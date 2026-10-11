import { registerBlockType, type BlockConfiguration } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import { transforms } from './transforms';
import './editor.css';
import type { FullPageAttributes } from './types';

// Rendered on the server (Full_Page_Renderer), so there is nothing to save.
// The title, attributes and supports come from block.json, which WordPress
// merges in by name; the cast bridges that gap in the typings, as in
// blocks/register.ts, which takes no transforms.
registerBlockType< FullPageAttributes >( metadata.name, {
	edit: Edit,
	save: () => null,
	transforms,
} as unknown as BlockConfiguration< FullPageAttributes > );
