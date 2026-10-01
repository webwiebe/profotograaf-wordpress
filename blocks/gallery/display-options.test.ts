import { describe, expect, it } from 'vitest';
import {
	DISPLAY_DEFAULTS,
	cleanNumber,
	cssRatio,
	displayControls,
	displaySummary,
} from './display-options';

describe( 'cleanNumber', () => {
	it( 'keeps an empty value empty', () => {
		expect( cleanNumber( '', 1, 8 ) ).toBe( '' );
		expect( cleanNumber( '  ', 1, 8 ) ).toBe( '' );
	} );

	it( 'rounds and clamps into the bounds', () => {
		expect( cleanNumber( '3.6', 1, 8 ) ).toBe( '4' );
		expect( cleanNumber( '0', 1, 8 ) ).toBe( '1' );
		expect( cleanNumber( '40', 1, 8 ) ).toBe( '8' );
		expect( cleanNumber( '0', 0, 96 ) ).toBe( '0' );
	} );

	it( 'drops text', () => {
		expect( cleanNumber( 'wide', 1, 8 ) ).toBe( '' );
	} );
} );

describe( 'cssRatio', () => {
	it( 'turns a stored ratio into a CSS ratio', () => {
		expect( cssRatio( '3-2' ) ).toBe( '3 / 2' );
	} );

	it( 'uses 4 / 3 for original and for no choice', () => {
		expect( cssRatio( 'original' ) ).toBe( '4 / 3' );
		expect( cssRatio( '' ) ).toBe( '4 / 3' );
	} );
} );

describe( 'displaySummary', () => {
	it( 'is empty when nothing is set', () => {
		expect( displaySummary( DISPLAY_DEFAULTS ) ).toEqual( [] );
	} );

	it( 'names sort, paging and lightbox', () => {
		expect(
			displaySummary( {
				...DISPLAY_DEFAULTS,
				sort: 'newest',
				perPage: '1',
				loadMore: 'on',
				lightbox: 'off',
			} )
		).toEqual( [ 'Newest first', '1 photo per page', 'load more button', 'lightbox off' ] );
	} );
} );

describe( 'displayControls', () => {
	it( 'has one control per display attribute', () => {
		expect( displayControls().map( ( c ) => c.attribute ).sort() ).toEqual(
			Object.keys( DISPLAY_DEFAULTS ).sort()
		);
	} );
} );
