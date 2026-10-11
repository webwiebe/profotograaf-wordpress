import { __, sprintf } from '@wordpress/i18n';
import {
	gapOptions,
	hoverOptions,
	radiusOptions,
	shadowOptions,
	strengthOptions,
} from '../gallery/look-options';
import { defaultLabel } from './labels';
import { fieldRule, type Section } from './sections';

/**
 * The spacing and shape fields of a section (#194), with the choices and
 * labels #198 gave the gallery block (blocks/gallery/look-options.ts). A
 * section stores '' or nothing for a field the photographer left alone; the
 * server resolves it through Sections_Schema (section, plugin setting,
 * includes/sections.json) and Full_Page_Look writes it.
 */

/** The look fields, in the order the editor shows them. */
export const LOOK_FIELDS = [
	'gap',
	'gapRow',
	'gapColumn',
	'radius',
	'hover',
	'hoverStrength',
	'frameWidth',
	'frameColor',
	'shadow',
] as const;

export type LookField = ( typeof LOOK_FIELDS )[ number ];

interface ChoiceOption {
	label: string;
	value: string;
}

/** Whether a field is a spacing or shape field. */
export function isLookField( field: string ): field is LookField {
	return ( LOOK_FIELDS as readonly string[] ).includes( field );
}

/** The value a section draws for a field: its own, else the default. */
export function drawnValue( section: Section, field: keyof Section ): unknown {
	const value = section[ field ];
	return value === undefined || value === '' ? fieldRule( section.type, field as never ).default : value;
}

/** Whether a section crops every photo square, the only shape drawn as a circle. */
export function isSquareSection( section: Section ): boolean {
	return section.type === 'grid' && drawnValue( section, 'ratio' ) === '1-1';
}

/**
 * The choices of a look field that has a list, with the default first,
 * labelled after the value it stands for. The gallery block's lists start
 * with "Site default"; that entry is replaced.
 */
export function lookChoices( section: Section, field: 'radius' | 'hover' | 'hoverStrength' | 'shadow' ): ChoiceOption[] {
	const rule = fieldRule( section.type, field );
	const current = section[ field ] ?? '';
	const lists: Record< typeof field, () => ChoiceOption[] > = {
		radius: () => radiusOptions( isSquareSection( section ), current ),
		hover: () => hoverOptions( ! ( rule.enum ?? [] ).includes( 'grow' ) ),
		hoverStrength: strengthOptions,
		shadow: shadowOptions,
	};
	const own = lists[ field ]().filter( ( option ) => option.value !== '' );
	const fallback = own.find( ( option ) => option.value === rule.default )?.label ?? String( rule.default );
	return [ { label: defaultLabel( fallback ), value: '' }, ...own ];
}

/**
 * The gap choices: the default (the type default in pixels, or the section
 * gap for a gap per axis), the presets of the gallery block and Custom.
 */
export function gapChoices( section: Section, field: 'gap' | 'gapRow' | 'gapColumn' ): ChoiceOption[] {
	const fallback = fieldRule( section.type, field ).default;
	const label =
		typeof fallback === 'number'
			? defaultLabel(
					sprintf(
						/* translators: %d: a number of pixels. */
						__( '%d pixels', 'profotograaf' ),
						fallback
					)
			  )
			: __( 'Same as the gap between photos', 'profotograaf' );
	return [ { label, value: '' }, ...gapOptions().filter( ( option ) => option.value !== '' ) ];
}

type Style = Record< string, string >;

const RADIUS: Record< string, string > = { subtle: '4px', rounded: '12px', full: '50%' };

/** The custom properties of a section's look, as Full_Page_Look writes them. */
export function lookStyle( section: Section ): Style {
	const style: Style = {};
	if ( typeof section.gapRow === 'number' ) {
		style[ '--pf-gap-row' ] = `${ section.gapRow }px`;
	}
	if ( typeof section.gapColumn === 'number' ) {
		style[ '--pf-gap-col' ] = `${ section.gapColumn }px`;
	}
	const radius = section.radius === 'full' && ! isSquareSection( section ) ? '' : RADIUS[ section.radius ?? '' ];
	if ( radius ) {
		style[ '--pf-radius' ] = radius;
	}
	if ( ( section.frameWidth ?? 0 ) > 0 ) {
		style[ '--pf-frame' ] = `${ section.frameWidth }px`;
		style[ '--pf-frame-color' ] = section.frameColor || '#ffffff';
	}
	return style;
}

/** The data attributes of a section's look, as Full_Page_Look writes them. */
export function lookData( section: Section ): Record< string, string > {
	const data: Record< string, string > = {};
	if ( section.hover && section.hover !== 'none' ) {
		data[ 'data-hover' ] = section.hover;
		data[ 'data-hover-strength' ] = section.hoverStrength || 'subtle';
	}
	if ( section.shadow && section.shadow !== 'none' ) {
		data[ 'data-shadow' ] = section.shadow;
	}
	return data;
}
