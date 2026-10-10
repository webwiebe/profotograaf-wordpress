// @ts-check
// Display options through the real embed.js (tests/e2e/fixtures/embed.js): the
// plugin writes data-* attributes and the script draws the gallery from them.
// Each test asserts the attribute the plugin emitted and the layout it produced.
const { test, expect } = require( '@playwright/test' );
const { publishPost, shortcode, block, openEmbed, expectTiles, measure, wp } = require( './embed-helpers' );

const TOTAL = 19;
const CLOSE = 1;

/**
 * Number of distinct tile left offsets, which is the number of columns.
 *
 * @param {Awaited<ReturnType<typeof measure>>} box
 */
const columnsOf = ( box ) => new Set( box.tiles.map( ( tile ) => Math.round( tile.left ) ) ).size;

/**
 * Masonry keeps each photo's own shape, so the tiles differ in height.
 *
 * @param {import('@playwright/test').Locator} host
 */
async function expectTilesOfDifferentHeights( host ) {
	const { tiles } = await measure( host );
	const heights = new Set( tiles.map( ( tile ) => Math.round( tile.height ) ) );
	expect( heights.size, 'masonry tiles share one height' ).toBeGreaterThan( 1 );
}

/**
 * @param {string} content
 * @param {{width:number,height:number}} viewport
 * @param {import('@playwright/test').Browser} browser
 */
async function draw( content, viewport, browser ) {
	const opened = await openEmbed( browser, publishPost( 'Options', content ), viewport );
	await expectTiles( opened.host, TOTAL );
	return opened;
}

