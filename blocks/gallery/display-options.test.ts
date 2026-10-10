import { afterEach, describe, expect, it } from 'vitest';
import {
	DISPLAY_DEFAULTS,
	cleanNumber,
	cssRatio,
	displayControls,
	effectiveColumns,
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

	it( 'names what photos link to when the lightbox is off', () => {
		expect( displaySummary( { ...DISPLAY_DEFAULTS, lightbox: 'off', linkTo: 'page' } ) ).toEqual( [
			'lightbox off',
			'links to: the photo page on profotograaf',
		] );
		expect( displaySummary( { ...DISPLAY_DEFAULTS, lightbox: 'off', linkTo: 'site' } ) ).toEqual( [
			'lightbox off',
			'links to: your photographer site',
		] );
	} );

	it( 'says a link target waits for the lightbox to be off', () => {
		expect( displaySummary( { ...DISPLAY_DEFAULTS, linkTo: 'file' } ) ).toEqual( [
			'links to: the original photo file (lightbox off only)',
		] );
	} );

	it( 'leaves the link target out while the lightbox is on', () => {
		expect( displaySummary( { ...DISPLAY_DEFAULTS, lightbox: 'on', linkTo: 'page' } ) ).toEqual( [
			'lightbox on',
		] );
	} );

	it( 'names the new tab choice only next to a link target', () => {
		expect(
			displaySummary( { ...DISPLAY_DEFAULTS, lightbox: 'off', linkTo: 'page', linkNewTab: 'off' } )
		).toEqual( [ 'lightbox off', 'links to: the photo page on profotograaf', 'same tab' ] );
		expect( displaySummary( { ...DISPLAY_DEFAULTS, linkNewTab: 'off' } ) ).toEqual( [] );
	} );
} );

describe( 'link controls', () => {
	const control = ( attribute: string ) => displayControls().find( ( c ) => c.attribute === attribute );

	it( 'offers every link target and keeps the saved values', () => {
		expect( control( 'linkTo' )?.options?.map( ( o ) => o.value ) ).toEqual( [ '', 'none', 'page', 'site', 'file' ] );
	} );

	it( 'offers the new tab choice as on, off or the site default', () => {
		expect( control( 'linkNewTab' )?.options?.map( ( o ) => o.value ) ).toEqual( [ '', 'on', 'off' ] );
	} );

	it( 'tells the author the link options apply with the lightbox off', () => {
		expect( control( 'linkTo' )?.help ).toMatch( /lightbox is off/ );
		expect( control( 'linkNewTab' )?.help ).toMatch( /lightbox is off/ );
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
	const site = { ...siteDefaults(), columns: '5', gap: '0', ratio: '1-1' };

	it( 'ignores the shape for masonry, from the block and from the site', () => {
		expect( previewLayout( { ...DISPLAY_DEFAULTS, ratio: '3-2', layout: 'masonry' }, site ).ratio ).toBe( '4 / 3' );
		expect( previewLayout( DISPLAY_DEFAULTS, { ...site, layout: 'masonry' } ).ratio ).toBe( '4 / 3' );
		expect( previewLayout( { ...DISPLAY_DEFAULTS, layout: 'grid' }, { ...site, layout: 'masonry' } ).ratio ).toBe( '1 / 1' );
	} );

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
		expect( siteDefaults() ).toEqual( {
			columns: '',
			columnsTablet: '',
			columnsMobile: '',
			gap: '',
			ratio: '',
			layout: '',
		} );
	} );

	it( 'reads the values the server passed', () => {
		target.profotograafGalleryDefaults = { columns: 4, gap: '12', ratio: '16-9' };
		expect( siteDefaults() ).toEqual( {
			columns: '4',
			columnsTablet: '',
			columnsMobile: '',
			gap: '12',
			ratio: '16-9',
			layout: '',
		} );
	} );
} );

