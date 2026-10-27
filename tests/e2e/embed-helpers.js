// @ts-check
// Shared by the specs that run the real embed.js (tests/e2e/fixtures/embed.js).
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { expect, test } = require( '@playwright/test' );
const { MOCK } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );

// WordPress points the script and the API at this host, which only the
// containers resolve. The browser reaches the same mock on the published port.
const PLATFORM_HOST = /^https?:\/\/mock-platform:8090(\/.*)$/;

// embed.js takes its API origin from the script tag's data-platform only when
// that is an https origin, and otherwise from the origin that served the
// script, which is the WordPress site. The mock answers over http inside the
// containers, so the browser is shown the https spelling; PLATFORM_HOST maps
// both spellings to the mock.
const HTTP_PLATFORM_ATTRIBUTE = 'data-platform="http://mock-platform:8090"';
const HTTPS_PLATFORM_ATTRIBUTE = 'data-platform="https://mock-platform:8090"';

/**
 * @param {string} html
 * @return {string}
 */
function withHttpsPlatform( html ) {
	return html.split( HTTP_PLATFORM_ATTRIBUTE ).join( HTTPS_PLATFORM_ATTRIBUTE );
}

/**
 * Serves every document of the site with the platform declared over https.
 *
 * @param {import('@playwright/test').BrowserContext} context
 */
function declarePlatformOverHttps( context ) {
	return context.route( ( target ) => target.hostname === 'localhost', async ( route ) => {
		if ( route.request().resourceType() !== 'document' ) {
			return route.fallback();
		}
		const response = await route.fetch();
		return route.fulfill( { response, body: withHttpsPlatform( await response.text() ) } );
	} );
}

// EMBED_SCRIPT_URL sends the script request to another build of embed.js, for
// the live canary. The plugin serves its own copy from the plugin folder, so
// that request is the one replaced. The API stays the mock: the script takes
// its API origin from the data-platform attribute declared above.
const PLUGIN_EMBED_SCRIPT = /\/wp-content\/plugins\/[^/]+\/build\/embed\/embed\.js(\?.*)?$/;

const HOST = 'div[data-profotograaf-gallery="g-e2e"]';
const PAGE_URL = 'http://mock-platform:8090/share/g/spring-wedding';

/**
 * Runs wp-cli in the WordPress container started by up.sh.
 *
 * @param {...string} args
 * @return {string}
 */
function wp( ...args ) {
	return execFileSync( 'docker', [ 'compose', '-f', COMPOSE, 'run', '--rm', '-T', 'cli', 'wp', ...args ], {
		encoding: 'utf8',
		timeout: 120_000,
	} ).trim();
}

/**
 * Creates a published post and returns its URL.
 *
 * @param {string} title
 * @param {string} content
 * @return {string}
 */
function publishPost( title, content ) {
	const id = wp( 'post', 'create', '--porcelain', '--post_status=publish', `--post_title=${ title }`, `--post_content=${ content }` );
	return wp( 'post', 'list', '--post__in=' + id, '--field=url', '--post_type=post' );
}

/**
 * The shortcode for the e2e gallery. `url` makes the lookup unnecessary, so the
 * test needs no pairing.
 *
 * @param {Record<string,string>} options Shortcode attributes.
 * @return {string}
 */
function shortcode( options = {} ) {
	const attributes = { id: 'g-e2e', url: PAGE_URL, title: 'Spring wedding', ...options };
	return '[profotograaf_gallery ' + Object.entries( attributes ).map( ( [ key, value ] ) => `${ key }="${ value }"` ).join( ' ' ) + ']';
}

/**
 * The block markup for the e2e gallery.
 *
 * @param {Record<string,string|string[]>} attributes Block attributes.
 * @return {string}
 */
function block( attributes = {} ) {
	const all = { galleryId: 'g-e2e', galleryTitle: 'Spring wedding', galleryUrl: PAGE_URL, ...attributes };
	return `<!-- wp:profotograaf/gallery ${ JSON.stringify( all ) } /-->`;
}

/**
 * Opens a page in a fresh context with the platform host mapped to the mock and
 * every console error and failed request recorded.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string} url
 * @param {{width:number,height:number}} viewport
 * @param {(context: import('@playwright/test').BrowserContext) => Promise<void>} [setup] Runs before the page opens, for init scripts.
 */
