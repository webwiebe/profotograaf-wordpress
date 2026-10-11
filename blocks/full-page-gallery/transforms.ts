import { createBlock, type BlockBlockTransform } from '@wordpress/blocks';
import gallery from '../gallery/block.json';
import metadata from './block.json';

/**
 * The attributes the gallery block and the full-page gallery share, by name
 * in both block.json files: the gallery, the excluded photos, the display
 * options both have and the captions and alt text of single photos.
 */
const CARRIED = [
	'galleryId',
	'galleryTitle',
	'galleryUrl',
	'excludedPhotoIds',
	'captions',
	'lightbox',
	'linkTo',
	'linkNewTab',
	'imageText',
] as const;

type Attributes = Record< string, unknown >;

/**
 * The shared attributes that hold a value. Empty ones stay out, so the new
 * block keeps its own defaults and stores nothing it did not have.
 */
export function carried( source: Attributes | Attributes[] ): Attributes {
	const from = Array.isArray( source ) ? source[ 0 ] ?? {} : source;
	const out: Attributes = {};
	for ( const key of CARRIED ) {
		const value = from[ key ];
		const empty = value === undefined || value === '' || ( Array.isArray( value ) && value.length === 0 );
		if ( ! empty ) {
			out[ key ] = value;
		}
	}
	return out;
}

/** Turns the gallery block into a full-page gallery and back. */
export const transforms: { from: BlockBlockTransform[]; to: BlockBlockTransform[] } = {
	from: [
		{
			type: 'block',
			blocks: [ gallery.name ],
			transform: ( attributes ) => createBlock( metadata.name, carried( attributes ) ),
		},
	],
	to: [
		{
			type: 'block',
			blocks: [ gallery.name ],
			transform: ( attributes ) => createBlock( gallery.name, carried( attributes ) ),
		},
	],
};
