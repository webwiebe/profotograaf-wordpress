// @ts-check
// Layout shift budget for the gallery the real embed.js draws. The plugin
// reserves an aspect ratio close to the drawn gallery (Reserved_Space), so the
// page should not jump when the script replaces the placeholder. The
// layout-shift entry type exists in chromium only.
const { test, expect } = require( '@playwright/test' );
const { publishPost, shortcode, openEmbed, expectTiles, measure } = require( './embed-helpers' );

const BUDGET = 0.1;
const TOTAL = 19;
const PER_PAGE = 16;

/**
 * Records layout shifts in window.__cls from before the page scripts run.
 *
 * @param {import('@playwright/test').BrowserContext} ctx
 */
function recordLayoutShifts( ctx ) {
	return ctx.addInitScript( () => {
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
	} );
}

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
				const { context, page, host } = await openEmbed( browser, urls[ layout ], { width, height: 900 }, recordLayoutShifts );
				await expectTiles( host, PER_PAGE );
				await host.getByRole( 'button', { name: 'Show more' } ).click();
				await expectTiles( host, TOTAL );
				await page.waitForLoadState( 'networkidle' );
				// Layout shifts are reported after the frame that caused them.
				await page.evaluate( () => new Promise( ( resolve ) => requestAnimationFrame( () => requestAnimationFrame( resolve ) ) ) );

				// @ts-ignore
				const cls = await page.evaluate( () => window.__cls );
				console.log( `CLS ${ layout } ${ width }px: ${ cls.value.toFixed( 4 ) } (${ cls.entries.length } shifts: ${ cls.entries.map( ( v ) => v.toFixed( 4 ) ).join( ", " ) })` );
				expect( cls.value, `CLS ${ cls.value } over the ${ BUDGET } budget` ).toBeLessThan( BUDGET );
				await context.close();
			} );
		}
	}
	// embed.js picks its columns by the width of its container, not the
	// viewport (wiebe-xyz/professionals#2487), and the reserved space follows
	// the same widths. A theme column of about 650px on a 1440px screen draws 3
	// of 4 columns, and the page must not move when they appear.
	test( 'a narrow content column on a wide screen draws 3 of 4 columns without a shift', async ( { browser } ) => {
		const url = publishPost( 'CLS narrow column', shortcode( { layout: 'grid', columns: '4', per_page: String( PER_PAGE ) } ) );
		const { context, page, host } = await openEmbed( browser, url, { width: 1440, height: 900 }, async ( ctx ) => {
			await recordLayoutShifts( ctx );
			// The height of the host in the last frame before embed.js reports a state.
			return ctx.addInitScript( () => {
				const frame = () => {
					const el = document.querySelector( '[data-profotograaf-gallery]' );
					if ( el && ! el.getAttribute( 'data-pf-state' ) ) {
						// @ts-ignore Test-only global read back below.
						window.__reserved = el.getBoundingClientRect().height;
					}
					requestAnimationFrame( frame );
				};
				requestAnimationFrame( frame );
			} );
		} );
		await expectTiles( host, PER_PAGE );
		await page.waitForLoadState( 'networkidle' );
		await page.evaluate( () => new Promise( ( resolve ) => requestAnimationFrame( () => requestAnimationFrame( resolve ) ) ) );

		const { tiles } = await measure( host );
		const columns = new Set( tiles.map( ( tile ) => Math.round( tile.left ) ) ).size;
		const width = await host.evaluate( ( el ) => el.getBoundingClientRect().width );
		expect( width, 'the content column is narrower than 900px' ).toBeLessThanOrEqual( 900 );
		expect( columns ).toBe( 3 );

		// @ts-ignore
		const cls = await page.evaluate( () => window.__cls );
		expect( cls.value, `CLS ${ cls.value }` ).toBeLessThan( 0.02 );

		// The reserved box is within 15% of the drawn gallery.
		// @ts-ignore
		const reserved = await page.evaluate( () => window.__reserved );
		const drawn = await host.evaluate( ( el ) => el.getBoundingClientRect().height );
		expect( reserved, 'a box was reserved before embed.js drew' ).toBeGreaterThan( 0 );
		expect( Math.abs( reserved - drawn ) / drawn, `reserved ${ reserved }px, drawn ${ drawn }px` ).toBeLessThan( 0.15 );
		await context.close();
	} );
} );
