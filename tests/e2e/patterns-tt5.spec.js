// @ts-check
//
// Issue #1709: every pattern of the plugin renders on Twenty Twenty-Five, the
// default block theme. The spec installs the theme from wordpress.org, makes a
// page from each registered pattern and checks what a visitor gets. Without
// network access to wordpress.org the spec is skipped.
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];
const THEME = 'twentytwentyfive';

/**
 * Runs wp-cli in the WordPress stack started by up.sh.
 *
 * @param {string[]} args
 */
function wp( ...args ) {
	return execFileSync( 'docker', [ 'compose', ...PROJECT, '-f', COMPOSE, 'run', '--rm', '-T', 'cli', 'wp', ...args ], {
		encoding: 'utf8',
		timeout: 240_000,
	} ).trim();
}

/** Activates the theme, installing it first when it is missing. Tries twice, because it downloads from wordpress.org. */
function installTheme() {
	if ( wp( 'theme', 'list', '--field=name' ).split( '\n' ).includes( THEME ) ) {
		wp( 'theme', 'activate', THEME );
		return true;
	}
	for ( let attempt = 0; attempt < 2; attempt++ ) {
		try {
			wp( 'theme', 'install', THEME, '--activate' );
			return true;
		} catch {
			// Retried once.
		}
	}
	return false;
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Patterns on Twenty Twenty-Five', () => {
	let installed = false;
	let previousTheme = '';
	let wasInstalled = false;
	/** @type {{ slug: string, pageId: string, heading: string }[]} */
	let pages = [];

	test.beforeAll( () => {
		previousTheme = wp( 'theme', 'list', '--status=active', '--field=name' );
		wasInstalled = wp( 'theme', 'list', '--field=name' ).split( '\n' ).includes( THEME );
		installed = installTheme();
		if ( ! installed ) {
			return;
		}
		const slugs = JSON.parse(
			wp(
				'eval',
				"echo wp_json_encode( array_values( array_filter( array_keys( WP_Block_Patterns_Registry::get_instance()->get_all_registered() ? array_column( WP_Block_Patterns_Registry::get_instance()->get_all_registered(), null, 'name' ) : array() ), fn( $n ) => str_starts_with( $n, 'profotograaf/' ) ) ) );"
			)
		);
		pages = slugs.map( ( /** @type {string} */ slug ) => {
			const out = wp(
				'eval',
				`$p = WP_Block_Patterns_Registry::get_instance()->get_registered( '${ slug }' ); $id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pattern ${ slug }', 'post_content' => $p['content'] ) ); preg_match( '#<h2[^>]*>(.*?)</h2>#', $p['content'], $m ); echo wp_json_encode( array( 'id' => (string) $id, 'heading' => html_entity_decode( $m[1] ?? '' ) ) );`
			);
			const parsed = JSON.parse( out );
			return { slug, pageId: parsed.id, heading: parsed.heading };
		} );
	} );

	test.afterAll( () => {
		if ( ! installed ) {
			return;
		}
		for ( const { pageId } of pages ) {
			wp( 'post', 'delete', pageId, '--force' );
		}
		wp( 'theme', 'activate', previousTheme );
		if ( ! wasInstalled ) {
			wp( 'theme', 'delete', THEME );
		}
	} );

	test.beforeEach( () => {
		test.skip( ! installed, `${ THEME } could not be installed from wordpress.org` );
	} );

	test( 'the plugin registers the seven patterns', () => {
		expect( pages.map( ( p ) => p.slug ).sort() ).toEqual( [
			'profotograaf/client-portal-cta',
			'profotograaf/contact-page',
			'profotograaf/masonry-showcase',
			'profotograaf/portfolio-grid',
			'profotograaf/services-with-gallery',
			'profotograaf/testimonials-with-photos',
			'profotograaf/wedding-story',
		] );
	} );

	test( 'each pattern renders as a page for a visitor', async ( { page } ) => {
		for ( const { slug, pageId, heading } of pages ) {
			const response = await page.goto( `/?page_id=${ pageId }` );
			expect( response?.status(), slug ).toBe( 200 );
			await expect( page.locator( 'body' ), slug ).toHaveClass( /wp-theme-twentytwentyfive/ );
			// The client portal pattern is one dynamic block, which shows nothing until the site is connected.
			if ( heading ) {
				const content = page.locator( '.wp-block-post-content' );
				await expect( content, slug ).toBeVisible();
				await expect( content.getByRole( 'heading', { name: heading } ).first(), slug ).toBeVisible();
			}
			const text = await page.locator( 'body' ).innerText();
			expect( text, slug ).not.toMatch( /Fatal error|Warning:|Notice:|Deprecated:/ );
		}
	} );
} );
