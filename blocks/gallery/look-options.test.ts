import { describe, expect, it } from 'vitest';
import { siteDefaults } from './display-options';
import { cleanColor, gapPreset, hoverOptions, isSquare, lookScope, radiusOptions } from './look-options';

const none = siteDefaults();
const values = ( options: Array< { value: string } > ) => options.map( ( o ) => o.value );

describe( 'gapPreset', () => {
	it( 'names a preset gap, the site default, or a custom number', () => {
		expect( gapPreset( '' ) ).toBe( '' );
		expect( gapPreset( '0' ) ).toBe( '0' );
		expect( gapPreset( '24' ) ).toBe( '24' );
		expect( gapPreset( '7' ) ).toBe( 'custom' );
	} );
} );

describe( 'isSquare', () => {
	it( 'is true for a grid without a shape and for the 1:1 shape', () => {
		expect( isSquare( {}, none ) ).toBe( true );
		expect( isSquare( { layout: 'grid', ratio: '1-1' }, none ) ).toBe( true );
		expect( isSquare( { layout: 'slideshow', ratio: '1-1' }, none ) ).toBe( true );
	} );

	it( 'is false for other shapes, masonry and a slideshow without a shape', () => {
		expect( isSquare( { layout: 'grid', ratio: '4-3' }, none ) ).toBe( false );
		expect( isSquare( { layout: 'masonry', ratio: '1-1' }, none ) ).toBe( false );
		expect( isSquare( { layout: 'slideshow' }, none ) ).toBe( false );
	} );

	it( 'follows the site shape and layout, and reads auto as not set', () => {
		expect( isSquare( {}, { ...none, ratio: '3-2' } ) ).toBe( false );
		expect( isSquare( { layout: 'auto' }, { ...none, layout: 'masonry' } ) ).toBe( false );
		expect( isSquare( { ratio: '1-1' }, { ...none, ratio: '3-2' } ) ).toBe( true );
	} );
} );

describe( 'radiusOptions', () => {
	it( 'offers the circle for square photos only', () => {
		expect( values( radiusOptions( true, '' ) ) ).toEqual( [ '', 'none', 'subtle', 'rounded', 'full' ] );
		expect( values( radiusOptions( false, '' ) ) ).toEqual( [ '', 'none', 'subtle', 'rounded' ] );
	} );

	it( 'keeps a stored circle visible, marked, when the photos are not square', () => {
		const options = radiusOptions( false, 'full' );
		expect( options.at( -1 ) ).toEqual( { label: expect.stringMatching( /not drawn/ ), value: 'full' } );
	} );
} );

describe( 'hoverOptions', () => {
	it( 'leaves grow out of a slideshow', () => {
		expect( values( hoverOptions( false ) ) ).toEqual( [ '', 'zoom', 'grow', 'none' ] );
		expect( values( hoverOptions( true ) ) ).toEqual( [ '', 'zoom', 'none' ] );
	} );
} );

describe( 'lookScope', () => {
	it( 'marks the slideshow the block or site draws', () => {
		expect( lookScope( { layout: 'slideshow' }, none ).slideshow ).toBe( true );
		expect( lookScope( {}, { ...none, layout: 'slideshow' } ).slideshow ).toBe( true );
		expect( lookScope( { layout: 'grid' }, { ...none, layout: 'slideshow' } ).slideshow ).toBe( false );
	} );
} );

describe( 'cleanColor', () => {
	it( 'keeps a 3 or 6 digit hex code in lower case and drops anything else', () => {
		expect( cleanColor( ' #ABCDEF ' ) ).toBe( '#abcdef' );
		expect( cleanColor( '#fff' ) ).toBe( '#fff' );
		expect( cleanColor( 'white' ) ).toBe( '' );
		expect( cleanColor( '#ffffff80' ) ).toBe( '' );
		expect( cleanColor( undefined ) ).toBe( '' );
	} );
} );
