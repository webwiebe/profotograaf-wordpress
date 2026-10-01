import { __ } from '@wordpress/i18n';
import type { CSSProperties } from 'react';
import {
	cssRatio,
	displaySummary,
	type DisplayAttributes,
} from './display-options';
import { excludedCount, excludedLabel, visiblePhotos } from './exclude';
import type { PhotoRow } from './types';

const TILES = 6;
const MAX_PHOTO_TILES = 12;

interface Tile {
	key: string;
	src: string | undefined;
}

/**
 * The tiles to draw. With the photo list, the first photos that are not left
 * out. Without it (loading, or the platform has no list), the cover on every
 * tile.
 */
function tilesFor(
	photos: PhotoRow[] | null | undefined,
	excluded: string[],
	cover: string | undefined
): Tile[] {
	if ( photos && photos.length > 0 ) {
		return visiblePhotos( photos, excluded )
			.slice( 0, MAX_PHOTO_TILES )
			.map( ( photo ) => ( { key: photo.id, src: photo.thumb_url || undefined } ) );
	}
	return Array.from( { length: TILES }, ( _unused, index ) => ( {
		key: String( index ),
		src: cover,
	} ) );
}

/**
 * A sketch of the gallery in the editor. It follows columns, gap, shape and
 * captions the way embed.js will draw them. Photos left out do not appear.
 */
export function PreviewGrid( {
	attributes,
	cover,
	photos,
	excluded = [],
}: {
	attributes: DisplayAttributes;
	cover?: string | undefined;
	photos?: PhotoRow[] | null | undefined;
	excluded?: string[];
} ) {
	const columns = Number( attributes.columns ) || 3;
	const gap = attributes.gap === '' ? 8 : Number( attributes.gap );
	const style = {
		'--profotograaf-columns': columns,
		'--profotograaf-gap': `${ gap }px`,
		'--profotograaf-ratio': cssRatio( attributes.ratio ),
	} as CSSProperties;
	const summary = displaySummary( attributes );
	const left = photos ? excludedCount( photos, excluded ) : 0;
	if ( left > 0 ) {
		summary.push( excludedLabel( left ) );
	}
	const showCaption =
		attributes.captions === 'below' || attributes.captions === 'overlay';

	return (
		<>
			<div className="profotograaf-gallery-grid" style={ style }>
				{ tilesFor( photos, excluded, cover ).map( ( tile ) => (
					<figure
						key={ tile.key }
						className="profotograaf-gallery-grid__tile"
						data-captions={ attributes.captions || undefined }
					>
						{ tile.src ? (
							<img src={ tile.src } alt="" />
						) : (
							<span className="profotograaf-gallery-grid__blank" />
						) }
						{ showCaption && (
							<figcaption>{ __( 'Caption', 'profotograaf' ) }</figcaption>
						) }
					</figure>
				) ) }
			</div>
			{ summary.length > 0 && (
				<p className="profotograaf-gallery-preview__line">
					{ summary.join( ', ' ) }
				</p>
			) }
		</>
	);
}
