import { sectionTake, type Section, type SectionType } from './sections';
import type { FullPageAttributes, PreviewPhoto, SectionsAnswer, SlotPick } from './types';

/** The section types whose photos the photographer can pick (#194). */
const PICKABLE: SectionType[] = [ 'hero', 'feature', 'pair' ];

/** One slot the slot picker shows. */
export interface Slot {
	/** `<cycle>.<section index>.<position>`. */
	id: string;
	index: number;
	position: number;
	/** The photo the preview places in the slot. */
	photo: PreviewPhoto | undefined;
	/** The photo picked for the slot, or ''. */
	picked: string;
}

/** The pickable slots of one section of the preview. */
export interface SlotGroup {
	key: string;
	type: SectionType;
	/** The place of the section among the pickable sections of its type, from 1. */
	number: number;
	slots: Slot[];
}

/** A slot id split into its numbers, or null for a slot of the tail (''). */
export function parseSlot( slot: string ): { cycle: number; index: number; position: number } | null {
	const match = /^(\d+)\.(\d+)\.(\d+)$/.exec( slot );
	if ( ! match ) {
		return null;
	}
	return { cycle: Number( match[ 1 ] ), index: Number( match[ 2 ] ), position: Number( match[ 3 ] ) };
}

/** Whether the block uses its own list of sections. */
export function isCustom( attributes: Pick< FullPageAttributes, 'preset' > ): boolean {
	return attributes.preset === 'custom';
}

/** The photo picked for one slot, or ''. */
function pickedFor( attributes: FullPageAttributes, slot: string, index: number, position: number ): string {
	if ( isCustom( attributes ) ) {
		return attributes.sections[ index ]?.photoIds?.[ position ] ?? '';
	}
	return attributes.picks.find( ( pick ) => pick.slot === slot )?.photoId ?? '';
}

/**
 * Whether a slot of the answer can hold a pick. A preset takes a pick for any
 * slot. A Custom list keeps its picks on the sections, which only apply to the
 * first cycle, and only to a section the block still has in that place.
 */
function pickable( attributes: FullPageAttributes, section: SectionsAnswer[ 'sections' ][ number ], slot: string ): boolean {
	const parsed = parseSlot( slot );
	if ( parsed === null ) {
		return false;
	}
	if ( ! isCustom( attributes ) ) {
		return true;
	}
	return parsed.cycle === 0 && attributes.sections[ parsed.index ]?.type === section.type;
}

/** The pickable slots of the preview, grouped by section: hero, feature and pair. */
export function slotGroups( attributes: FullPageAttributes, answer: SectionsAnswer ): SlotGroup[] {
	const groups: SlotGroup[] = [];
	const seen: Partial< Record< SectionType, number > > = {};
	answer.sections.forEach( ( section, at ) => {
		if ( ! PICKABLE.includes( section.type ) ) {
			return;
		}
		const slots: Slot[] = [];
		section.slots.forEach( ( id, position ) => {
			const parsed = parseSlot( id );
			if ( parsed !== null && pickable( attributes, section, id ) ) {
				const picked = pickedFor( attributes, id, parsed.index, parsed.position );
				slots.push( { id, index: parsed.index, position: parsed.position, photo: section.photos[ position ], picked } );
			}
		} );
		if ( slots.length > 0 ) {
			const number = ( seen[ section.type ] ?? 0 ) + 1;
			seen[ section.type ] = number;
			groups.push( { key: `${ at }`, type: section.type, number, slots } );
		}
	} );
	return groups;
}

/** The preset picks with a photo in one slot. A photo picked elsewhere moves. */
function withPresetPick( picks: SlotPick[], slot: string, photoId: string ): SlotPick[] {
	return [ ...picks.filter( ( pick ) => pick.slot !== slot && pick.photoId !== photoId ), { slot, photoId } ];
}

/**
 * The section with a new list of picked photos by position ('' for an open
 * one). Open positions at the end are dropped, and an empty list removes the key.
 */
function withIds( section: Section, ids: string[] ): Section {
	const kept = ids.slice( 0, sectionTake( section ) );
	while ( kept.length > 0 && kept[ kept.length - 1 ] === '' ) {
		kept.pop();
	}
	const next: Section = { ...section, photoIds: kept };
	if ( kept.length === 0 ) {
		delete next.photoIds;
	}
	return next;
}

/**
 * The custom sections with a photo in one slot. A section keeps its picks by
 * position, with '' for an open position. A photo picked elsewhere moves.
 */
function withCustomPick( sections: Section[], index: number, position: number, photoId: string ): Section[] {
	return sections.map( ( section, at ) => {
		const current = section.photoIds ?? [];
		if ( at !== index && ! current.includes( photoId ) ) {
			return section;
		}
		const ids = current.map( ( id ) => ( id === photoId ? '' : id ) );
		if ( at === index ) {
			while ( ids.length <= position ) {
				ids.push( '' );
			}
			ids[ position ] = photoId;
		}
		return withIds( section, ids );
	} );
}

/** The attributes that put a photo in a slot. */
export function choosePhoto( attributes: FullPageAttributes, slot: Slot, photoId: string ): Partial< FullPageAttributes > {
	return isCustom( attributes )
		? { sections: withCustomPick( attributes.sections, slot.index, slot.position, photoId ) }
		: { picks: withPresetPick( attributes.picks, slot.id, photoId ) };
}

/** The attributes that clear the pick of a slot. */
export function clearSlot( attributes: FullPageAttributes, slot: Slot ): Partial< FullPageAttributes > {
	if ( ! isCustom( attributes ) ) {
		return { picks: attributes.picks.filter( ( pick ) => pick.slot !== slot.id ) };
	}
	return {
		sections: attributes.sections.map( ( section, at ) =>
			at === slot.index
				? withIds( section, ( section.photoIds ?? [] ).map( ( id, position ) => ( position === slot.position ? '' : id ) ) )
				: section
		),
	};
}

/**
 * The attributes that drop every pick: the preset picks and the photoIds of
 * the custom sections. Picks name photos of one gallery, so they go when the
 * photographer chooses another one.
 */
export function withoutPicks( attributes: FullPageAttributes ): Partial< FullPageAttributes > {
	return { picks: [], sections: attributes.sections.map( ( section ) => withIds( section, [] ) ) };
}
