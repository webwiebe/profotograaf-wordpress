// @ts-check
// A published page with a gallery block loads no JavaScript from the platform and
// carries the gallery markup in its source (#260, wordpress.org guideline 8).
const { test, expect } = require( '@playwright/test' );
const { publishPost, block } = require( './embed-helpers' );

test.skip( !! process.env.E2E_BASE_URL, 'needs the docker WordPress and mock platform of tests/e2e/up.sh' );

/** @type {string} */
let url;

test.beforeAll( () => {
	url = publishPost( 'No remote code', block( { layout: 'grid' } ) );
} );

test( 'the gallery is in the page source, as plain HTML with real images', async ( { request } ) => {
	const source = await ( await request.get( url ) ).text();
	expect( source ).toContain( 'data-profotograaf-gallery=' );
	expect( source ).toMatch( /<template shadowrootmode="open">/ );
	expect( source, 'photos are <img> tags in the source' ).toMatch( /<img [^>]*src="[^"]+"/ );
} );

test( 'no script on the page comes from the platform', async ( { browser } ) => {
	const context = await browser.newContext();
	const page = await context.newPage();
	const site = new URL( url ).origin;
	/** @type {string[]} */
	const scripts = [];
	page.on( 'request', ( request ) => {
		if ( request.resourceType() === 'script' ) {
			scripts.push( request.url() );
		}
	} );
	await page.goto( url, { waitUntil: 'networkidle' } );

	const foreign = scripts.filter( ( script ) => new URL( script ).origin !== site );
	expect( foreign, 'scripts from another origin' ).toEqual( [] );
	expect( scripts.some( ( script ) => script.includes( '/embed/' ) ), 'the bundled embed script is served by the site' ).toBe( true );

	const tags = await page.evaluate( () => [ ...document.querySelectorAll( 'script[src]' ) ].map( ( el ) => /** @type {HTMLScriptElement} */ ( el ).src ) );
	expect( tags.filter( ( src ) => new URL( src ).origin !== site ), 'script tags from another origin' ).toEqual( [] );
	await context.close();
} );
