import apiFetch from '@wordpress/api-fetch';
import { dispatch, select, subscribe } from '@wordpress/data';
import { createRefresher, mediaPath, parseUrls } from './refresh';
import type { Change, EditorBlock } from './refresh';

interface BlockEditorSelectors {
	getBlocks: () => EditorBlock[];
}

interface BlockEditorActions {
	updateBlockAttributes: ( clientId: string, attributes: Change[ 'attributes' ] ) => void;
	__unstableMarkNextChangeAsNotPersistent?: () => void;
}

// The server loads this script in the block editor and hands it the platform
// URL. It only acts on Image blocks whose URL is on that host.
const settings = ( window as unknown as { profotograafVirtualUrls?: { platformUrl?: unknown } } ).profotograafVirtualUrls;
const platformUrl = typeof settings?.platformUrl === 'string' ? settings.platformUrl : '';

if ( '' !== platformUrl ) {
	const listener = createRefresher( {
		platformUrl,
		getBlocks: () => ( select( 'core/block-editor' ) as unknown as BlockEditorSelectors ).getBlocks(),
		update: ( change ) => {
			const actions = dispatch( 'core/block-editor' ) as BlockEditorActions;
			// The refresh is no edit of the author's, so it adds no undo step.
			actions.__unstableMarkNextChangeAsNotPersistent?.();
			actions.updateBlockAttributes( change.clientId, change.attributes );
		},
		fetchUrls: async ( ids ) => parseUrls( ids, await apiFetch( { path: mediaPath( ids ) } ) ),
	} );
	subscribe( listener, 'core/block-editor' );
}
