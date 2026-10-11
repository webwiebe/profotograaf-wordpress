import table from '../../includes/sections.json';

/**
 * The shared vocabulary of the full-page gallery (#194): section types, field
 * defaults and presets. The table is includes/sections.json, the file
 * Sections_Schema reads in PHP, so the editor and the front end render cannot
 * disagree. A block stores '' for every field the photographer left alone and
 * the default comes from the table.
 */

export type SectionType = keyof typeof table.types;
export type PresetId = keyof typeof table.presets | 'custom';

export interface Section {
	type: SectionType;
	columns?: number;
	rows?: number;
	count?: number;
	ratio?: string;
	gap?: number;
	/** Gap per axis in pixels; '' uses `gap`. */
	gapRow?: number | '';
	gapColumn?: number | '';
	captions?: string;
	height?: number;
	rowHeight?: number;
	side?: string;
	mirror?: boolean;
	title?: string;
	heading?: string;
	text?: string;
	align?: string;
	radius?: string;
	hover?: string;
	hoverStrength?: string;
	frameColor?: string;
	frameWidth?: number;
	shadow?: string;
	/** Photos picked for this section, in slot order. */
	photoIds?: string[];
}

type Fields = Omit< Section, 'type' | 'photoIds' >;

interface FieldRule {
	default: unknown;
	min?: number;
	max?: number;
	enum?: string[];
	/** A regular expression a text value must match, such as the frame colour. */
	pattern?: string;
}

const TYPES = table.types as Record<
	SectionType,
	{
		take: number | string;
		fields: Fields;
		limits?: Partial< Record< keyof Fields, { min?: number; max?: number } > >;
		phone: Fields;
	}
>;
const FIELDS = table.fields as Record< keyof Fields, FieldRule >;

export const SECTION_TYPES = Object.keys( TYPES ) as SectionType[];
export const PRESETS = Object.keys( table.presets ) as Array< keyof typeof table.presets >;
export const DEFAULT_PRESET = table.defaultPreset as PresetId;
export const PHONE_BREAKPOINT = table.phoneBreakpoint;

/** Whether a value is a known section type. */
export function isSectionType( value: unknown ): value is SectionType {
	return typeof value === 'string' && value in TYPES;
}

/** The default values of one section type. */
export function sectionDefaults( type: SectionType ): Fields {
	return { ...TYPES[ type ].fields };
}

/** The fields one section type has, in table order. */
export function sectionFields( type: SectionType ): Array< keyof Fields > {
	return Object.keys( TYPES[ type ].fields ) as Array< keyof Fields >;
}

/** The allowed values or range of one field of a type. */
export function fieldRule( type: SectionType, field: keyof Fields ): FieldRule {
	return { ...FIELDS[ field ], ...TYPES[ type ].limits?.[ field ] };
}

/** A section with every field of its type filled from the defaults. */
export function withDefaults( section: Section ): Section {
	const filled: Section = { type: section.type, ...sectionDefaults( section.type ) };
	for ( const field of sectionFields( section.type ) ) {
		const value = section[ field ];
		if ( value !== undefined && value !== '' ) {
			( filled as unknown as Record< string, unknown > )[ field ] = value;
		}
	}
	filled.photoIds = section.photoIds ?? [];
	return filled;
}

/** How many photos a section takes from the gallery. */
export function sectionTake( section: Section ): number {
	const rule = TYPES[ section.type ].take;
	if ( typeof rule === 'number' ) {
		return rule;
	}
	const filled = withDefaults( section ) as unknown as Record< string, number >;
	return rule.split( '*' ).reduce( ( total, field ) => total * ( Number( filled[ field ] ) || 0 ), 1 );
}

/** The sections of one preset cycle, with defaults filled. */
export function presetPattern( preset: PresetId ): Section[] {
	if ( preset === 'custom' ) {
		return [];
	}
	return ( table.presets[ preset ] as Section[] ).map( withDefaults );
}
