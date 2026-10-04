// @ts-check
// Accessibility of the gallery the real embed.js draws (tests/e2e/fixtures/embed.js):
// axe on the first page, after Show more and with the lightbox open, plus the
// keyboard path to Show more. Axe is scoped to the plugin's host div because
// the active theme is outside this repository. Axe pierces the open shadow root
// embed.js draws into.
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { HOST, publishPost, shortcode, openEmbed, expectTiles } = require( './embed-helpers' );

const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag22aa' ];
const TOTAL = 19;
const PER_PAGE = 16;
const WIDTHS = [ 390, 1440 ];

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} when
 */
async function expectNoViolations( page, when ) {
	const results = await new AxeBuilder( { page } ).include( HOST ).withTags( TAGS ).analyze();
	const summary = results.violations.map( ( violation ) => ( {
		rule: violation.id,
		impact: violation.impact,
		help: violation.help,
		targets: violation.nodes.map( ( node ) => node.target.join( ' ' ) ).slice( 0, 3 ),
	} ) );
	expect( summary, `${ when }: axe found violations` ).toEqual( [] );
}

test.describe( 'accessibility with the real script', () => {
	/** @type {Record<string,string>} */
	const urls = {};

	test.beforeAll( () => {
		for ( const layout of [ 'masonry', 'grid' ] ) {
			urls[ layout ] = publishPost( `A11y ${ layout }`, shortcode( { layout, per_page: String( PER_PAGE ), lightbox: 'on' } ) );
		}
	} );

	for ( const layout of [ 'masonry', 'grid' ] ) {
		for ( const width of WIDTHS ) {
			test( `${ layout } at ${ width }px has no axe violations before and after Show more`, async ( { browser } ) => {
				const { context, page, host } = await openEmbed( browser, urls[ layout ], { width, height: 900 } );
				await expectTiles( host, PER_PAGE );
				await expectNoViolations( page, 'first page' );

				await host.getByRole( 'button', { name: 'Show more' } ).click();
				await expectTiles( host, TOTAL );
				await expectNoViolations( page, 'after Show more' );
				await context.close();
			} );

			test( `${ layout } at ${ width }px has no axe violations with the lightbox open`, async ( { browser } ) => {
				const { context, page, host } = await openEmbed( browser, urls[ layout ], { width, height: 900 } );
				await expectTiles( host, PER_PAGE );
				await host.locator( '.photos .tile' ).first().click();
				await expect( host.getByRole( 'dialog' ) ).toBeVisible();
				await expectNoViolations( page, 'lightbox open' );
				await context.close();
			} );
		}

		test( `${ layout } Show more is reachable with Tab and activates with Enter`, async ( { browser } ) => {
			const { context, page, host } = await openEmbed( browser, urls[ layout ], { width: 1440, height: 900 } );
			await expectTiles( host, PER_PAGE );
			const more = host.getByRole( 'button', { name: 'Show more' } );

			// Tab through the page until the focus lands on the button. The tiles
			// come first, so the loop is bounded by the tile count plus the theme.
			let reached = false;
			for ( let presses = 0; presses < 80 && ! reached; presses++ ) {
				await page.keyboard.press( 'Tab' );
				reached = await more.evaluate( ( button ) => button.getRootNode().activeElement === button );
			}
			expect( reached, 'Tab never reached the Show more button' ).toBe( true );
			await expect( more ).toBeFocused();

			await page.keyboard.press( 'Enter' );
			await expectTiles( host, TOTAL );
			await context.close();
		} );
	}
} );
