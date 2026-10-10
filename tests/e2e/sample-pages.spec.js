// @ts-check
// The sample pages (tests/e2e/sample-pages.js) on a site connected to the real
// platform, such as the local dev site:
//
//   E2E_BASE_URL=https://localhost:8443 pnpm exec playwright test sample-pages --workers=2
//
// Every test skips when the base URL has no sample pages, so the CI run against
// the docker WordPress (which has none) stays green. E2E_SCREENSHOT_DIR collects
// the review screenshots at desktop, tablet and phone width in one folder.
const { test, expect } = require( '@playwright/test' );
const { PAGES } = require( './sample-pages' );
const { WIDTHS, expectPhotos, checkLightbox, checkSlideshow, checkLoadMore, expectFallback, shoot, loadLazyImages } = require( './gallery-checks' );

/**
 * The gallery div of one sample block.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} anchor
 */
const gallery = ( page, anchor ) => page.locator( `#${ anchor }[data-profotograaf-gallery]` );

/** @type {Map<string, boolean>} */
const present = new Map();

/**
 * Opens a sample page, or skips the test when the site does not have it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} slug
 */
async function openSample( page, slug ) {
	if ( present.get( slug ) === false ) {
		test.skip( true, `no /${ slug }/ page on ${ test.info().project.use.baseURL }` );
	}
	const response = await page.goto( `/${ slug }/` );
	// A site without pretty permalinks answers any path with its front page,
	// so the page counts as present only when it holds its own first gallery.
	const first = PAGES.find( ( candidate ) => candidate.slug === slug )?.blocks[ 0 ].anchor;
	const has = !! response && response.ok() && ( await gallery( page, String( first ) ).count() ) > 0;
	present.set( slug, has );
	test.skip( ! has, `no /${ slug }/ page with galleries on this site; run tests/e2e/sample-pages.js first` );
}

for ( const sample of PAGES ) {
	test.describe( sample.title, () => {
		test( 'every gallery draws its photos or its fallback', async ( { page } ) => {
			await openSample( page, sample.slug );
			for ( const block of sample.blocks ) {
				const host = gallery( page, block.anchor );
				await expect( host, block.anchor ).toHaveCount( 1 );
				if ( block.expect === 'empty' ) {
					await expectFallback( host );
				} else if ( block.expect !== 'png' ) {
					await expectPhotos( host );
				}
			}
		} );

		for ( const [ name, width ] of Object.entries( WIDTHS ) ) {
			test( `screenshots at ${ name } ${ width }px`, async ( { page } ) => {
				test.slow();
				await page.setViewportSize( { width, height: 900 } );
				await openSample( page, sample.slug );
				await loadLazyImages( page );
				await shoot( page, `${ sample.slug }-${ width }` );
				for ( const block of sample.blocks ) {
					const host = gallery( page, block.anchor );
					await host.scrollIntoViewIfNeeded();
					await shoot( host, `${ block.anchor }-${ width }` );
				}
			} );
		}
	} );
}

test.describe( 'interaction on the real platform', () => {
	test( 'lightbox opens, steps and closes with mouse and keyboard', async ( { page } ) => {
		await openSample( page, 'sample-grid' );
		const host = gallery( page, 'grid-columns' );
		await expectPhotos( host, 2 );
		await checkLightbox( page, host );
	} );

	test( 'lightbox screenshots at every width', async ( { page } ) => {
		await openSample( page, 'sample-grid' );
		for ( const [ name, width ] of Object.entries( WIDTHS ) ) {
			await page.setViewportSize( { width, height: name === 'mobile' ? 844 : 900 } );
			const host = gallery( page, 'grid-columns' );
			await expectPhotos( host );
			await host.locator( '.tile' ).first().click();
			await expect.poll( () => host.locator( '.lb img' ).evaluate( ( img ) => /** @type {HTMLImageElement} */ ( img ).naturalWidth ) ).toBeGreaterThan( 0 );
			await shoot( page, `lightbox-${ width }`, false );
			await page.keyboard.press( 'Escape' );
		}
	} );

	test( 'slideshow steps with buttons, dots and arrow keys', async ( { page } ) => {
		await openSample( page, 'sample-slideshow' );
		const host = gallery( page, 'slideshow-large' );
		await expectPhotos( host );
		await checkSlideshow( page, host );
	} );

	test( 'Show more adds the next page until the gallery is complete', async ( { page } ) => {
		await openSample( page, 'sample-combinations' );
		const host = gallery( page, 'combo-load-more' );
		await expectPhotos( host );
		expect( await checkLoadMore( host, 8 ) ).toBeGreaterThan( 8 );
	} );

	test( 'per page without Show more shows the first page only', async ( { page } ) => {
		await openSample( page, 'sample-combinations' );
		const host = gallery( page, 'combo-first-page' );
		await expectPhotos( host );
		await expect( host.locator( '.photos .tile' ) ).toHaveCount( 6 );
		await expect( host.getByRole( 'button', { name: 'Show more' } ) ).toHaveCount( 0 );
	} );

	test( 'with the lightbox off a photo links to its page', async ( { page } ) => {
		await openSample( page, 'sample-combinations' );
		const host = gallery( page, 'combo-link-page' );
		await expectPhotos( host );
		const link = host.locator( 'a.tile' ).first();
		await expect( link ).toHaveAttribute( 'href', /^https:\/\/.+\/photo\// );
		await expect( link ).toHaveAttribute( 'target', '_blank' );
	} );

	test( 'a failed platform call leaves the fallback link and no blank box', async ( { page } ) => {
		await page.route( '**/api/v1/embed/galleries/**', ( route ) => route.fulfill( { status: 503, body: '{}' } ) );
		await openSample( page, 'sample-grid' );
		await expectFallback( gallery( page, 'grid-few' ) );
	} );

	test( 'a gallery of PNG photos draws them (professionals#2330)', async ( { page } ) => {
		await openSample( page, 'sample-combinations' );
		await expectPhotos( gallery( page, 'combo-png' ) );
	} );
} );
