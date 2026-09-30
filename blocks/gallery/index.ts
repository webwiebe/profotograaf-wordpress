import metadata from './block.json';
import Edit from './edit';
import './editor.css';
import { registerServerRenderedBlock } from '../register';
import type { GalleryAttributes } from './types';

// Rendered on the server (Gallery_Renderer), so there is nothing to save.
registerServerRenderedBlock< GalleryAttributes >( metadata.name, {
	edit: Edit,
} );
