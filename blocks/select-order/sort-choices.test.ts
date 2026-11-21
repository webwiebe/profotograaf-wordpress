import { describe, expect, it } from 'vitest';
import { sortChoices } from './sort-choices';

const labels = ( list: { label: string }[] ) => list.map( ( o ) => o.label );
const choice = ( label: string, value = label.toLowerCase() ) => ( { label, value } );

describe( 'sortChoices', () => {
	it( 'sorts without regard to case', () => {
		expect( labels( sortChoices( [ choice( 'banaan' ), choice( 'Appel' ), choice( 'cacao' ) ], 'nl' ) ) ).toEqual( [ 'Appel', 'banaan', 'cacao' ] );
	} );

	it( 'sorts accented letters with their base letter in nl, de and fr', () => {
		const input = [ choice( 'Zebra' ), choice( 'Ötzi' ), choice( 'Eden' ), choice( 'école' ), choice( 'Oslo' ) ];
		for ( const locale of [ 'nl', 'de', 'fr' ] ) {
			expect( labels( sortChoices( input, locale ) ), locale ).toEqual( [ 'Eden', 'école', 'Oslo', 'Ötzi', 'Zebra' ] );
		}
	} );

	it( 'keeps the site default, All, Any and None first, in the given order', () => {
		const input = [ choice( 'Zoom', 'zoom' ), choice( 'None', 'none' ), choice( 'Grow', 'grow' ), choice( 'Site default', '' ), choice( 'All galleries', 'all' ), choice( 'Any', 'any' ) ];
		expect( labels( sortChoices( input, 'en' ) ) ).toEqual( [ 'None', 'Site default', 'All galleries', 'Any', 'Grow', 'Zoom' ] );
	} );

	it( 'reads numbers by value', () => {
		expect( labels( sortChoices( [ choice( 'Heading 10' ), choice( 'Heading 2' ), choice( 'Heading 3' ) ], 'en' ) ) ).toEqual( [ 'Heading 2', 'Heading 3', 'Heading 10' ] );
	} );

	it( 'leaves the input untouched', () => {
		const input = [ choice( 'b' ), choice( 'a' ) ];
		sortChoices( input, 'en' );
		expect( labels( input ) ).toEqual( [ 'b', 'a' ] );
	} );
} );
