import { __ } from '@wordpress/i18n';
import type { CSSProperties } from 'react';
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
}: {
	attributes: DisplayAttributes;
	cover?: string | undefined;
} ) {
	const columns = Number( attributes.columns ) || 3;
	const gap = attributes.gap === '' ? 8 : Number( attributes.gap );
	const style = {
		'--profotograaf-columns': columns,
		'--profotograaf-gap': `${ gap }px`,
		'--profotograaf-ratio': cssRatio( attributes.ratio ),
	} as CSSProperties;
	const summary = displaySummary( attributes );
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
							<img src={ cover } alt="" />
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
