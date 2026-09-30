import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './editor.css';

registerBlockType( metadata.name, {
	edit: Edit,
	// Rendered on the server (Gallery_Renderer), so there is nothing to save.
	save: () => null,
} );
