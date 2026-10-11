import { describe, expect, it } from 'vitest';
import { choosePhoto, clearSlot, isCustom, parseSlot, slotGroups, withoutPicks } from './picks';
import { blockAttributes, editorialAnswer, previewSection } from '../test-support/full-page';

describe( 'parseSlot', () => {
	it( 'reads the cycle, section index and position', () => {
		expect( parseSlot( '2.4.1' ) ).toEqual( { cycle: 2, index: 4, position: 1 } );
	} );

	it( 'gives null for a tail slot or anything else', () => {
		expect( parseSlot( '' ) ).toBeNull();
		expect( parseSlot( '1.2' ) ).toBeNull();
		expect( parseSlot( 'a.b.c' ) ).toBeNull();
	} );
} );

describe( 'slotGroups in a preset', () => {
	it( 'lists the hero and pair slots and leaves out text and tail sections', () => {
		const groups = slotGroups( blockAttributes(), editorialAnswer() );
		expect( groups.map( ( group ) => group.type ) ).toEqual( [ 'hero', 'pair' ] );
		expect( groups[ 1 ]?.slots.map( ( slot ) => slot.id ) ).toEqual( [ '0.2.0', '0.2.1', '0.2.2' ] );
		expect( groups[ 1 ]?.slots[ 2 ]?.photo?.id ).toBe( 'p-4' );
	} );

	it( 'numbers the sections per type', () => {
		const answer = editorialAnswer();
		answer.sections.push( previewSection( { type: 'hero', slots: [ '1.0.0' ] }, 9 ) );
		const groups = slotGroups( blockAttributes(), answer );
		expect( groups.map( ( group ) => `${ group.type } ${ group.number }` ) ).toEqual( [ 'hero 1', 'pair 1', 'hero 2' ] );
	} );

	it( 'marks the slots that hold a pick', () => {
		const groups = slotGroups( blockAttributes( { picks: [ { slot: '0.2.1', photoId: 'p-9' } ] } ), editorialAnswer() );
		expect( groups[ 1 ]?.slots.map( ( slot ) => slot.picked ) ).toEqual( [ '', 'p-9', '' ] );
	} );
} );

describe( 'slotGroups in Custom', () => {
	const custom = blockAttributes( {
		preset: 'custom',
		sections: [ { type: 'hero', photoIds: [ 'p-7' ] }, { type: 'text' }, { type: 'pair' } ],
	} );

	it( 'reads the picks from the sections', () => {
		const groups = slotGroups( custom, editorialAnswer() );
		expect( groups[ 0 ]?.slots[ 0 ]?.picked ).toBe( 'p-7' );
		expect( groups[ 1 ]?.slots.map( ( slot ) => slot.picked ) ).toEqual( [ '', '', '' ] );
	} );

	it( 'offers only the first cycle', () => {
		const answer = editorialAnswer();
		answer.sections.push( previewSection( { type: 'hero', slots: [ '1.0.0' ] }, 9 ) );
		expect( slotGroups( custom, answer ) ).toHaveLength( 2 );
	} );

	it( 'skips a slot whose section the block no longer has in that place', () => {
		const groups = slotGroups( { ...custom, sections: [ { type: 'grid' } ] }, editorialAnswer() );
		expect( groups ).toEqual( [] );
	} );
} );

describe( 'choosePhoto and clearSlot in a preset', () => {
	const slot = { id: '0.2.1', index: 2, position: 1, photo: undefined, picked: '' };

	it( 'adds a pick', () => {
		expect( choosePhoto( blockAttributes(), slot, 'p-3' ) ).toEqual( { picks: [ { slot: '0.2.1', photoId: 'p-3' } ] } );
	} );

	it( 'replaces the pick of the slot and moves a photo picked elsewhere', () => {
		const attributes = blockAttributes( {
			picks: [
				{ slot: '0.2.1', photoId: 'p-1' },
				{ slot: '0.0.0', photoId: 'p-3' },
				{ slot: '0.2.0', photoId: 'p-5' },
			],
		} );
		expect( choosePhoto( attributes, slot, 'p-3' ) ).toEqual( {
			picks: [
				{ slot: '0.2.0', photoId: 'p-5' },
				{ slot: '0.2.1', photoId: 'p-3' },
			],
		} );
	} );

	it( 'clears one slot', () => {
		const attributes = blockAttributes( { picks: [ { slot: '0.2.1', photoId: 'p-1' }, { slot: '0.0.0', photoId: 'p-3' } ] } );
		expect( clearSlot( attributes, slot ) ).toEqual( { picks: [ { slot: '0.0.0', photoId: 'p-3' } ] } );
	} );
} );