test.describe( 'display options with the real script', () => {
	for ( const layout of [ 'grid', 'masonry' ] ) {
		test( `${ layout } columns=4 gives four columns on a wide screen`, async ( { browser } ) => {
			const { context, host, problems } = await draw( shortcode( { layout, columns: '4' } ), { width: 1440, height: 900 }, browser );

			await expect( host ).toHaveAttribute( 'data-columns', '4' );
			expect( columnsOf( await measure( host ) ) ).toBe( 4 );
			expect( problems ).toEqual( [] );
			await context.close();
		} );

		test( `${ layout } gap=0 makes the tiles touch`, async ( { browser } ) => {
			const { context, host } = await draw( shortcode( { layout, columns: '4', gap: '0' } ), { width: 1440, height: 900 }, browser );

			await expect( host ).toHaveAttribute( 'data-gap', '0' );
			const { tiles } = await measure( host );
			const byColumn = new Map();
			for ( const tile of tiles ) {
				const key = Math.round( tile.left );
				byColumn.set( key, [ ...( byColumn.get( key ) || [] ), tile ] );
			}
			const lefts = [ ...byColumn.keys() ].sort( ( a, b ) => a - b );
			expect( lefts ).toHaveLength( 4 );
			for ( const column of byColumn.values() ) {
				column.sort( ( a, b ) => a.top - b.top );
				column.slice( 1 ).forEach( ( tile, index ) => {
					expect( Math.abs( tile.top - ( column[ index ].top + column[ index ].height ) ) ).toBeLessThanOrEqual( CLOSE );
				} );
			}
			const first = byColumn.get( lefts[ 0 ] )[ 0 ];
			const second = byColumn.get( lefts[ 1 ] )[ 0 ];
			expect( Math.abs( second.left - ( first.left + first.width ) ) ).toBeLessThanOrEqual( CLOSE );
			await context.close();
		} );
	}

	test( 'gap=0 leaves no rounded corner specks between tiles', async ( { browser } ) => {
		const { context, host } = await draw( shortcode( { layout: 'grid', columns: '4', gap: '0' } ), { width: 1440, height: 900 }, browser );

		const radius = await host.locator( '.photos .tile' ).first().evaluate( ( tile ) => getComputedStyle( tile ).borderTopLeftRadius );
		expect( radius ).toBe( '0px' );
		await context.close();
	} );

	for ( const [ name, width, expected ] of [ [ 'tablet', 820, 3 ], [ 'mobile', 390, 2 ] ] ) {
		test( `columns per breakpoint: ${ name } shows ${ expected } columns`, async ( { browser } ) => {
			const content = shortcode( { layout: 'grid', columns: '4', columns_tablet: '3', columns_mobile: '2' } );
			const { context, host } = await draw( content, { width: Number( width ), height: 900 }, browser );

			await expect( host ).toHaveAttribute( 'data-columns-tablet', '3' );
			await expect( host ).toHaveAttribute( 'data-columns-mobile', '2' );
			expect( columnsOf( await measure( host ) ) ).toBe( expected );
			await context.close();
		} );
	}

	test( 'the plugin sends the per breakpoint column counts as data attributes', async ( { browser } ) => {
		const { context, host } = await draw( shortcode( { columns: '4', columns_tablet: '3', columns_mobile: '2' } ), { width: 1440, height: 900 }, browser );

		await expect( host ).toHaveAttribute( 'data-columns-tablet', '3' );
		await expect( host ).toHaveAttribute( 'data-columns-mobile', '2' );
		await context.close();
	} );

	test( 'sort=newest puts the last photo first', async ( { browser } ) => {
		const { context, host } = await draw( shortcode( { sort: 'newest' } ), { width: 1440, height: 900 }, browser );

		await expect( host ).toHaveAttribute( 'data-sort', 'newest' );
		await expect( host.locator( '.photos .tile' ).first() ).toHaveAccessibleName( `Photo ${ TOTAL }` );
		await context.close();
	} );

	test( 'ratio 1:1 crops every grid tile to a square', async ( { browser } ) => {
		const { context, host } = await draw( shortcode( { layout: 'grid', ratio: '1-1' } ), { width: 1440, height: 900 }, browser );

		await expect( host ).toHaveAttribute( 'data-ratio', '1:1' );
		const { tiles } = await measure( host );
		for ( const tile of tiles ) {
			expect( Math.abs( tile.width - tile.height ) ).toBeLessThanOrEqual( CLOSE );
		}
		await context.close();
	} );

	test( 'masonry ignores a block ratio and keeps each photo shape', async ( { browser } ) => {
		const { context, host } = await draw( shortcode( { layout: 'masonry', ratio: '1-1' } ), { width: 1440, height: 900 }, browser );

		await expect( host ).not.toHaveAttribute( 'data-ratio', /.*/ );
		await expectTilesOfDifferentHeights( host );
		await context.close();
	} );

	test( 'masonry ignores the site-wide 1:1 photo shape and draws tiles of different heights', async ( { browser } ) => {
		const current = wp( 'eval', "$o = get_option( 'profotograaf_settings', array() ); echo $o['gallery_ratio'] ?? '';" );
		const setRatio = ( value ) => wp( 'eval', `$o = get_option( 'profotograaf_settings', array() ); $o['gallery_ratio'] = '${ value }'; update_option( 'profotograaf_settings', $o );` );
		setRatio( '1-1' );
		try {
			const masonry = await draw( shortcode( { layout: 'masonry' } ), { width: 1440, height: 900 }, browser );
			await expect( masonry.host ).not.toHaveAttribute( 'data-ratio', /.*/ );
			await expectTilesOfDifferentHeights( masonry.host );
			await masonry.context.close();

			const grid = await draw( shortcode( { layout: 'grid' } ), { width: 1440, height: 900 }, browser );
			await expect( grid.host ).toHaveAttribute( 'data-ratio', '1:1' );
			await grid.context.close();
		} finally {
			setRatio( current );
		}
	} );

	test( 'duotone applies the pf-duo filter to every photo', async ( { browser } ) => {
		const { context, host } = await draw( shortcode( { duotone: '#112233,#ffddaa' } ), { width: 1440, height: 900 }, browser );

		await expect( host ).toHaveAttribute( 'data-duotone', '#112233,#ffddaa' );
		await expect( host.locator( 'svg filter#pf-duo' ) ).toHaveCount( 1 );
		const filters = await host.locator( '.photos .tile img' ).evaluateAll( ( images ) => images.map( ( img ) => getComputedStyle( img ).filter ) );
		expect( filters ).toHaveLength( TOTAL );
		for ( const filter of filters ) {
			expect( filter ).toContain( '#pf-duo' );
		}
		await context.close();
	} );

	test( 'lightbox on opens a dialog and off leaves the tile inert', async ( { browser } ) => {
		const on = await draw( shortcode( { lightbox: 'on' } ), { width: 1440, height: 900 }, browser );
		await on.host.locator( '.photos .tile' ).first().click();
		await expect( on.host.getByRole( 'dialog' ) ).toBeVisible();
		await on.context.close();

		const off = await draw( shortcode( { lightbox: 'off' } ), { width: 1440, height: 900 }, browser );
		await expect( off.host ).toHaveAttribute( 'data-lightbox', 'off' );
		await expect( off.host.locator( '.photos .tile.static' ) ).toHaveCount( TOTAL );
		await off.host.locator( '.photos .tile' ).first().click();
		await expect( off.host.getByRole( 'dialog' ) ).toHaveCount( 0 );
		await off.context.close();
	} );
} );

