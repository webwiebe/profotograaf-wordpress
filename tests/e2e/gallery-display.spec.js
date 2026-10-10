// @ts-check
// How a gallery looks and behaves on a published page, with the real embed.js
// (tests/e2e/fixtures/embed.js) and the mock platform. The same checks run
// against the real platform in sample-pages.spec.js; both use gallery-checks.js.
// See docs/gallery-display-matrix.md for the options and where each is applied.
const { test, expect } = require( '@playwright/test' );
const { publishPost, block, openEmbed, wp } = require( './embed-helpers' );
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
	urls.duotone = publishPost( 'Display duotone', block( { layout: 'grid', style: { color: { duotone: [ '#1a1a2e', '#f5c542' ] } } } ) );
	// The gallery list remembers the platform layout of a gallery, which the render reads
	// on the platform default. The mock lists g-e2e-parallax with layout `parallax`.
	wp(
		'eval',
		"$i = get_option( 'profotograaf_gallery_index', array() ); $i['g-e2e-parallax'] = array( 'title' => 'Sample parallax', 'url' => 'http://mock-platform:8090/share/g/sample-parallax', 'count' => 19, 'layout' => 'parallax', 'embed_layout' => '' ); update_option( 'profotograaf_gallery_index', $i, false );"
	);
	const parallax = { galleryId: 'g-e2e-parallax', galleryTitle: 'Sample parallax', galleryUrl: 'http://mock-platform:8090/share/g/sample-parallax' };
	urls.parallaxDefault = publishPost( 'Display parallax default', block( parallax ) );
	urls.parallaxGrid = publishPost( 'Display parallax grid', block( { ...parallax, layout: 'grid' } ) );
	urls.duotonePreset = publishPost( 'Display duotone preset', block( { layout: 'grid', style: { color: { duotone: 'var:preset|duotone|dark-grayscale' } } } ) );
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

	test( 'a parallax gallery on the platform default is drawn as a slideshow', async ( { browser } ) => {
		const { context, page, problems } = await openEmbed( browser, urls.parallaxDefault );
		const host = hostOf( page, 'g-e2e-parallax' );
		await expect( host ).toHaveAttribute( 'data-layout', 'slideshow' );
		await expectPhotos( host, 1 );
		await checkSlideshow( page, host );
		expect( problems ).toEqual( [] );
		await context.close();
	} );

	test( 'a block that picks the grid keeps it for a parallax gallery', async ( { browser } ) => {
		const { context, page, problems } = await openEmbed( browser, urls.parallaxGrid );
		const host = hostOf( page, 'g-e2e-parallax' );
		await expect( host ).toHaveAttribute( 'data-layout', 'grid' );
		await expectPhotos( host, TOTAL );
		await expect( host.locator( '.slides' ) ).toHaveCount( 0 );
		expect( problems ).toEqual( [] );
		await context.close();
	} );

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

	for ( const [ name, colours ] of [ [ 'duotone', '#1a1a2e,#f5c542' ], [ 'duotonePreset', '#000000,#7f7f7f' ] ] ) {
		test( `a block duotone (${ name }) puts the pf-duo filter on every tile image`, async ( { browser } ) => {
			const { context, host, problems } = await openEmbed( browser, urls[ name ] );
			await expectPhotos( host, TOTAL );
			await expect( host ).toHaveAttribute( 'data-duotone', colours );
			await expect( host.locator( 'svg filter#pf-duo' ) ).toHaveCount( 1 );
			const filters = await host.locator( '.photos .tile img' ).evaluateAll( ( images ) => images.map( ( img ) => getComputedStyle( img ).filter ) );
			expect( filters ).toHaveLength( TOTAL );
			for ( const filter of filters ) {
				expect( filter ).toContain( '#pf-duo' );
			}
			expect( await host.evaluate( ( el ) => el.querySelectorAll( 'img' ).length ), 'no photo in the light DOM for the WordPress filter to hit' ).toBe( 0 );
			expect( problems ).toEqual( [] );
			await context.close();
		} );
	}

} );
