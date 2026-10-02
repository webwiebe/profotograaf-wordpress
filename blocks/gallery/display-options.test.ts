import { afterEach, describe, expect, it } from 'vitest';
import {
	DISPLAY_DEFAULTS,
	cleanNumber,
	cssRatio,
	displayControls,
	displaySummary,
	previewLayout,
	siteDefaults,
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

	it( 'names what photos link to', () => {
		expect( displaySummary( { ...DISPLAY_DEFAULTS, linkTo: 'page' } ) ).toEqual( [
			'links to: the photo page on profotograaf',
		] );
	} );
} );

describe( 'displayControls', () => {
	it( 'has one control per display attribute', () => {
		expect( displayControls().map( ( c ) => c.attribute ).sort() ).toEqual(
			Object.keys( DISPLAY_DEFAULTS ).sort()
		);
	} );
} );

describe( 'previewLayout', () => {
	const site = { columns: '5', gap: '0', ratio: '1-1' };

	it( 'uses the Profotograaf default with no attributes and no site defaults', () => {
		expect( previewLayout( DISPLAY_DEFAULTS ) ).toEqual( { columns: 3, gap: 8, ratio: '4 / 3' } );
	} );

	it( 'shows the site defaults when the block sets nothing', () => {
		expect( previewLayout( DISPLAY_DEFAULTS, site ) ).toEqual( { columns: 5, gap: 0, ratio: '1 / 1' } );
	} );

	it( 'lets block attributes override the site defaults', () => {
		const own = { ...DISPLAY_DEFAULTS, columns: '2', gap: '24', ratio: '3-2' };
		expect( previewLayout( own, site ) ).toEqual( { columns: 2, gap: 24, ratio: '3 / 2' } );
	} );

	it( 'overrides one value and keeps the site default for the rest', () => {
		const own = { ...DISPLAY_DEFAULTS, columns: '2' };
		expect( previewLayout( own, site ) ).toEqual( { columns: 2, gap: 0, ratio: '1 / 1' } );
	} );
} );

describe( 'siteDefaults', () => {
	const target = globalThis as { profotograafGalleryDefaults?: unknown };

	afterEach( () => {
		delete target.profotograafGalleryDefaults;
	} );

	it( 'is empty when the server passed nothing', () => {
		expect( siteDefaults() ).toEqual( { columns: '', gap: '', ratio: '' } );
	} );

	it( 'reads the values the server passed', () => {
		target.profotograafGalleryDefaults = { columns: 4, gap: '12', ratio: '16-9' };
		expect( siteDefaults() ).toEqual( { columns: '4', gap: '12', ratio: '16-9' } );
	} );
} );
