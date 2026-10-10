import { __, sprintf } from '@wordpress/i18n';
import layoutMap from '../../includes/layout-map.json';
import type { GalleryRow } from './types';

/**
 * What the site embed draws for each layout a gallery can have on Profotograaf.
 * The table is includes/layout-map.json, the file Layout_Map reads in PHP, so
 * the editor hint and the front end render cannot disagree.
 */
const PLATFORM_TO_DRAWN: Record< string, string | undefined > = layoutMap.map;
const DRAWN: string[] = layoutMap.drawn;

export interface DrawnLayout {
	/** grid, masonry or slideshow. */
	layout: string;
	/** False when the plugin does not know the platform layout and used the fallback. */
	known: boolean;
}

/**
 * The layout the site draws for a gallery on the platform default. A layout the
 * platform reports as drawn (`embed_layout`) wins when it is one embed.js draws.
 */
export function drawnLayout( platform: string, reported = '' ): DrawnLayout {
	const wanted = reported.trim().toLowerCase();
	if ( DRAWN.includes( wanted ) ) {
		return { layout: wanted, known: true };
	}
	const mapped = PLATFORM_TO_DRAWN[ platform.trim().toLowerCase() ];
	return mapped
		? { layout: mapped, known: true }
		: { layout: layoutMap.fallback, known: false };
}

function titleCase( value: string ): string {
	const text = value.replace( /[-_]+/g, ' ' ).trim();
	return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
}

/** The names of the layouts a gallery can have on Profotograaf, by value. */
function layoutNames(): Record< string, string > {
	return {
		grid: __( 'Grid', 'profotograaf' ),
		masonry: __( 'Masonry', 'profotograaf' ),
		slideshow: __( 'Slideshow', 'profotograaf' ),
		justified: __( 'Justified', 'profotograaf' ),
		mosaic: __( 'Mosaic', 'profotograaf' ),
		lighttable: __( 'Light table', 'profotograaf' ),
		parallax: __( 'Parallax', 'profotograaf' ),
		direct: __( 'Direct', 'profotograaf' ),
		cinema: __( 'Cinema', 'profotograaf' ),
		filmstrip: __( 'Filmstrip', 'profotograaf' ),
		slideout: __( 'Slideout', 'profotograaf' ),
		flickr: __( 'Flickr', 'profotograaf' ),
		instagram: __( 'Instagram', 'profotograaf' ),
		duo: __( 'Duo', 'profotograaf' ),
	};
}

/** The name of a layout as the photographer knows it. */
export function layoutName( layout: string ): string {
	return layoutNames()[ layout.toLowerCase() ] ?? titleCase( layout );
}

/**
 * The hint under the Layout control for a block on the platform default, or ''
 * when the gallery's layout is not known (not listed yet, or the platform sends
 * none).
 */
export function layoutHint(
	row: Pick< GalleryRow, 'layout' | 'embed_layout' > | null | undefined
): string {
	const platform = row?.layout ?? '';
	const reported = row?.embed_layout ?? '';
	if ( ! platform && ! DRAWN.includes( reported ) ) {
		return '';
	}
	const drawn = drawnLayout( platform, reported );
	const shown = layoutName( drawn.layout );
	if ( ! platform ) {
		return sprintf(
			/* translators: %s: the layout drawn on the site, such as Slideshow. */
			__( 'Shown as %s on your site.', 'profotograaf' ),
			shown
		);
	}
	if ( ! drawn.known ) {
		return sprintf(
			/* translators: 1: layout name on Profotograaf, such as Parallax. 2: layout drawn on the site, such as Grid. */
			__(
				'%1$s on Profotograaf. This plugin does not know that layout, so it is shown as %2$s on your site.',
				'profotograaf'
			),
			layoutName( platform ),
			shown
		);
	}
	return sprintf(
		/* translators: 1: layout name on Profotograaf, such as Parallax. 2: layout drawn on the site, such as Slideshow. */
		__( '%1$s on Profotograaf, shown as %2$s on your site', 'profotograaf' ),
		layoutName( platform ),
		shown
	);
}