describe( 'choosePhoto and clearSlot in Custom', () => {
	const attributes = blockAttributes( {
		preset: 'custom',
		sections: [ { type: 'hero', photoIds: [ 'p-7' ] }, { type: 'text' }, { type: 'feature', count: 3, photoIds: [ 'p-1', 'p-2' ] } ],
	} );

	it( 'knows the Custom preset', () => {
		expect( isCustom( attributes ) ).toBe( true );
		expect( isCustom( blockAttributes() ) ).toBe( false );
	} );

	it( 'replaces a picked position', () => {
		const result = choosePhoto( attributes, { id: '0.2.1', index: 2, position: 1, photo: undefined, picked: 'p-2' }, 'p-9' );
		expect( result.sections?.[ 2 ]?.photoIds ).toEqual( [ 'p-1', 'p-9' ] );
		expect( result.sections?.[ 1 ] ).toBe( attributes.sections[ 1 ] );
	} );

	it( 'adds a photo at the end for a position past the list', () => {
		const result = choosePhoto( attributes, { id: '0.2.2', index: 2, position: 2, photo: undefined, picked: '' }, 'p-9' );
		expect( result.sections?.[ 2 ]?.photoIds ).toEqual( [ 'p-1', 'p-2', 'p-9' ] );
	} );

	it( 'moves a photo picked in another section and drops the emptied list', () => {
		const result = choosePhoto( attributes, { id: '0.2.2', index: 2, position: 2, photo: undefined, picked: '' }, 'p-7' );
		expect( result.sections?.[ 0 ] ).toEqual( { type: 'hero' } );
		expect( result.sections?.[ 2 ]?.photoIds ).toEqual( [ 'p-1', 'p-2', 'p-7' ] );
	} );

	it( 'keeps no more picks than the section takes', () => {
		const full = blockAttributes( { preset: 'custom', sections: [ { type: 'hero', photoIds: [ 'p-1' ] } ] } );
		const result = choosePhoto( full, { id: '0.0.0', index: 0, position: 0, photo: undefined, picked: 'p-1' }, 'p-2' );
		expect( result.sections?.[ 0 ]?.photoIds ).toEqual( [ 'p-2' ] );
	} );

	it( 'clears one position and keeps the others in place', () => {
		const result = clearSlot( attributes, { id: '0.2.0', index: 2, position: 0, photo: undefined, picked: 'p-1' } );
		expect( result.sections?.[ 2 ]?.photoIds ).toEqual( [ '', 'p-2' ] );
		expect( result.sections?.[ 0 ] ).toBe( attributes.sections[ 0 ] );
		const last = clearSlot( attributes, { id: '0.2.1', index: 2, position: 1, photo: undefined, picked: 'p-2' } );
		expect( last.sections?.[ 2 ]?.photoIds ).toEqual( [ 'p-1' ] );
	} );

	it( 'keeps a photo on the position it was picked for', () => {
		const empty = blockAttributes( { preset: 'custom', sections: [ { type: 'feature', count: 3 } ] } );
		const result = choosePhoto( empty, { id: '0.0.2', index: 0, position: 2, photo: undefined, picked: '' }, 'p-9' );
		expect( result.sections?.[ 0 ]?.photoIds ).toEqual( [ '', '', 'p-9' ] );
	} );
} );

describe( 'withoutPicks', () => {
	it( 'drops the preset picks and the photos of custom sections', () => {
		const attributes = blockAttributes( {
			picks: [ { slot: '0.0.0', photoId: 'p-1' } ],
			sections: [ { type: 'hero', photoIds: [ 'p-7' ] }, { type: 'grid', columns: 4 } ],
		} );
		expect( withoutPicks( attributes ) ).toEqual( { picks: [], sections: [ { type: 'hero' }, { type: 'grid', columns: 4 } ] } );
	} );
} );
