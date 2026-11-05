// @ts-check
// Takes the WordPress admin screenshots of the ProFotograaf public docs
// (plugins-and-mcp page, professionals #2539) in English, Dutch, German and French.
//
// This spec is not part of the normal E2E run: playwright.config.js skips it
// unless DOCS_SCREENSHOTS is set. Run it with `make docs-screenshots`, which
// starts the E2E stack, captures the JPEGs and stops the stack. The images land
// in test-results/docs-shots/<locale>/<id>.jpg (1280x800, JPEG quality 80, the
// format of the docs' own screenshots) for web/public/marketing/docs/shots/.
const { execFileSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const OUTPUT = process.env.DOCS_SHOTS_DIR || path.join( __dirname, '..', '..', 'test-results', 'docs-shots' );
const SITE_TITLE = 'Harbour Light Photography';
const SETTINGS = '/wp-admin/options-general.php?page=profotograaf';
// The docs locale folder and the WordPress locale of each language.
const LOCALES = [
	{ docs: 'en', wp: '' },
	{ docs: 'nl', wp: 'nl_NL' },
	{ docs: 'de', wp: 'de_DE' },
	{ docs: 'fr', wp: 'fr_FR' },
];
// The mock lists every gallery with /cover.jpg, which it does not serve. The
// picker shows a real photo from the fixtures instead.
const COVER = path.join( __dirname, 'fixtures', 'photos', 'p-1-web.jpg' );
// Hides the "update available" notices of the throwaway test site.
const QUIET_PLUGIN = `<?php
$quiet = static function ( $transient ) {
	$transient->updates      = array();
	$transient->response     = array();
	$transient->translations = array();
	return $transient;
};
foreach ( array( 'update_core', 'update_plugins', 'update_themes' ) as $name ) {
	add_filter( "site_transient_$name", static function ( $value ) use ( $quiet ) {
		return $quiet( is_object( $value ) ? $value : new stdClass() );
	} );
}
`;

/**
 * Runs wp-cli in the WordPress stack started by up.sh.
 *
 * @param {string[]} args
 */
function wp( ...args ) {
	return execFileSync(
		'docker',
		[ 'compose', '-f', COMPOSE, 'run', '--rm', '-T', 'cli', 'wp', ...args ],
		{ encoding: 'utf8', timeout: 180_000 }
	).trim();
}

/**
 * Stores the plan the platform reported, as the status sync would.
 *
 * @param {{slug: string, name: string, badge: boolean}} plan
 */
function plan( { slug, name, badge } ) {
	const now = Math.floor( Date.now() / 1000 );
	const status = { fetched_at: now, attempted_at: now, plan: { slug, name, badge, prominent: false } };
	wp( 'option', 'update', 'profotograaf_platform_status', JSON.stringify( status ), '--format=json' );
}

/**
 * Sets the admin user's language; an empty locale is the site default, English.
 *
 * @param {string} locale
 */
function language( locale ) {
	wp( 'user', 'update', 'admin', `--locale=${ locale }` );
}

/**
 * Saves a 1280x800 JPEG of the page for one docs locale.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} locale
 * @param {string} id
 */
async function shoot( page, locale, id ) {
	const dir = path.join( OUTPUT, locale );
	fs.mkdirSync( dir, { recursive: true } );
	await page.mouse.move( 0, 0 );
	await page.screenshot( { path: path.join( dir, `${ id }.jpg` ), type: 'jpeg', quality: 80, animations: 'disabled', caret: 'hide' } );
}

/**
 * Waits until every image inside the locator has loaded.
 *
 * @param {import('@playwright/test').Locator} scope
 */
async function imagesLoaded( scope ) {
	await expect
		.poll( () => scope.locator( 'img' ).evaluateAll( ( list ) => list.every( ( img ) => /** @type {HTMLImageElement} */ ( img ).complete && /** @type {HTMLImageElement} */ ( img ).naturalWidth > 0 ) ), { timeout: 30_000 } )
		.toBe( true );
}

test.describe.configure( { mode: 'serial' } );
test.use( { viewport: { width: 1280, height: 800 } } );

test.describe( 'docs screenshots of the WordPress admin', () => {
	test.beforeAll( () => {
		wp( 'language', 'core', 'install', 'nl_NL', 'de_DE', 'fr_FR' );
		wp( 'option', 'update', 'blogname', SITE_TITLE );
		// The telemetry question would sit between the Free plan condition and the settings.
		wp( 'option', 'update', 'profotograaf_telemetry_prompt', JSON.stringify( { answered: true } ), '--format=json' );
		wp( 'eval', `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/e2e-quiet.php', base64_decode( '${ Buffer.from( QUIET_PLUGIN ).toString( 'base64' ) }' ) );` );
	} );

	test.afterAll( async () => {
		language( '' );
		wp( 'eval', "foreach ( array( 'profotograaf_connection', 'profotograaf_platform_status', 'profotograaf_link_consent', 'profotograaf_telemetry_prompt' ) as $o ) { delete_option( $o ); }" );
		wp( 'option', 'update', 'blogname', 'Profotograaf E2E' );
		wp( 'eval', "wp_delete_file( WPMU_PLUGIN_DIR . '/e2e-quiet.php' );" );
		await mock( '/__reset' );
	} );

	test( 'wp-connect: Settings, Profotograaf before connecting', async ( { page } ) => {
		test.setTimeout( 120_000 );
		await login( page );
		for ( const { docs, wp: locale } of LOCALES ) {
			language( locale );
			await page.goto( SETTINGS );
			await expect( page.locator( 'form input[name="action"][value="profotograaf_connect"]' ) ).toHaveCount( 1 );
			await shoot( page, docs, 'wp-connect' );
		}
	} );

	test( 'wp-free-link: the Free plan condition', async ( { page } ) => {
		test.setTimeout( 120_000 );
		language( '' );
		await mock( '/__reset' );
		await login( page );
		await page.goto( SETTINGS );
		await page.getByRole( 'button', { name: 'Connect to Profotograaf' } ).click();
		await expect( page.locator( '.profotograaf-code' ) ).toBeVisible();
		await mock( '/__approve' );
		await expect( page.getByText( 'Connected to Profotograaf.' ) ).toBeVisible( { timeout: 20_000 } );

		plan( { slug: 'free', name: 'Free', badge: true } );
		wp( 'eval', "delete_option( 'profotograaf_link_consent' );" );
		for ( const { docs, wp: locale } of LOCALES ) {
			language( locale );
			await page.goto( SETTINGS );
			await expect( page.locator( '.profotograaf-notice--link-consent input[name="accept"]' ) ).toBeVisible();
			await shoot( page, docs, 'wp-free-link' );
		}
	} );

	test( 'wp-gallery-block: the gallery block with its picker', async ( { page } ) => {
		plan( { slug: 'pro', name: 'Pro', badge: false } );
		test.setTimeout( 240_000 );
		await page.route( '**/cover.jpg', ( route ) => route.fulfill( { path: COVER, contentType: 'image/jpeg' } ) );
		await login( page );
		for ( const { docs, wp: locale } of LOCALES ) {
			language( locale );
			const id = wp( 'post', 'create', '--porcelain', '--post_type=page', '--post_status=draft', `--post_title=${ SITE_TITLE }`, '--post_content=<!-- wp:profotograaf/gallery /-->' );
			await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
			await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/block-editor' ) );
			await page.evaluate( () => {
				window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
				window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'fullscreenMode', false );
			} );
			const dialog = page.locator( '.components-modal__screen-overlay' );
			if ( await dialog.waitFor( { state: 'visible', timeout: 4_000 } ).then( () => true, () => false ) ) {
				await page.keyboard.press( 'Escape' );
				await expect( dialog ).toBeHidden();
			}
			const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
			const block = canvas.locator( '.wp-block-profotograaf-gallery' );
			await block.click();
			await expect( block.getByText( 'Spring wedding' ).first() ).toBeVisible( { timeout: 20_000 } );
			await imagesLoaded( block );
			await shoot( page, docs, 'wp-gallery-block' );
			wp( 'post', 'delete', id, '--force' );
		}
	} );
} );
