import {
	presetPattern,
	sectionDefaults,
	sectionFields,
	sectionTake,
	type PresetId,
	type Section,
	type SectionType,
} from './sections';
import type { FieldName } from './labels';

type FieldValue = Section[ FieldName ];

/**
 * The section with one field set, or removed when the value is undefined so
 * the default from includes/sections.json applies.
 */
export function withField( section: Section, field: FieldName, value: FieldValue ): Section {
	if ( value !== undefined ) {
		return { ...section, [ field ]: value };
	}
	const next: Section = { ...section };
	delete next[ field ];
	return next;
}

/**
 * The section as another type. Fields the new type also has are kept, the
 * picked photos are cut to the number the new type takes.
 */
export function withType( section: Section, type: SectionType ): Section {
	let next: Section = { type };
	for ( const field of sectionFields( type ) ) {
		next = withField( next, field, section[ field ] );
	}
	const ids = ( section.photoIds ?? [] ).slice( 0, sectionTake( next ) );
	if ( ids.length > 0 ) {
		next.photoIds = ids;
	}
	return next;
}

/** The list with one section moved by one place, up (-1) or down (1). */
export function moved( sections: Section[], index: number, step: -1 | 1 ): Section[] {
	const target = index + step;
	const moving = sections[ index ];
	const other = sections[ target ];
	if ( moving === undefined || other === undefined ) {
		return sections;
	}
	const next = [ ...sections ];
	next[ target ] = moving;
	next[ index ] = other;
	return next;
}

/** The list without one section. */
export function removed( sections: Section[], index: number ): Section[] {
	return sections.filter( ( _section, at ) => at !== index );
}

/** The list with one section replaced. */
export function replaced( sections: Section[], index: number, section: Section ): Section[] {
	return sections.map( ( current, at ) => ( at === index ? section : current ) );
}

/**
 * The sections a Custom list starts from: one cycle of a preset, each section
 * holding only the values that differ from its type default, so nothing
 * stores a default.
 */
export function seedSections( preset: PresetId ): Section[] {
	return presetPattern( preset ).map( ( filled ) => {
		const defaults = sectionDefaults( filled.type ) as Record< string, unknown >;
		let section: Section = { type: filled.type };
		for ( const field of sectionFields( filled.type ) ) {
			if ( filled[ field ] !== defaults[ field ] ) {
				section = withField( section, field, filled[ field ] );
			}
		}
		return section;
	} );
}
