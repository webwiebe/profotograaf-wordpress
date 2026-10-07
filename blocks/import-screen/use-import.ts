import { _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { runImport } from './import-job';
import type { ImportFailure, PhotoItem } from './types';

export interface Progress {
	done: number;
	total: number;
}

export interface ImportState {
	selected: Record< string, PhotoItem >;
	imported: Record< string, number >;
	progress: Progress | null;
	failures: ImportFailure[];
	summary: string;
	toggle: ( photo: PhotoItem ) => void;
	select: ( photos: PhotoItem[] ) => void;
	clear: () => void;
	start: () => Promise< void >;
	/** Attachment id of a photo imported earlier or in this session, 0 when none. */
	attachmentOf: ( photo: PhotoItem ) => number;
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

/** The selection across pages and the import of it. */
export function useImport(): ImportState {
	const [ selected, setSelected ] = useState< Record< string, PhotoItem > >( {} );
	const [ imported, setImported ] = useState< Record< string, number > >( {} );
	const [ progress, setProgress ] = useState< Progress | null >( null );
	const [ failures, setFailures ] = useState< ImportFailure[] >( [] );
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

	const start = async () => {
		const queue = Object.values( selected );
		setFailures( [] );
		setSummary( '' );
		setProgress( { done: 0, total: queue.length } );
		const { added, failed } = await runImport( queue, ( done ) =>
			setProgress( { done, total: queue.length } )
		);
		const count = Object.keys( added ).length;
		setImported( ( current ) => ( { ...current, ...added } ) );
		setSelected( ( current ) => without( current, Object.keys( added ) ) );
		setFailures( failed );
		setSummary(
			sprintf(
				/* translators: %d: number of photos added to the Media Library. */
				_n( '%d photo imported.', '%d photos imported.', count, 'profotograaf' ),
				count
			)
		);
		setProgress( null );
	};

	return {
		selected,
		imported,
		progress,
		failures,
		summary,
		toggle,
		select,
		clear: () => setSelected( {} ),
		start,
		attachmentOf: ( photo ) => imported[ photo.sourceId ] ?? photo.id ?? 0,
	};
}
