import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { fetchPhotos } from './fetch';
import type { InserterQuery, MediaItem } from './fetch';

interface InserterMediaCategory {
	name: string;
	labels: { name: string; search_items: string };
	mediaType: 'image';
	fetch: ( query: InserterQuery ) => Promise< MediaItem[] >;
}

interface BlockEditorActions {
	registerInserterMediaCategory?: ( category: InserterMediaCategory ) => void;
}

// The server loads this script only while the media source setting is on and
// the user can upload files, so registering is all it does.
try {
	const actions = dispatch( 'core/block-editor' ) as BlockEditorActions;
	actions.registerInserterMediaCategory?.( {
		name: 'profotograaf',
		labels: {
			name: __( 'Profotograaf', 'profotograaf' ),
			search_items: __( 'Search Profotograaf photos', 'profotograaf' ),
		},
		mediaType: 'image',
		fetch: fetchPhotos,
	} );
} catch ( error ) {
	// eslint-disable-next-line no-console
	console.warn( 'The Profotograaf photo category could not be registered.', error );
}