test.describe( 'link-to with lightbox off', () => {
	test( 'link_to=none leaves the tiles inert', async ( { browser } ) => {
		const { context, host } = await draw( shortcode( { lightbox: 'off', link_to: 'none' } ), { width: 1440, height: 900 }, browser );

		await expect( host ).toHaveAttribute( 'data-link-to', 'none' );
		const first = host.locator( '.photos .tile' ).first();
		expect( await first.evaluate( ( tile ) => tile.tagName ) ).not.toBe( 'A' );
		await first.click();
		await expect( host.getByRole( 'dialog' ) ).toHaveCount( 0 );
		await context.close();
	} );

	// The mock payload carries site_url and a per-photo url, as the platform's
	// embed API does. `file` links to the web image.
	const TARGETS = { page: /\/share\/g\/spring-wedding\/photo\/p-\d+$/, site: /\/studio-e2e$/, file: /\/img\/p-\d+\/web\.svg$/ };
	for ( const [ linkTo, href ] of Object.entries( TARGETS ) ) {
		test( `link_to=${ linkTo } draws an anchor that opens in a new tab`, async ( { browser } ) => {
			const { context, host } = await draw( shortcode( { lightbox: 'off', link_to: linkTo } ), { width: 1440, height: 900 }, browser );

			await expect( host ).toHaveAttribute( 'data-link-to', linkTo );
			const first = host.locator( '.photos .tile' ).first();
			expect( await first.evaluate( ( tile ) => tile.tagName ) ).toBe( 'A' );
			await expect( first ).toHaveAttribute( 'href', href );
			await expect( first ).toHaveAttribute( 'target', '_blank' );
			await expect( first ).toHaveAttribute( 'rel', /noopener/ );
			await context.close();
		} );
	}

	test( 'link_new_tab=off opens the link in the same tab', async ( { browser } ) => {
		const { context, host } = await draw( shortcode( { lightbox: 'off', link_to: 'page', link_new_tab: 'off' } ), { width: 1440, height: 900 }, browser );

		await expect( host ).toHaveAttribute( 'data-link-new-tab', 'off' );
		const first = host.locator( '.photos .tile' ).first();
		expect( await first.evaluate( ( tile ) => tile.tagName ) ).toBe( 'A' );
		await expect( first ).not.toHaveAttribute( 'target', '_blank' );
		await context.close();
	} );

	test( 'link_to is ignored while the lightbox is on', async ( { browser } ) => {
		const { context, host } = await draw( shortcode( { lightbox: 'on', link_to: 'page' } ), { width: 1440, height: 900 }, browser );

		expect( await host.locator( '.photos .tile' ).first().evaluate( ( tile ) => tile.tagName ) ).not.toBe( 'A' );
		await context.close();
	} );
} );

