import { __ } from '@wordpress/i18n';
import type { CSSProperties } from 'react';
import type { ImageText } from './types';
import {
	cssRatio,
	displaySummary,
	type DisplayAttributes,
} from './display-options';

const TILES = 6;

/**
 * A sketch of the gallery in the editor. It follows columns, gap, shape and
 * captions the way embed.js will draw them, with the cover as every tile.
 */
export function PreviewGrid( {
	attributes,
	cover,
	imageText = [],
}: {
	attributes: DisplayAttributes;
	cover?: string | undefined;
	imageText?: ImageText[];
} ) {
	const columns = Number( attributes.columns ) || 3;
	const gap = attributes.gap === '' ? 8 : Number( attributes.gap );
	const style = {
		'--profotograaf-columns': columns,
		'--profotograaf-gap': `${ gap }px`,
		'--profotograaf-ratio': cssRatio( attributes.ratio ),
	} as CSSProperties;
	const summary = displaySummary( attributes );
	// The platform photo ids are not known in the editor, so the first entries
	// stand in for the first tiles.
	const showCaption =
		attributes.captions === 'below' || attributes.captions === 'overlay';

	return (
		<>
			<div className="profotograaf-gallery-grid" style={ style }>
				{ Array.from( { length: TILES }, ( _unused, index ) => (
					<figure
						key={ index }
						className="profotograaf-gallery-grid__tile"
						data-captions={ attributes.captions || undefined }
					>
						{ cover ? (
							<img src={ cover } alt={ imageText[ index ]?.alt ?? '' } />
						) : (
							<span className="profotograaf-gallery-grid__blank" />
						) }
						{ showCaption && (
							<figcaption>
								{ imageText[ index ]?.caption || __( 'Caption', 'profotograaf' ) }
							</figcaption>
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