async function openEmbed( browser, url, viewport = { width: 1440, height: 900 }, setup = async () => {} ) {
	// Contexts from the browser fixture skip the project's device options, so the
	// phone profile passes its touch and user agent settings on here.
	const { isMobile, hasTouch, userAgent, deviceScaleFactor } = test.info().project.use;
	const device = Object.fromEntries(
		Object.entries( { isMobile, hasTouch, userAgent, deviceScaleFactor } ).filter( ( [ , value ] ) => value !== undefined )
	);
	const context = await browser.newContext( { viewport, ...device } );
	await context.route( PLATFORM_HOST, async ( route ) => {
		const [ , path ] = PLATFORM_HOST.exec( route.request().url() );
		const response = await route.fetch( { url: MOCK + path } );
		await route.fulfill( { response } );
	} );
	const live = process.env.EMBED_SCRIPT_URL;
	if ( live ) {
		await context.route( PLUGIN_EMBED_SCRIPT, async ( route ) => {
			const response = await route.fetch( { url: live } );
			await route.fulfill( { response } );
		} );
	}
	await declarePlatformOverHttps( context );
	if ( live ) {
		// The live script asks for the large images in CORS mode, and the mock
		// image route sends no CORS headers. Chromium also refuses these requests
		// as a page on one loopback port reaching another, so the answer is served
		// from here instead of the network.
		await context.route( ( target ) => target.href.startsWith( `${ MOCK }/img/` ), async ( route ) => {
			const response = await route.fetch();
			await route.fulfill( { response, headers: { ...response.headers(), 'access-control-allow-origin': '*' } } );
		} );
	}
	await setup( context );
	const page = await context.newPage();
	/** @type {string[]} */
	const problems = [];
	page.on( 'console', ( message ) => {
		// WebKit logs the plugin's preconnect hint for the container-only host as an
		// error. The host is mapped to the mock by the route above, which a
		// preconnect does not go through.
		if ( message.type() === 'error' && ! message.text().startsWith( 'Failed to preconnect to http://mock-platform' ) ) {
			problems.push( 'console: ' + message.text() );
		}
	} );
	page.on( 'pageerror', ( error ) => problems.push( 'pageerror: ' + error.message ) );
	page.on( 'requestfailed', ( request ) => problems.push( 'failed: ' + request.url() ) );
	page.on( 'response', ( response ) => {
		if ( response.status() >= 400 ) {
			problems.push( `${ response.status() }: ${ response.url() }` );
		}
	} );
	await page.goto( url );
	return { context, page, problems, host: page.locator( HOST ).first() };
}

// The tiles appear after the mock platform answers the gallery request. One
// nightly answer took 5.0 s (run 40470513721), as long as the default expect
// timeout, so the wait covers a slow mock and still fails when nothing is drawn.
const TILES_TIMEOUT = 20_000;

/**
 * Waits until embed.js drew `count` tiles.
 *
 * @param {import('@playwright/test').Locator} host
 * @param {number} count
 */
async function expectTiles( host, count ) {
	await expect( host.locator( '.photos .tile' ) ).toHaveCount( count, { timeout: TILES_TIMEOUT } );
}

/**
 * Boxes of the gallery host, its tiles and the theme footer, in viewport pixels.
 *
 * @param {import('@playwright/test').Locator} host
 */
function measure( host ) {
	return host.evaluate( ( el ) => {
		const tiles = [ ...el.shadowRoot.querySelectorAll( '.photos .tile' ) ].map( ( tile ) => tile.getBoundingClientRect() );
		const footer = document.querySelector( 'footer' );
		const more = el.shadowRoot.querySelector( '.more' );
		return {
			hostBottom: el.getBoundingClientRect().bottom,
			lastTileBottom: Math.max( ...tiles.map( ( box ) => box.bottom ) ),
			footerTop: footer ? footer.getBoundingClientRect().top : Infinity,
			moreBottom: more && more.getClientRects().length > 0 ? more.getBoundingClientRect().bottom : -Infinity,
			tiles: tiles.map( ( { left, top, width, height } ) => ( { left, top, width, height } ) ),
			scrollWidth: document.documentElement.scrollWidth,
			innerWidth: window.innerWidth,
		};
	} );
}

/**
 * Makes the browser behave like one without declarative shadow DOM: the server
 * markup arrives with its <template> left inert, so the light-DOM fallback
 * link is what a visitor sees (#260). Call it on a context before it loads a
 * page.
 *
 * @param {import('@playwright/test').BrowserContext} context
 */
function withoutDeclarativeShadowDom( context ) {
	return context.route( ( target ) => target.hostname === 'localhost', async ( route ) => {
		if ( route.request().resourceType() !== 'document' ) {
			return route.fallback();
		}
		const response = await route.fetch();
		const body = withHttpsPlatform( ( await response.text() ).replace( /<template shadowrootmode=/g, '<template data-shadowrootmode=' ) );
		return route.fulfill( { response, body } );
	} );
}

module.exports = { PLUGIN_EMBED_SCRIPT, HOST, PAGE_URL, wp, publishPost, shortcode, block, openEmbed, expectTiles, measure, withoutDeclarativeShadowDom };