test.describe( 'block markup and shortcode', () => {
	test( 'the same options give the same attributes and the same layout', async ( { browser } ) => {
		const viewport = { width: 1440, height: 900 };
		const fromShortcode = await openEmbed(
			browser,
			publishPost( 'Options shortcode', shortcode( { layout: 'grid', columns: '3', gap: '4', ratio: '4-3', per_page: '10', lightbox: 'off', align: 'wide' } ) ),
			viewport
		);
		// Per page is a Show more setting, so only 10 tiles are drawn. A new block is wide, so the shortcode asks for wide too.
		const fromBlock = await openEmbed(
			browser,
			publishPost(
				'Options block',
				block( { layout: 'grid', columns: '3', gap: '4', ratio: '4-3', perPage: '10', lightbox: 'off' } )
			),
			viewport
		);
		await expectTiles( fromBlock.host, 10 );
		await expectTiles( fromShortcode.host, 10 );

		const attributes = ( /** @type {import('@playwright/test').Locator} */ host ) =>
			host.evaluate( ( el ) => Object.fromEntries( el.getAttributeNames().filter( ( name ) => name.startsWith( 'data-' ) && name !== 'data-pf-ready' && name !== 'data-pf-state' ).map( ( name ) => [ name, el.getAttribute( name ) ] ) ) );
		const expected = { 'data-profotograaf-gallery': 'g-e2e', 'data-layout': 'grid', 'data-columns': '3', 'data-columns-tablet': '3', 'data-columns-mobile': '2', 'data-gap': '4', 'data-ratio': '4:3', 'data-per-page': '10', 'data-lightbox': 'off' };
		expect( await attributes( fromShortcode.host ) ).toEqual( expected );
		expect( await attributes( fromBlock.host ) ).toEqual( expected );

		const a = await measure( fromShortcode.host );
		const b = await measure( fromBlock.host );
		expect( columnsOf( a ) ).toBe( 3 );
		expect( columnsOf( b ) ).toBe( 3 );
		expect( b.tiles.length ).toBe( a.tiles.length );
		b.tiles.forEach( ( tile, index ) => {
			expect( Math.abs( tile.width - a.tiles[ index ].width ) ).toBeLessThanOrEqual( CLOSE );
			expect( Math.abs( tile.height - a.tiles[ index ].height ) ).toBeLessThanOrEqual( CLOSE );
			expect( Math.abs( tile.left - a.tiles[ index ].left ) ).toBeLessThanOrEqual( CLOSE );
		} );
		await fromShortcode.host.locator( '.photos .tile' ).first().click();
		await expect( fromShortcode.host.getByRole( 'dialog' ) ).toHaveCount( 0 );
		expect( fromShortcode.problems ).toEqual( [] );
		expect( fromBlock.problems ).toEqual( [] );
		await fromShortcode.context.close();
		await fromBlock.context.close();
	} );
} );

test.describe( 'photos left out', () => {
	// embed.js reads data-exclude (ids split on commas and spaces) and draws the
	// other photos. Tiles are named after the photo title, Photo 1 to Photo 19.
	test( 'the block and the shortcode hide the photos they leave out', async ( { browser } ) => {
		const viewport = { width: 1440, height: 900 };
		const fromBlock = await openEmbed(
			browser,
			publishPost( 'Exclude block', block( { excludedPhotoIds: [ 'p-2', 'p-19' ] } ) ),
			viewport
		);
		const fromShortcode = await openEmbed( browser, publishPost( 'Exclude shortcode', shortcode( { exclude: 'p-1, p-3' } ) ), viewport );

		await expect( fromBlock.host ).toHaveAttribute( 'data-exclude', 'p-2,p-19' );
		await expect( fromShortcode.host ).toHaveAttribute( 'data-exclude', 'p-1,p-3' );
		await expectTiles( fromBlock.host, TOTAL - 2 );
		await expectTiles( fromShortcode.host, TOTAL - 2 );
		const names = ( /** @type {import('@playwright/test').Locator} */ host ) =>
			host.locator( '.photos .tile' ).evaluateAll( ( tiles ) => tiles.map( ( tile ) => tile.getAttribute( 'aria-label' ) ) );
		const blockNames = await names( fromBlock.host );
		const shortcodeNames = await names( fromShortcode.host );
		expect( blockNames ).not.toContain( 'Photo 2' );
		expect( blockNames ).not.toContain( 'Photo 19' );
		expect( blockNames ).toContain( 'Photo 1' );
		expect( shortcodeNames ).not.toContain( 'Photo 1' );
		expect( shortcodeNames ).not.toContain( 'Photo 3' );
		expect( shortcodeNames ).toContain( 'Photo 2' );
		expect( fromBlock.problems ).toEqual( [] );
		expect( fromShortcode.problems ).toEqual( [] );
		await fromBlock.context.close();
		await fromShortcode.context.close();
	} );
} );
