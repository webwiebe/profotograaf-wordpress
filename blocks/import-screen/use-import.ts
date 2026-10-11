import { _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { runImport } from './import-job';
import type { AddMode, ImportFailure, PhotoItem } from './types';

export interface Progress {
	done: number;
	total: number;
	mode: AddMode;
}

export interface ImportState {
	selected: Record< string, PhotoItem >;
	imported: Record< string, number >;
	progress: Progress | null;
	failures: ImportFailure[];
	/** How the photos in `failures` were being added. */
	failedMode: AddMode;
	summary: string;
	toggle: ( photo: PhotoItem ) => void;
	select: ( photos: PhotoItem[] ) => void;
	clear: () => void;
	start: ( mode?: AddMode ) => Promise< void >;
	/** Attachment id of a photo imported earlier or in this session, 0 when none. */
	attachmentOf: ( photo: PhotoItem ) => number;
	/** Whether the photo's attachment is kept on Profotograaf. */
	keptOf: ( photo: PhotoItem ) => boolean;
}

function without(
	record: Record< string, PhotoItem >,
	ids: string[]
): Record< string, PhotoItem > {
	const next = { ...record };
	for ( const id of ids ) {
		delete next[ id ];
	}
	return next;
}

function summaryOf( mode: AddMode, count: number ): string {
	return mode === 'keep'
		? sprintf(
				/* translators: %d: number of photos added to the Media Library without a download. */
				_n( '%d photo kept on Profotograaf.', '%d photos kept on Profotograaf.', count, 'profotograaf' ),
				count
		  )
		: sprintf(
				/* translators: %d: number of photos added to the Media Library. */
				_n( '%d photo imported.', '%d photos imported.', count, 'profotograaf' ),
				count
		  );
}

/** The selection across pages and the import of it. */
export function useImport(): ImportState {
	const [ selected, setSelected ] = useState< Record< string, PhotoItem > >( {} );
	const [ imported, setImported ] = useState< Record< string, number > >( {} );
	const [ kept, setKept ] = useState< Record< string, boolean > >( {} );
	const [ progress, setProgress ] = useState< Progress | null >( null );
	const [ failures, setFailures ] = useState< ImportFailure[] >( [] );
	const [ failedMode, setFailedMode ] = useState< AddMode >( 'import' );
	const [ summary, setSummary ] = useState( '' );

	const toggle = ( photo: PhotoItem ) =>
		setSelected( ( current ) =>
			current[ photo.sourceId ]
				? without( current, [ photo.sourceId ] )
				: { ...current, [ photo.sourceId ]: photo }
		);

	const select = ( photos: PhotoItem[] ) =>
		setSelected( ( current ) => ( {
			...current,
			...Object.fromEntries( photos.map( ( photo ) => [ photo.sourceId, photo ] ) ),
		} ) );

	const start = async ( mode: AddMode = 'import' ) => {
		const queue = Object.values( selected );
		setFailures( [] );
		setSummary( '' );
		setProgress( { done: 0, total: queue.length, mode } );
		const { added, failed } = await runImport(
			queue,
			( done ) => setProgress( { done, total: queue.length, mode } ),
			mode
		);
		const ids = Object.keys( added );
		setImported( ( current ) => ( { ...current, ...added } ) );
		setKept( ( current ) => ( {
			...current,
			...Object.fromEntries( ids.map( ( id ) => [ id, mode === 'keep' ] ) ),
		} ) );
		setSelected( ( current ) => without( current, ids ) );
		setFailures( failed );
		setFailedMode( mode );
		setSummary( summaryOf( mode, ids.length ) );
		setProgress( null );
	};

	return {
		selected,
		imported,
		progress,
		failures,
		failedMode,
		summary,
		toggle,
		select,
		clear: () => setSelected( {} ),
		start,
		attachmentOf: ( photo ) => imported[ photo.sourceId ] ?? photo.id ?? 0,
		keptOf: ( photo ) => kept[ photo.sourceId ] ?? photo.kept === true,
	};
}
