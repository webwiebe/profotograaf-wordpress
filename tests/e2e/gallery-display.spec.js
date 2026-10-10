// @ts-check
// How a gallery looks and behaves on a published page, with the real embed.js
// (tests/e2e/fixtures/embed.js) and the mock platform. The same checks run
// against the real platform in sample-pages.spec.js; both use gallery-checks.js.
// See docs/gallery-display-matrix.md for the options and where each is applied.
const { test, expect } = require( '@playwright/test' );
const { publishPost, block, openEmbed } = require( './embed-helpers' );
const { WIDTHS, expectPhotos, checkLightbox, checkSlideshow, checkLoadMore, expectFallback, shoot } = require( './gallery-checks' );

const TOTAL = 19;

test.skip( !! process.env.E2E_BASE_URL, 'needs the docker WordPress and mock platform of tests/e2e/up.sh' );

/**
 * The gallery div for one gallery id.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} id
 */
const hostOf = ( page, id ) => page.locator( `div[data-profotograaf-gallery="${ id }"]` );

/**
 * How many columns the drawn tiles form: the number of distinct left edges.
 *
 * @param {import('@playwright/test').Locator} host
 */
const columnsOf = ( host ) =>
	host.evaluate( ( el ) => new Set( [ ...el.shadowRoot.querySelectorAll( '.tile' ) ].map( ( tile ) => Math.round( tile.getBoundingClientRect().left ) ) ).size );

/** @type {Record<string,string>} */
const urls = {};

test.beforeAll( () => {
	urls.grid = publishPost( 'Display grid', block( { layout: 'grid', lightbox: 'on' } ) );
	urls.masonry = publishPost( 'Display masonry', block( { layout: 'masonry' } ) );
	urls.slideshow = publishPost( 'Display slideshow', block( { layout: 'slideshow', lightbox: 'on' } ) );
	urls.loadMore = publishPost( 'Display load more', block( { layout: 'grid', perPage: '8', loadMore: 'on' } ) );
	urls.fiveColumns = publishPost( 'Display five columns', block( { layout: 'grid', columns: '5', perPage: '10' } ) );
	urls.masonryFour = publishPost( 'Display masonry four columns', block( { layout: 'masonry', columns: '4', perPage: '10' } ) );
	urls.phoneThree = publishPost( 'Display phone three', block( { layout: 'grid', columns: '5', columnsMobile: '3', perPage: '10' } ) );
} );

test.describe( 'gallery display with the mock platform', () => {
	for ( const layout of [ 'grid', 'masonry', 'slideshow' ] ) {
		test( `${ layout } draws its photos inside the shadow root at every width`, async ( { browser } ) => {
			const { context, page, host, problems } = await openEmbed( browser, urls[ layout ] );
			await expectPhotos( host, layout === 'slideshow' ? 1 : TOTAL );
			expect( await host.evaluate( ( el ) => el.querySelectorAll( 'img' ).length ), 'no photo in the light DOM' ).toBe( 0 );
			for ( const width of Object.values( WIDTHS ) ) {
				await page.setViewportSize( { width, height: 900 } );
				await host.scrollIntoViewIfNeeded();
				await shoot( host, `mock-${ layout }-${ width }` );
			}
			expect( problems ).toEqual( [] );
			await context.close();
		} );
	}

	test( 'a new block is wide by default', async ( { browser } ) => {
		const { context, host } = await openEmbed( browser, urls.grid );
		await expect( host ).toHaveClass( /alignwide/ );
		await context.close();
	} );

	test( 'a 5 column grid with no tablet or phone value steps down to 3 and 2 columns', async ( { browser } ) => {
		const { context, page, host, problems } = await openEmbed( browser, urls.fiveColumns, { width: 1440, height: 900 } );
		await expectPhotos( host, 10 );
		expect( await columnsOf( host ), 'desktop' ).toBe( 5 );
		await page.setViewportSize( { width: 800, height: 900 } );
		await expect.poll( () => columnsOf( host ), { message: 'tablet' } ).toBe( 3 );
		await page.setViewportSize( { width: 390, height: 900 } );
		await expect.poll( () => columnsOf( host ), { message: 'phone' } ).toBe( 2 );
		expect( problems ).toEqual( [] );
		await context.close();
	} );

	test( 'a 4 column masonry gallery with no phone value draws 1 column at 390 px', async ( { browser } ) => {
		const { context, host } = await openEmbed( browser, urls.masonryFour, { width: 390, height: 900 } );
		await expectPhotos( host, 10 );
		await expect.poll( () => columnsOf( host ) ).toBe( 1 );
		await context.close();
	} );

	test( 'an explicit phone value wins over the step down', async ( { browser } ) => {
		const { context, host } = await openEmbed( browser, urls.phoneThree, { width: 390, height: 900 } );
		await expectPhotos( host, 10 );
		await expect.poll( () => columnsOf( host ) ).toBe( 3 );
		await context.close();
	} );

	test( 'lightbox opens on a tile, steps with keys and buttons, and closes', async ( { browser } ) => {
		const { context, page, host, problems } = await openEmbed( browser, urls.grid );
		await expectPhotos( host, TOTAL );
		await checkLightbox( page, host );
		expect( problems ).toEqual( [] );
		await context.close();
	} );

	test( 'slideshow steps with buttons, dots and arrow keys and opens the lightbox', async ( { browser } ) => {
		const { context, page, host, problems } = await openEmbed( browser, urls.slideshow );
		await expectPhotos( host );
		await checkSlideshow( page, host );
		await host.locator( '.dot' ).first().click();
		await checkLightbox( page, host );
		expect( problems ).toEqual( [] );
		await context.close();
	} );

	test( 'Show more adds pages of 8 until all photos show', async ( { browser } ) => {
		const { context, host } = await openEmbed( browser, urls.loadMore );
		await expectPhotos( host );
		expect( await checkLoadMore( host, 8 ) ).toBe( TOTAL );
		await context.close();
	} );

	test( 'a failed platform call keeps the fallback link and gives its space back', async ( { browser } ) => {
		const { context, page } = await openEmbed( browser, urls.grid, undefined, async ( ctx ) => {
			await ctx.route( '**/api/v1/embed/galleries/g-e2e', ( route ) => route.fulfill( { status: 503, body: '{}' } ) );
		} );
		await expectFallback( hostOf( page, 'g-e2e' ) );
		await context.close();
	} );
} );
