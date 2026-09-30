import { registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration, BlockEditProps } from '@wordpress/blocks';
import type { ComponentType } from 'react';

interface ServerRenderedBlock< T extends Record< string, unknown > > {
	edit: ComponentType< BlockEditProps< T > >;
}

/**
 * Registers a block whose title, attributes and category come from block.json
 * (WordPress merges the server registration into the client one by name).
 * Both blocks render on the server, so save() is always null.
 *
 * The typings of registerBlockType demand the whole configuration next to a
 * name, which block.json already supplies at runtime. The cast is the one place
 * that gap is bridged.
 */
export function registerServerRenderedBlock<
	T extends Record< string, unknown >,
>( name: string, block: ServerRenderedBlock< T > ): void {
	registerBlockType< T >( name, {
		edit: block.edit,
		save: () => null,
	} as unknown as BlockConfiguration< T > );
}