describe( 'effectiveColumns', () => {
	const none = { columns: '', columnsTablet: '', columnsMobile: '' };
	const site = { columns: '', columnsTablet: '', columnsMobile: '', gap: '', ratio: '', layout: '' };

	it( 'steps a grid down to 3 on tablets and 2 on phones', () => {
		expect( effectiveColumns( { ...none, columns: '5' }, site, 'grid' ) ).toEqual( { tablet: '3', mobile: '2' } );
	} );

	it( 'steps masonry down to 1 on phones', () => {
		expect( effectiveColumns( { ...none, columns: '5' }, site, 'masonry' ) ).toEqual( { tablet: '3', mobile: '1' } );
	} );

	it( 'does not step up', () => {
		expect( effectiveColumns( { ...none, columns: '1' }, site, 'grid' ) ).toEqual( { tablet: '1', mobile: '1' } );
		expect( effectiveColumns( { ...none, columns: '2' }, site, 'grid' ) ).toEqual( { tablet: '2', mobile: '2' } );
	} );

	it( 'treats an empty layout as the site layout, then as a grid', () => {
		expect( effectiveColumns( { ...none, columns: '5' }, site, '' ).mobile ).toBe( '2' );
		expect( effectiveColumns( { ...none, columns: '5' }, { ...site, layout: 'masonry' }, '' ).mobile ).toBe( '1' );
	} );

	it( 'lets explicit block values win and steps the phone down from the tablet', () => {
		expect( effectiveColumns( { columns: '5', columnsTablet: '5', columnsMobile: '4' }, site, 'grid' ) ).toEqual( {
			tablet: '5',
			mobile: '4',
		} );
		expect( effectiveColumns( { columns: '5', columnsTablet: '1', columnsMobile: '' }, site, 'grid' ) ).toEqual( {
			tablet: '1',
			mobile: '1',
		} );
	} );

	it( 'uses the site columns and the site tablet and phone values', () => {
		expect( effectiveColumns( none, { ...site, columns: '6' }, 'grid' ) ).toEqual( { tablet: '3', mobile: '2' } );
		expect( effectiveColumns( { ...none, columns: '6' }, { ...site, columnsTablet: '2', columnsMobile: '1' }, 'grid' ) ).toEqual( {
			tablet: '2',
			mobile: '1',
		} );
	} );

	it( 'shows nothing when no columns are set or for a slideshow', () => {
		expect( effectiveColumns( none, site, 'grid' ) ).toEqual( { tablet: '', mobile: '' } );
		expect( effectiveColumns( { ...none, columns: '5' }, site, 'slideshow' ) ).toEqual( { tablet: '', mobile: '' } );
	} );
} );

describe( 'displayControls with a block', () => {
	const find = ( controls: ReturnType< typeof displayControls >, attribute: string ) =>
		controls.find( ( c ) => c.attribute === attribute );
	const site = { ...siteDefaults() };

	it( 'shows the effective tablet and phone columns as placeholders', () => {
		const controls = displayControls( { ...DISPLAY_DEFAULTS, columns: '5', layout: 'grid' }, site );
		expect( find( controls, 'columnsTablet' )?.placeholder ).toBe( '3' );
		expect( find( controls, 'columnsMobile' )?.placeholder ).toBe( '2' );
	} );

	it( 'offers Original except for a grid', () => {
		const values = ( layout: string, ratio = '' ) =>
			find( displayControls( { ...DISPLAY_DEFAULTS, ratio, layout }, site ), 'ratio' )?.options?.map( ( o ) => o.value );
		expect( values( 'slideshow' ) ).toContain( 'original' );
		expect( values( 'grid' ) ).not.toContain( 'original' );
		expect( values( '' ) ).not.toContain( 'original' );
		expect( values( '' ) ).toContain( '1-1' );
	} );

	it( 'replaces the Photo shape control with a hint for masonry', () => {
		const control = find( displayControls( { ...DISPLAY_DEFAULTS, ratio: '1-1', layout: 'masonry' }, site ), 'ratio' );
		expect( control?.kind ).toBe( 'note' );
		expect( control?.options ).toBeUndefined();
		expect( control?.help ).toBe( "Masonry keeps each photo's own shape." );
	} );

	it( 'follows the site layout when the block has none', () => {
		const masonry = displayControls( DISPLAY_DEFAULTS, { ...site, layout: 'masonry' } );
		expect( find( masonry, 'ratio' )?.kind ).toBe( 'note' );
		const slideshow = displayControls( DISPLAY_DEFAULTS, { ...site, layout: 'slideshow' } );
		expect( find( slideshow, 'ratio' )?.options?.map( ( o ) => o.value ) ).toContain( 'original' );
	} );

	it( 'lets an explicit grid block override a masonry site layout', () => {
		const controls = displayControls( { ...DISPLAY_DEFAULTS, layout: 'grid' }, { ...site, layout: 'masonry' } );
		expect( find( controls, 'ratio' )?.kind ).toBe( 'select' );
	} );

	it( 'keeps a stored Original on a grid, marked as not drawn yet', () => {
		const option = find( displayControls( { ...DISPLAY_DEFAULTS, ratio: 'original', layout: 'grid' }, site ), 'ratio' )
			?.options?.find( ( o ) => o.value === 'original' );
		expect( option?.label ).toMatch( /not drawn in a grid/ );
	} );
} );
