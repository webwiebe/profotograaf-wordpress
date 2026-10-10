// @ts-check
// Checks on a gallery that embed.js drew, shared by the specs that run against
// the mock platform (gallery-display.spec.js) and the ones that run against a
// site connected to the real platform (sample-pages.spec.js). Playwright CSS
// locators pierce the open shadow root embed.js renders into, so `host.locator`
// finds the tiles, the lightbox and the slideshow controls.
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { expect, test } = require( '@playwright/test' );

/** The widths the review screenshots are taken at: desktop, tablet, phone. */
const WIDTHS = { desktop: 1280, tablet: 768, mobile: 390 };

/** The reserved space is given back after this many seconds (Reserved_Space::RELEASE_AFTER), plus a margin. */
const RELEASE_MS = 8_000 + 4_000;

/**
 * Waits until the gallery drew at least `min` tiles and every tile image that
 * is in view has loaded.
 *
 * @param {import('@playwright/test').Locator} host
 * @param {number} min
 */
async function expectPhotos( host, min = 1 ) {
	await host.scrollIntoViewIfNeeded();
	await expect.poll( () => host.locator( '.tile img' ).count(), { message: 'tiles drawn', timeout: 20_000 } ).toBeGreaterThanOrEqual( min );
	const first = host.locator( '.tile img' ).first();
	await first.scrollIntoViewIfNeeded();
	await expect.poll( () => first.evaluate( ( img ) => /** @type {HTMLImageElement} */ ( img ).naturalWidth ), { message: 'first photo loaded', timeout: 20_000 } ).toBeGreaterThan( 0 );
}

/**
 * Opens the lightbox on the first tile, steps with the arrow keys and the
 * buttons, closes it with Escape and checks focus goes back to the tile.
 *
 * @param {import('@playwright/test').Page}    page
 * @param {import('@playwright/test').Locator} host
 */
async function checkLightbox( page, host ) {
	const tile = host.locator( '.tile' ).first();
	const total = await host.evaluate( ( el ) => el.shadowRoot?.querySelectorAll( '.photos .tile, .dots .dot' ).length ?? 0 );
	await tile.click();
	const box = host.locator( '.lb' );
	await expect( box ).toBeVisible();
	await expect( box ).toHaveAttribute( 'role', 'dialog' );
	await expect.poll( () => box.locator( 'img' ).evaluate( ( img ) => /** @type {HTMLImageElement} */ ( img ).naturalWidth ) ).toBeGreaterThan( 0 );
	const count = box.locator( '.count' );
	await expect( count ).toHaveText( new RegExp( `^1 / \\d+$` ) );
	expect( await page.evaluate( () => document.body.style.overflow ) ).toBe( 'hidden' );
	if ( total > 1 ) {
		await page.keyboard.press( 'ArrowRight' );
		await expect( count ).toHaveText( /^2 \/ / );
		await page.keyboard.press( 'ArrowLeft' );
		await expect( count ).toHaveText( /^1 \/ / );
		await box.locator( 'button.n' ).click();
		await expect( count ).toHaveText( /^2 \/ / );
		await box.locator( 'button.p' ).click();
		await expect( count ).toHaveText( /^1 \/ / );
	}
	await page.keyboard.press( 'Escape' );
	await expect( box ).toHaveCount( 0 );
	expect( await page.evaluate( () => document.body.style.overflow ) ).toBe( '' );
	expect( await host.evaluate( ( el ) => el.shadowRoot?.activeElement?.classList.contains( 'tile' ) ) ).toBe( true );

	await tile.click();
	await host.locator( '.lb button.x' ).click();
	await expect( box ).toHaveCount( 0 );
}

/**
 * The index of the current slide, from the dot marked current.
 *
 * @param {import('@playwright/test').Locator} host
 */
function currentSlide( host ) {
	return host.evaluate( ( el ) => [ ...( el.shadowRoot?.querySelectorAll( '.dot' ) ?? [] ) ].findIndex( ( dot ) => dot.getAttribute( 'aria-current' ) === 'true' ) );
}

/**
 * Steps a slideshow with its buttons, its dots and the arrow keys.
 *
 * @param {import('@playwright/test').Page}    page
 * @param {import('@playwright/test').Locator} host
 */
