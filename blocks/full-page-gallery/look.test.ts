import { describe, expect, it } from 'vitest';
import { drawnValue, isLookField, isSquareSection, lookData, lookStyle } from './look';

describe( 'section look', () => {
	it( 'knows the look fields', () => {
		expect( isLookField( 'gapRow' ) ).toBe( true );
		expect( isLookField( 'columns' ) ).toBe( false );
	} );

	it( 'reads the drawn value from the section or the default', () => {
		expect( drawnValue( { type: 'grid' }, 'hover' ) ).toBe( 'zoom' );
		expect( drawnValue( { type: 'grid', hover: 'none' }, 'hover' ) ).toBe( 'none' );
		expect( drawnValue( { type: 'grid', gapRow: '' }, 'gapRow' ) ).toBe( '' );
	} );

	it( 'treats only a 1:1 grid as square', () => {
		expect( isSquareSection( { type: 'grid', ratio: '1-1' } ) ).toBe( true );
		expect( isSquareSection( { type: 'grid' } ) ).toBe( false );
		expect( isSquareSection( { type: 'masonry', ratio: '1-1' } ) ).toBe( false );
	} );

	it( 'writes the custom properties Full_Page_Look writes', () => {
		expect( lookStyle( { type: 'grid', gapRow: '', radius: 'none', frameWidth: 0 } ) ).toEqual( {} );
		expect(
			lookStyle( { type: 'grid', ratio: '1-1', gapRow: 24, gapColumn: 0, radius: 'full', frameWidth: 6, frameColor: '#111111' } )
		).toEqual( {
			'--pf-gap-row': '24px',
			'--pf-gap-col': '0px',
			'--pf-radius': '50%',
			'--pf-frame': '6px',
			'--pf-frame-color': '#111111',
		} );
		expect( lookStyle( { type: 'grid', radius: 'full', frameWidth: 2 } ) ).toEqual( { '--pf-frame': '2px', '--pf-frame-color': '#ffffff' } );
		expect( lookStyle( { type: 'pair', radius: 'rounded' } ) ).toEqual( { '--pf-radius': '12px' } );
	} );

	it( 'writes the data attributes Full_Page_Look writes', () => {
		expect( lookData( { type: 'grid', hover: 'zoom', hoverStrength: 'strong', shadow: 'soft' } ) ).toEqual( {
			'data-hover': 'zoom',
			'data-hover-strength': 'strong',
			'data-shadow': 'soft',
		} );
		expect( lookData( { type: 'grid', hover: 'grow' } ) ).toEqual( { 'data-hover': 'grow', 'data-hover-strength': 'subtle' } );
		expect( lookData( { type: 'grid', hover: 'none', shadow: 'none' } ) ).toEqual( {} );
		expect( lookData( { type: 'text' } ) ).toEqual( {} );
	} );
} );
