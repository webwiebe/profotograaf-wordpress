import { __ } from '@wordpress/i18n';
import { AUTO, type SiteDefaults } from './display-options';

/**
 * The spacing and shape options of the block (#198): a gap per axis, corner
 * radius, hover effect, frame and shadow. Like the display options, every
 * value is a string and an empty string means "use the site default"; the
 * server resolves them through Gallery_Look and Option_Resolver. The gap
 * between photos itself is a display option (`gap`), shown in this panel.
 */
export type LookAttributes = {
	gapRow: string;
	gapColumn: string;
	radius: string;
	hover: string;
	hoverStrength: string;
	frameColor: string;
	frameWidth: string;
	shadow: string;
};

export const LOOK_DEFAULTS: LookAttributes = {
	gapRow: '',
	gapColumn: '',
	radius: '',
	hover: '',
	hoverStrength: '',
	frameColor: '',
	frameWidth: '',
	shadow: '',
};

interface ChoiceOption {
	label: string;
	value: string;
}

/** The gap presets in pixels, and the select value for any other number. */
const GAP_PRESETS = [ '0', '4', '12', '24' ];
export const GAP_CUSTOM = 'custom';

/** The preset select value for a stored gap: the preset, custom, or '' for the site default. */
export function gapPreset( gap: string ): string {
	if ( gap === '' || GAP_PRESETS.includes( gap ) ) {
		return gap;
	}
	return GAP_CUSTOM;
}

export function gapOptions(): ChoiceOption[] {
	return [
		siteDefault(),
		{ label: __( 'None (0 pixels)', 'profotograaf' ), value: '0' },
		{ label: __( 'Small (4 pixels)', 'profotograaf' ), value: '4' },
		{ label: __( 'Medium (12 pixels)', 'profotograaf' ), value: '12' },
		{ label: __( 'Large (24 pixels)', 'profotograaf' ), value: '24' },
		{ label: __( 'Custom', 'profotograaf' ), value: GAP_CUSTOM },
	];
}

function siteDefault(): ChoiceOption {
	return { label: __( 'Site default', 'profotograaf' ), value: '' };
}

/** The layout and photo shape a block draws, with `auto` read as not set. */
function drawn( attributes: { layout?: string; ratio?: string }, defaults: SiteDefaults ) {
	const own = ( value?: string ) => ( value === AUTO ? '' : value ?? '' );
	return {
		layout: own( attributes.layout ) || defaults.layout || 'grid',
		ratio: own( attributes.ratio ) || defaults.ratio,
	};
}

/**
 * Whether the block crops every photo square, the only shape embed.js draws a
 * circle for: a grid without a shape (its default crop is square) or the 1:1
 * shape in a grid or slideshow. Masonry keeps each photo's own shape.
 */
export function isSquare( attributes: { layout?: string; ratio?: string }, defaults: SiteDefaults ): boolean {
	const { layout, ratio } = drawn( attributes, defaults );
	if ( layout === 'masonry' ) {
		return false;
	}
	return ratio === '1-1' || ( layout === 'grid' && ratio === '' );
}

/**
 * The corner options. Circle is offered for square photos only; a block that
 * holds it while its photos are not square keeps it, marked, so the control
 * still shows the stored value.
 */
export function radiusOptions( square: boolean, current: string ): ChoiceOption[] {
	const options = [
		siteDefault(),
		{ label: __( 'Square corners', 'profotograaf' ), value: 'none' },
		{ label: __( 'Subtle (4 pixels)', 'profotograaf' ), value: 'subtle' },
		{ label: __( 'Rounded (12 pixels)', 'profotograaf' ), value: 'rounded' },
	];
	if ( square ) {
		options.push( { label: __( 'Circle', 'profotograaf' ), value: 'full' } );
	} else if ( current === 'full' ) {
		options.push( { label: __( 'Circle (square photos only, not drawn now)', 'profotograaf' ), value: 'full' } );
	}
	return options;
}

/** The hover effects. A slideshow does not grow. */
export function hoverOptions( slideshow: boolean ): ChoiceOption[] {
	const options = [ siteDefault(), { label: __( 'Zoom inside the frame', 'profotograaf' ), value: 'zoom' } ];
	if ( ! slideshow ) {
		options.push( { label: __( 'Grow above the neighbours', 'profotograaf' ), value: 'grow' } );
	}
	options.push( { label: __( 'None', 'profotograaf' ), value: 'none' } );
	return options;
}

export function strengthOptions(): ChoiceOption[] {
	return [
		siteDefault(),
		{ label: __( 'Subtle', 'profotograaf' ), value: 'subtle' },
		{ label: __( 'Strong', 'profotograaf' ), value: 'strong' },
	];
}

export function shadowOptions(): ChoiceOption[] {
	return [
		siteDefault(),
		{ label: __( 'None', 'profotograaf' ), value: 'none' },
		{ label: __( 'Soft', 'profotograaf' ), value: 'soft' },
		{ label: __( 'Strong', 'profotograaf' ), value: 'strong' },
	];
}

/** Which look controls apply to the layout a block draws. */
export function lookScope( attributes: { layout?: string; ratio?: string }, defaults: SiteDefaults ) {
	const slideshow = drawn( attributes, defaults ).layout === 'slideshow';
	return {
		// The slideshow ignores the gaps per axis, the frame and the shadow.
		slideshow,
		square: isSquare( attributes, defaults ),
	};
}

/** A frame colour as a 3 or 6 digit hex code, or '' for anything else. */
export function cleanColor( raw: string | undefined ): string {
	const value = ( raw ?? '' ).trim().toLowerCase();
	return /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/.test( value ) ? value : '';
}
