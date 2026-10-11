import { __ } from '@wordpress/i18n';
import { cropStyle, withVirtualImage } from './image-edit';
import type { BlockEditProps } from './image-edit';
import type { ComponentType } from 'react';

type Filter = ( edit: ComponentType< BlockEditProps > ) => ComponentType< BlockEditProps >;

interface HooksGlobal {
	addFilter: ( hook: string, namespace: string, callback: Filter ) => void;
}

// The server loads this script in the block editor, for users who can upload
// files, while photos can be kept on Profotograaf. An Image block that shows
// such a photo loses its Crop button, because the REST image editor has no
// file to work on, and explains that in the block settings.
const globalScope = window as unknown as { wp?: { hooks?: HooksGlobal } };
const hooks = globalScope.wp?.hooks;
if ( hooks ) {
	hooks.addFilter( 'editor.BlockEdit', 'profotograaf/virtual-image', withVirtualImage );
	const style = document.createElement( 'style' );
	style.id = 'profotograaf-virtual-image-style';
	// The core label, so the domain is WordPress' own.
	style.textContent = cropStyle( __( 'Crop' ) );
	document.head.append( style );
}
