// @ts-check
// The real embed.js (tests/e2e/fixtures/embed.js) on a published page of the
// default block theme. These tests guard the box model between the plugin's
// host div, the gallery embed.js draws inside it and the theme footer. They
// compare boxes with each other and never against a pixel value.
const { test, expect } = require( '@playwright/test' );
const { publishPost, shortcode, openEmbed, expectTiles, measure } = require( './embed-helpers' );

const WIDTHS = [ 390, 820, 1440 ];
const LAYOUTS = [ 'masonry', 'grid' ];
const TOTAL = 19;
const PER_PAGE = 16;
const TOLERANCE = 0.5;

/**
 * @param {Awaited<ReturnType<typeof measure>>} box
 * @param {string} when
 */
function expectContained( box, when ) {
	expect( Number.isFinite( box.footerTop ), `${ when }: the theme has no footer element to compare with` ).toBe( true );
	expect( box.hostBottom, `${ when }: the host box ends above its last tile` ).toBeGreaterThanOrEqual( box.lastTileBottom - TOLERANCE );
	expect( box.hostBottom, `${ when }: the host box ends above the Show more button` ).toBeGreaterThanOrEqual( box.moreBottom - TOLERANCE );
	expect( box.footerTop, `${ when }: the footer starts inside the host box` ).toBeGreaterThanOrEqual( box.hostBottom - TOLERANCE );
	expect( box.scrollWidth, `${ when }: the page scrolls sideways` ).toBeLessThanOrEqual( box.innerWidth );
}

test.describe( 'embed.js layout with the real script', () => {
	/** @type {Record<string,string>} */
	const urls = {};

	test.beforeAll( () => {
		for ( const layout of LAYOUTS ) {
			urls[ layout ] = publishPost( `Layout ${ layout }`, shortcode( { layout, per_page: String( PER_PAGE ) } ) );
		}
	} );

	for ( const layout of LAYOUTS ) {
		for ( const width of WIDTHS ) {
			test( `${ layout } at ${ width }px keeps the host box around its tiles before and after Show more`, async ( { browser } ) => {
				const { context, host, problems } = await openEmbed( browser, urls[ layout ], { width, height: 900 } );
				await expectTiles( host, PER_PAGE );
				expectContained( await measure( host ), 'first page' );

				await host.getByRole( 'button', { name: 'Show more' } ).click();
				await expectTiles( host, TOTAL );
				expectContained( await measure( host ), 'after Show more' );

				expect( problems ).toEqual( [] );
				await context.close();
			} );
		}

		test( `${ layout } stays contained when the viewport is resized`, async ( { browser } ) => {
			const { context, page, host } = await openEmbed( browser, urls[ layout ], { width: 1440, height: 900 } );
			await expectTiles( host, PER_PAGE );
			await host.getByRole( 'button', { name: 'Show more' } ).click();
			await expectTiles( host, TOTAL );

			for ( const width of [ 390, 820, 1440 ] ) {
				await page.setViewportSize( { width, height: 900 } );
				await expect.poll( () => page.evaluate( () => window.innerWidth ) ).toBe( width );
				await expect
					.poll( async () => {
						const box = await measure( host );
						return box.hostBottom >= box.lastTileBottom - TOLERANCE && box.footerTop >= box.hostBottom - TOLERANCE;
					}, { message: `contained after resizing to ${ width }px` } )
					.toBe( true );
			}
			await context.close();
		} );
	}
} );

test.describe( 'per page and Show more', () => {
	test( 'shows 16 tiles first, then the rest, and hides the button', async ( { browser } ) => {
		const url = publishPost( 'Per page', shortcode( { per_page: String( PER_PAGE ) } ) );
		const { context, host, problems } = await openEmbed( browser, url, { width: 820, height: 900 } );
		const more = host.getByRole( 'button', { name: 'Show more' } );

		await expectTiles( host, PER_PAGE );
		await expect( more ).toBeVisible();

		await more.click();
		await expectTiles( host, TOTAL );
		await expect( more ).toBeHidden();

		const box = await measure( host );
		expect( box.scrollWidth ).toBeLessThanOrEqual( box.innerWidth );
		expect( problems ).toEqual( [] );
		await context.close();
	} );

	test( 'a gallery that fits on one page has no button', async ( { browser } ) => {
		const url = publishPost( 'Per page fits', shortcode( { per_page: '40' } ) );
		const { context, host } = await openEmbed( browser, url );

		await expectTiles( host, TOTAL );
		await expect( host.getByRole( 'button', { name: 'Show more' } ) ).toBeHidden();
		await context.close();
	} );

	// Platform gap: embed.js hides the button it was activated from and does not
	// move focus, so a keyboard user lands on the document body after Show more.
	// Remove the fixme once the platform focuses the first new tile.
	test.fixme( 'moves focus to a new tile after Show more', async ( { browser } ) => {
		const url = publishPost( 'Per page focus', shortcode( { per_page: String( PER_PAGE ) } ) );
		const { context, page, host } = await openEmbed( browser, url, { width: 820, height: 900 } );
		await expectTiles( host, PER_PAGE );

		await host.getByRole( 'button', { name: 'Show more' } ).focus();
		await page.keyboard.press( 'Enter' );
		await expectTiles( host, TOTAL );

		const focusInGallery = await host.evaluate( ( el ) => el.shadowRoot.activeElement !== null );
		expect( focusInGallery, 'focus fell back to the document body' ).toBe( true );
		await context.close();
	} );
} );