async function checkSlideshow( page, host ) {
	await expect( host.locator( '.slides' ) ).toHaveAttribute( 'aria-roledescription', 'carousel' );
	await expect( host.locator( '.stage .tile' ) ).toHaveCount( 1 );
	const dots = await host.locator( '.dot' ).count();
	expect( dots, 'one dot per photo' ).toBeGreaterThan( 1 );
	expect( await currentSlide( host ) ).toBe( 0 );

	await host.locator( 'button.next' ).click();
	expect( await currentSlide( host ) ).toBe( 1 );
	await host.locator( 'button.prev' ).click();
	expect( await currentSlide( host ) ).toBe( 0 );
	await host.locator( 'button.prev' ).click();
	expect( await currentSlide( host ), 'prev on the first slide wraps to the last' ).toBe( dots - 1 );
	await host.locator( '.dot' ).nth( 1 ).click();
	expect( await currentSlide( host ) ).toBe( 1 );

	await host.locator( 'button.next' ).focus();
	await page.keyboard.press( 'ArrowRight' );
	expect( await currentSlide( host ) ).toBe( 2 % dots );
	await page.keyboard.press( 'ArrowLeft' );
	expect( await currentSlide( host ) ).toBe( 1 );
	await expect.poll( () => host.locator( '.stage img' ).evaluate( ( img ) => /** @type {HTMLImageElement} */ ( img ).naturalWidth ) ).toBeGreaterThan( 0 );
}

/**
 * Clicks Show more until it is gone and checks every click adds tiles.
 *
 * @param {import('@playwright/test').Locator} host
 * @param {number} perPage
 * @return {Promise<number>} The tile count at the end.
 */
async function checkLoadMore( host, perPage ) {
	const tiles = host.locator( '.photos .tile' );
	await expect( tiles ).toHaveCount( perPage );
	const more = host.getByRole( 'button', { name: 'Show more' } );
	await expect( more ).toBeVisible();
	let shown = perPage;
	while ( await more.isVisible() ) {
		await more.click();
		await expect.poll( () => tiles.count() ).toBeGreaterThan( shown );
		shown = await tiles.count();
	}
	return shown;
}

/**
 * The state of a gallery embed.js did not draw: no shadow root content, the
 * fallback link still there and, once the reservation is released, no tall
 * blank box.
 *
 * @param {import('@playwright/test').Locator} host
 */
async function expectFallback( host ) {
	await host.scrollIntoViewIfNeeded();
	await expect( host ).toHaveAttribute( 'data-pf-ready', '' );
	expect( await host.evaluate( ( el ) => el.shadowRoot?.querySelectorAll( '.tile' ).length ?? 0 ) ).toBe( 0 );
	await expect( host.locator( ':scope > a' ) ).toBeVisible();
	await expect
		.poll( () => host.evaluate( ( el ) => el.getBoundingClientRect().height ), { message: 'the blank box is released', timeout: RELEASE_MS } )
		.toBeLessThan( 120 );
}

/**
 * Where screenshots go: E2E_SCREENSHOT_DIR when set (the review run), else the
 * test's own output folder.
 *
 * @param {string} name
 */
function screenshotPath( name ) {
	const dir = process.env.E2E_SCREENSHOT_DIR;
	if ( ! dir ) {
		return test.info().outputPath( `${ name }.png` );
	}
	fs.mkdirSync( dir, { recursive: true } );
	return path.join( dir, `${ name }.png` );
}

/**
 * Screenshots a gallery, or a page (the whole page, or the viewport with
 * `fullPage` false), and attaches it to the report.
 *
 * @param {import('@playwright/test').Page|import('@playwright/test').Locator} target
 * @param {string} name
 * @param {boolean} fullPage
 */
async function shoot( target, name, fullPage = true ) {
	const file = screenshotPath( name );
	if ( 'goto' in target ) {
		await target.screenshot( { path: file, fullPage, animations: 'disabled' } );
	} else {
		await target.screenshot( { path: file, animations: 'disabled' } );
	}
	await test.info().attach( name, { path: file, contentType: 'image/png' } );
}

/**
 * Scrolls the page from top to bottom so every lazy image loads, then back.
 *
 * @param {import('@playwright/test').Page} page
 */
async function loadLazyImages( page ) {
	const height = await page.evaluate( () => document.documentElement.scrollHeight );
	for ( let y = 0; y < height; y += 500 ) {
		await page.evaluate( ( top ) => window.scrollTo( 0, top ), y );
		await page.waitForTimeout( 100 );
	}
	await page.waitForLoadState( 'networkidle' );
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
}

module.exports = { WIDTHS, expectPhotos, checkLightbox, checkSlideshow, checkLoadMore, expectFallback, shoot, loadLazyImages, currentSlide };
