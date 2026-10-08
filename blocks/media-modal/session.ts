import { __ } from '@wordpress/i18n';
import { runImport } from '../import-screen/import-job';
import type { ImportOutcome } from '../import-screen/import-job';
import type { ImportFailure, PhotoItem } from '../import-screen/types';
import { PhotoLibrary } from './library';
import type { ReadyPhoto, SelectionPort } from './types';

export interface Progress {
	done: number;
	total: number;
}

type Importer = ( queue: PhotoItem[], onProgress: ( done: number ) => void ) => Promise< ImportOutcome >;

/**
 * What one media frame knows about the Profotograaf tab: the photo list, the
 * photos the user picked and the import that runs when the user confirms.
 *
 * The frame's own selection only ever holds stand-ins for picked photos. When
 * the user confirms, the stand-ins are swapped for real attachments, so the
 * caller of the frame receives normal attachments.
 */
export class Session {
	readonly library: PhotoLibrary;

	progress: Progress | null = null;

	failures: ImportFailure[] = [];

	private readonly picked = new Map< string, PhotoItem >();

	private readonly listeners = new Set< () => void >();

	constructor(
		private readonly port: SelectionPort,
		library: PhotoLibrary = new PhotoLibrary(),
		private readonly importer: Importer = runImport
	) {
		this.library = library;
	}

	subscribe( listener: () => void ): () => void {
		this.listeners.add( listener );
		return () => this.listeners.delete( listener );
	}

	busy(): boolean {
		return this.progress !== null;
	}

	has( photo: PhotoItem ): boolean {
		return this.picked.has( photo.sourceId );
	}

	pending(): PhotoItem[] {
		return [ ...this.picked.values() ];
	}

	/** Picks or drops a photo. A frame that takes one item keeps the latest pick. */
	toggle( photo: PhotoItem ): void {
		if ( this.busy() ) {
			return;
		}
		if ( this.picked.has( photo.sourceId ) ) {
			this.picked.delete( photo.sourceId );
		} else {
			if ( ! this.port.multiple() ) {
				this.picked.clear();
			}
			this.picked.set( photo.sourceId, photo );
		}
		this.failures = [];
		this.port.show( this.pending() );
		this.emit();
	}

	/** Puts the picks back into the frame's selection when the tab shows again. */
	reveal(): void {
		this.port.show( this.pending() );
	}

	/** Takes the picks out of the frame's selection when another tab shows. */
	conceal(): void {
		this.port.hide();
	}

	/** Forgets the picks, for a frame that closed without confirming. */
	reset(): void {
		this.picked.clear();
		this.failures = [];
		this.port.hide();
		this.emit();
	}

	/**
	 * Imports the picked photos and swaps them for attachments. Resolves true
	 * when the frame may continue with its normal action: nothing pending or
	 * everything now a real attachment. Failed photos stay picked, with the
	 * reason in `failures`.
	 */
	async confirm(): Promise< boolean > {
		const queue = this.pending();
		if ( 0 === queue.length ) {
			return true;
		}
		this.failures = [];
		this.progress = { done: 0, total: queue.length };
		this.emit();
		try {
			this.failures = await this.run( queue );
		} catch {
			this.failures = queue.map( ( photo ) => failure( photo, __( 'Something went wrong. Try again.', 'profotograaf' ) ) );
		}
		this.progress = null;
		this.emit();
		return 0 === this.failures.length;
	}

	private async run( queue: PhotoItem[] ): Promise< ImportFailure[] > {
		const known = queue.filter( ( photo ) => ( photo.id ?? 0 ) > 0 );
		const fresh = queue.filter( ( photo ) => ! ( ( photo.id ?? 0 ) > 0 ) );
		const outcome: ImportOutcome =
			fresh.length > 0
				? await this.importer( fresh, ( done ) => this.advance( known.length + done, queue.length ) )
				: { added: {}, failed: [] };

		const ready: ReadyPhoto[] = [
			...known.map( ( photo ) => ( { photo, attachmentId: photo.id ?? 0 } ) ),
			...fresh
				.filter( ( photo ) => ( outcome.added[ photo.sourceId ] ?? 0 ) > 0 )
				.map( ( photo ) => ( { photo, attachmentId: outcome.added[ photo.sourceId ] ?? 0 } ) ),
		];
		const unloaded = new Set( await this.port.commit( ready ) );
		for ( const { photo } of ready ) {
			if ( ! unloaded.has( photo.sourceId ) ) {
				this.picked.delete( photo.sourceId );
			}
		}
		const titles = new Map( queue.map( ( photo ) => [ photo.sourceId, photo ] ) );
		const lost = [ ...unloaded ].map( ( id ) =>
			failure( titles.get( id ), __( 'The photo was imported but could not be loaded. Try again.', 'profotograaf' ), id )
		);
		return [ ...outcome.failed, ...lost ];
	}

	private advance( done: number, total: number ): void {
		this.progress = { done, total };
		this.emit();
	}

	private emit(): void {
		this.listeners.forEach( ( listener ) => listener() );
	}
}

function failure( photo: PhotoItem | undefined, message: string, id = '' ): ImportFailure {
	return {
		id: photo?.sourceId ?? id,
		title: photo?.title ?? id,
		message,
	};
}
