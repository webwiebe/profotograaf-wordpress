// @ts-check
// The gallery the plugin draws on the server (a declarative shadow root, #260)
// and the gallery the real embed.js draws are written by two code bases. This
// spec renders the same published page both ways and compares them:
//
//  1. Server only: embed.js is replaced by an empty script, so the page shows
//     what a visitor sees before the script runs, or without it.
//  2. Adopted: the real embed.js (tests/e2e/fixtures/embed.js) takes over the
//     server root. Adoption means no gallery request from the browser and
//     data-pf-state="drawn" on the host.
//
// It fails when the fixture embed.js predates adoption (professionals#2572) or
// when the plugin's markup or stylesheet drifts from what the script expects.
// Boxes are compared with each other, never against a pixel value. The pages
// set no display option: the server draws a gallery only when every option it
// sets is one the PHP renderer serves, and per_page is not (embed.js draws it).
const { test, expect } = require( '@playwright/test' );
const { publishPost, shortcode, openEmbed } = require( './embed-helpers' );

const WIDTHS = [ 390, 820, 1440 ];
const LAYOUTS = [ 'masonry', 'grid' ];
const TOLERANCE = 1;
const GALLERY_API = /\/api\/v1\/embed\/galleries\/g-e2e(\?|$)/;
const SCRIPT = /\/(share\/embed|build\/embed)\/embed(\.[a-f0-9]{12})?\.js$/;

/**
 * Everything that identifies a tile to a visitor, in a form that does not
 * depend on how the layout orders the DOM.
 *
 * @param {import('@playwright/test').Locator} host
 */
function readTiles( host ) {
	return host.evaluate( ( el ) => {
		const root = el.shadowRoot;
		if ( ! root ) {
			return null;
		}
		const photos = root.querySelector( '.photos' );
		const tiles = [ ...root.querySelectorAll( '.photos .tile' ) ]
			.filter( ( tile ) => tile.querySelector( 'img' ) )
			.map( ( tile ) => {
				const img = /** @type {HTMLImageElement} */ ( tile.querySelector( 'img' ) );
				const box = tile.getBoundingClientRect();
				return {
					src: ( img.getAttribute( 'src' ) || '' ).replace( /^https?:\/\/[^/]+/, '' ),
					alt: img.getAttribute( 'alt' ) || '',
					text: ( tile.textContent || '' ).replace( /\s+/g, ' ' ).trim(),
					box: { left: box.left, top: box.top, width: box.width, height: box.height },
				};
			} )
			.sort( ( a, b ) => a.src.localeCompare( b.src ) );
		return {
			tiles,
			flexWrap: photos ? getComputedStyle( photos ).flexWrap : '',
			hostHeight: el.getBoundingClientRect().height,
			scrollWidth: document.documentElement.scrollWidth,
			innerWidth: window.innerWidth,
		};
	} );
}

/**
 * Opens the page with a script that does nothing in place of embed.js.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string} url
 * @param {number} width
 */
function openServerOnly( browser, url, width ) {
	return openEmbed( browser, url, { width, height: 900 }, async ( context ) => {
		await context.route( ( target ) => SCRIPT.test( target.pathname ), ( route ) =>
			route.fulfill( { status: 200, contentType: 'text/javascript', body: '' } )
		);
	} );
}

test.describe( 'server markup and the adopted gallery', () => {
	/** @type {Record<string,string>} */
	const urls = {};

	test.beforeAll( () => {
		for ( const layout of LAYOUTS ) {
			urls[ layout ] = publishPost( `Parity ${ layout }`, shortcode( { layout } ) );
		}
	} );

	for ( const layout of LAYOUTS ) {
		for ( const width of WIDTHS ) {
			test( `${ layout } at ${ width }px: same photos, adopted without a request`, async ( { browser } ) => {
				const server = await openServerOnly( browser, urls[ layout ], width );
				const before = await readTiles( server.host );
				expect( before, 'the server wrote no shadow root: the plugin sent no gallery markup' ).not.toBeNull();
				expect( before.tiles.length, 'the server markup has no photos' ).toBeGreaterThan( 0 );
				expect( before.scrollWidth, 'the server markup scrolls the page sideways' ).toBeLessThanOrEqual( before.innerWidth );
				if ( layout === 'masonry' ) {
					// Before the columns exist the tiles wrap in reading order (professionals#2601).
					expect( before.flexWrap, 'the plugin stylesheet lacks the wrap rule for unplaced masonry tiles' ).toBe( 'wrap' );
				}
				await server.context.close();

				const adopted = await openEmbed( browser, urls[ layout ], { width, height: 900 } );
				const galleryRequests = [];
				adopted.page.on( 'request', ( request ) => {
					if ( GALLERY_API.test( request.url() ) ) {
						galleryRequests.push( request.url() );
					}
				} );
				await adopted.page.reload();
				await expect( adopted.host ).toHaveAttribute( 'data-pf-state', 'drawn' );
				const after = await readTiles( adopted.host );

				expect( galleryRequests, 'embed.js asked the platform for a gallery the server had already drawn' ).toEqual( [] );
				expect( after.tiles.map( ( { src, alt, text } ) => ( { src, alt, text } ) ) ).toEqual(
					before.tiles.map( ( { src, alt, text } ) => ( { src, alt, text } ) )
				);
				expect( after.scrollWidth ).toBeLessThanOrEqual( after.innerWidth );
				if ( layout === 'grid' ) {
					// A grid has no placement step, so adopting it moves nothing.
					expect( after.hostHeight ).toBeCloseTo( before.hostHeight, 0 );
					for ( const [ i, tile ] of after.tiles.entries() ) {
						for ( const key of /** @type {const} */ ( [ 'left', 'top', 'width', 'height' ] ) ) {
							expect( Math.abs( tile.box[ key ] - before.tiles[ i ].box[ key ] ), `grid tile ${ i } ${ key } moved on adoption` ).toBeLessThanOrEqual( TOLERANCE );
						}
					}
				}
				expect( adopted.problems ).toEqual( [] );
				await adopted.context.close();
			} );
		}
	}
} );
