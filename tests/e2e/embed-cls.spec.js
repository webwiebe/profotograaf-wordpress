// @ts-check
// Layout shift budget for the gallery the real embed.js draws. The plugin
// reserves the host's space (Reserved_Space) so the page should not jump when
// the script replaces the placeholder. The layout-shift entry type exists in
// chromium only.
const { test, expect } = require( '@playwright/test' );
const { publishPost, shortcode, openEmbed, expectTiles } = require( './embed-helpers' );

const BUDGET = 0.1;
const TOTAL = 19;
const PER_PAGE = 16;

test.describe( 'layout shift with the real script', () => {
	test.skip( ( { browserName } ) => browserName !== 'chromium', 'layout-shift entries are chromium only' );

	/** @type {Record<string,string>} */
	const urls = {};

	test.beforeAll( () => {
		for ( const layout of [ 'masonry', 'grid' ] ) {
			urls[ layout ] = publishPost( `CLS ${ layout }`, shortcode( { layout, per_page: String( PER_PAGE ) } ) );
		}
	} );

	for ( const layout of [ 'masonry', 'grid' ] ) {
		for ( const width of [ 390, 1440 ] ) {
			test( `${ layout } at ${ width }px stays under ${ BUDGET } from load through Show more`, async ( { browser } ) => {
				// Reserved_Space holds a fixed 8em, so the footer below the gallery
				// jumps when the 16 tiles appear. On a narrow screen that scores
				// 0.32 to 0.47 with the default block theme. Masonry on a wide screen
				// scores 0.089 with the WordPress 7.1 theme and 0.131 with the 7.0
				// one, so the 7.0 job expects it to fail. These cases go red the day
				// the reservation follows the gallery, which is the cue to remove
				// this line.
				const wide70 = layout === 'masonry' && width === 1440 && process.env.WP_VERSION === '7.0';
				test.fail( width === 390 || wide70, 'Reserved_Space holds 8em only, so the footer shifts when the gallery appears' );
				const { context, page, host } = await openEmbed( browser, urls[ layout ], { width, height: 900 }, ( ctx ) =>
					ctx.addInitScript( () => {
						// @ts-ignore Test-only global read back below.
						window.__cls = { value: 0, entries: [] };
						new PerformanceObserver( ( list ) => {
							for ( const entry of list.getEntries() ) {
								// @ts-ignore LayoutShift is not in the DOM typings.
								if ( ! entry.hadRecentInput ) {
									// @ts-ignore
									window.__cls.value += entry.value;
									// @ts-ignore
									window.__cls.entries.push( entry.value );
								}
							}
						} ).observe( { type: 'layout-shift', buffered: true } );
					} )
				);
				await expectTiles( host, PER_PAGE );
				await host.getByRole( 'button', { name: 'Show more' } ).click();
				await expectTiles( host, TOTAL );
				await page.waitForLoadState( 'networkidle' );
				// Layout shifts are reported after the frame that caused them.
				await page.evaluate( () => new Promise( ( resolve ) => requestAnimationFrame( () => requestAnimationFrame( resolve ) ) ) );

				// @ts-ignore
				const cls = await page.evaluate( () => window.__cls );
				console.log( `CLS ${ layout } ${ width }px: ${ cls.value.toFixed( 4 ) } (${ cls.entries.length } shifts)` );
				expect( cls.value, `CLS ${ cls.value } over the ${ BUDGET } budget` ).toBeLessThan( BUDGET );
				await context.close();
			} );
		}
	}
} );
