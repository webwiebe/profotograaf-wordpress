import { describe, expect, it } from 'vitest';
import layoutMap from '../../includes/layout-map.json';
import { displayControls, DISPLAY_DEFAULTS, previewLayout } from './display-options';
import { drawnLayout, layoutHint, layoutName, platformDefaults } from './layout-map';

const PLATFORM_LAYOUTS = [
	'grid',
	'masonry',
	'justified',
	'mosaic',
	'lighttable',
	'parallax',
	'direct',
	'cinema',
	'filmstrip',
	'slideout',
	'flickr',
	'instagram',
	'duo',
];

describe( 'the layout table', () => {
	it( 'maps every platform layout to a layout embed.js draws', () => {
		expect( Object.keys( layoutMap.map ).sort() ).toEqual( [ ...PLATFORM_LAYOUTS ].sort() );
		for ( const drawn of Object.values( layoutMap.map ) ) {
			expect( layoutMap.drawn ).toContain( drawn );
		}
		expect( layoutMap.drawn ).toContain( layoutMap.fallback );
	} );

	it.each( [
		[ 'masonry', 'masonry' ],
		[ 'justified', 'masonry' ],
		[ 'mosaic', 'masonry' ],
		[ 'lighttable', 'masonry' ],
		[ 'parallax', 'slideshow' ],
		[ 'direct', 'slideshow' ],
		[ 'cinema', 'slideshow' ],
		[ 'filmstrip', 'slideshow' ],
		[ 'slideout', 'slideshow' ],
		[ 'flickr', 'grid' ],
		[ 'instagram', 'grid' ],
		[ 'duo', 'grid' ],
	] )( 'draws %s as %s', ( platform, drawn ) => {
		expect( drawnLayout( platform ) ).toEqual( { layout: drawn, known: true } );
	} );

	it( 'falls back to the grid for a layout it does not know', () => {
		expect( drawnLayout( 'carousel' ) ).toEqual( { layout: 'grid', known: false } );
		expect( drawnLayout( '' ) ).toEqual( { layout: 'grid', known: false } );
	} );

	it( 'prefers the layout the platform reports as drawn', () => {
		expect( drawnLayout( 'parallax', 'masonry' ) ).toEqual( { layout: 'masonry', known: true } );
		expect( drawnLayout( 'carousel', 'slideshow' ) ).toEqual( { layout: 'slideshow', known: true } );
		expect( drawnLayout( 'parallax', 'bogus' ) ).toEqual( { layout: 'slideshow', known: true } );
	} );
} );

describe( 'layoutName', () => {
	it( 'names the known layouts and tidies an unknown one', () => {
		expect( layoutName( 'lighttable' ) ).toBe( 'Light table' );
		expect( layoutName( 'parallax' ) ).toBe( 'Parallax' );
		expect( layoutName( 'swipe-deck' ) ).toBe( 'Swipe deck' );
	} );

	it( 'has a name for every layout in the table', () => {
		for ( const platform of Object.keys( layoutMap.map ) ) {
			expect( layoutName( platform ) ).toMatch( /^[A-Z][a-z]+( [a-z]+)?$/ );
		}
	} );
} );

describe( 'layoutHint', () => {
	it( 'says what the platform layout is shown as', () => {
		expect( layoutHint( { layout: 'parallax' } ) ).toBe(
			'Parallax on Profotograaf, shown as Slideshow on your site'
		);
		expect( layoutHint( { layout: 'instagram' } ) ).toBe(
			'Instagram on Profotograaf, shown as Grid on your site'
		);
	} );

	it( 'says so when the layout is unknown and falls back to the grid', () => {
		const hint = layoutHint( { layout: 'carousel' } );
		expect( hint ).toContain( 'Carousel on Profotograaf' );
		expect( hint ).toContain( 'does not know that layout' );
		expect( hint ).toContain( 'shown as Grid on your site' );
	} );

	it( 'prefers the layout the platform reports as drawn', () => {
		expect( layoutHint( { layout: 'parallax', embed_layout: 'masonry' } ) ).toBe(
			'Parallax on Profotograaf, shown as Masonry on your site'
		);
		expect( layoutHint( { layout: '', embed_layout: 'slideshow' } ) ).toBe(
			'Shown as Slideshow on your site.'
		);
	} );

	it( 'has no hint without a known gallery layout', () => {
		expect( layoutHint( null ) ).toBe( '' );
		expect( layoutHint( undefined ) ).toBe( '' );
		expect( layoutHint( {} ) ).toBe( '' );
		expect( layoutHint( { layout: '' } ) ).toBe( '' );
	} );
} );

describe( 'platformDefaults', () => {
	const site = { columns: '', columnsTablet: '', columnsMobile: '', gap: '', ratio: '1-1', layout: '' };
	const shape = ( layout: string ) =>
		displayControls( DISPLAY_DEFAULTS, platformDefaults( site, { layout } ) ).find( ( c ) => c.attribute === 'ratio' );

	it.each( [ 'masonry', 'justified', 'mosaic', 'lighttable' ] )( 'hides the shape control for %s', ( layout ) => {
		expect( shape( layout )?.kind ).toBe( 'note' );
		expect( previewLayout( DISPLAY_DEFAULTS, platformDefaults( site, { layout } ) ).ratio ).toBe( '4 / 3' );
	} );

	it.each( [ 'parallax', 'instagram', 'carousel' ] )( 'keeps the shape control for %s', ( layout ) => {
		expect( shape( layout )?.kind ).toBe( 'select' );
	} );

	it( 'leaves the defaults alone when the site or the gallery has no layout to give', () => {
		expect( platformDefaults( { ...site, layout: 'grid' }, { layout: 'justified' } ).layout ).toBe( 'grid' );
		expect( platformDefaults( site, null ) ).toBe( site );
		expect( platformDefaults( site, { layout: '' } ) ).toBe( site );
	} );

	it( 'uses the layout the platform reports as drawn', () => {
		expect( platformDefaults( site, { layout: 'parallax', embed_layout: 'masonry' } ).layout ).toBe( 'masonry' );
	} );
} );
