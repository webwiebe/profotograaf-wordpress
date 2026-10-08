// @ts-check
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );

/**
 * Runs wp-cli in the WordPress stack started by up.sh.
 *
 * @param {string[]} args
 */
function wp( ...args ) {
	return execFileSync(
		'docker',
		[ 'compose', '-f', COMPOSE, 'run', '--rm', '-T', 'cli', 'wp', ...args ],
		{ encoding: 'utf8', timeout: 120_000 }
	).trim();
}

/** Switches "Use Profotograaf photos in the editor" on or off. */
function mediaSource( on ) {
	wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: on } ), '--format=json' );
}

/** Removes the imported photos and the stored catalogue, so the spec can run again. */
function cleanUp() {
	const ids = wp( 'post', 'list', '--post_type=attachment', '--post_status=any', '--field=ID' );
	if ( ids !== '' ) {
		wp( 'post', 'delete', ...ids.split( /\s+/ ), '--force' );
	}
	wp( 'eval', "delete_option( 'profotograaf_photo_catalogue' );" );
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Media > Import from Profotograaf', () => {
	test.beforeAll( async ( { browser } ) => {
		await mock( '/__reset' );
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Connect to Profotograaf' } ).click();
		await expect( page.locator( '.profotograaf-code' ) ).toBeVisible();
		await mock( '/__approve' );
		await expect( page.getByText( 'Connected to Profotograaf.' ) ).toBeVisible( { timeout: 20_000 } );
		await page.close();

		// The mock listens on a port and a private address the safe HTTP functions refuse by default.
		wp(
			'eval',
			"wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/e2e-http.php', '<?php add_filter( \"http_request_host_is_external\", \"__return_true\" ); add_filter( \"http_allowed_safe_ports\", function ( $p ) { $p[] = 8090; return $p; } );' );"
		);
		cleanUp();
	} );

	test.afterAll( async ( { browser } ) => {
		mediaSource( false );
		cleanUp();
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'the entry and the screen are absent while the setting is off', async ( { page } ) => {
		mediaSource( false );
		await login( page );
		await page.goto( '/wp-admin/upload.php' );
		await expect( page.locator( '#menu-media' ).getByRole( 'link', { name: 'Import from Profotograaf' } ) ).toHaveCount( 0 );

		const response = await page.goto( '/wp-admin/upload.php?page=profotograaf-import' );
		expect( response?.status() ).toBeGreaterThanOrEqual( 400 );
	} );

	test( 'browses, searches and filters the photos', async ( { page } ) => {
		mediaSource( true );
		await login( page );
		await page.goto( '/wp-admin/upload.php' );
		await page.locator( '#menu-media' ).getByRole( 'link', { name: 'Import from Profotograaf' } ).click();

		await expect( page.getByRole( 'heading', { level: 1, name: 'Import from Profotograaf' } ) ).toBeVisible();
		const cards = page.locator( '.profotograaf-import__card' );
		await expect( cards ).toHaveCount( 4, { timeout: 30_000 } );

		await page.getByLabel( 'Search photos' ).fill( 'leaves' );
		await page.getByRole( 'button', { name: 'Search' } ).click();
		await expect( cards ).toHaveCount( 1 );
		await expect( cards.first() ).toContainText( 'Falling leaves' );

		await page.getByLabel( 'Search photos' ).fill( '' );
		await page.getByRole( 'button', { name: 'Search' } ).click();
		await expect( cards ).toHaveCount( 4 );
		await page.getByLabel( 'Gallery' ).selectOption( { label: 'Spring wedding (3)' } );
		await expect( cards ).toHaveCount( 3 );
	} );

	test( 'imports the selected photos, marks them and lists them under the Profotograaf source', async ( { page } ) => {
		mediaSource( true );
		wp(
			'eval',
			"wp_insert_attachment( array( 'post_title' => 'Plain upload', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ) );"
		);
		await login( page );
		await page.goto( '/wp-admin/upload.php?page=profotograaf-import' );
		const cards = page.locator( '.profotograaf-import__card' );
		await expect( cards ).toHaveCount( 4, { timeout: 30_000 } );

		await page.getByRole( 'button', { name: /Bride/ } ).click();
		await page.getByRole( 'button', { name: /Rings/ } ).click();
		await page.getByRole( 'button', { name: 'Import 2 photos' } ).click();

		await expect( page.locator( '#profotograaf-import-root' ).getByText( '2 photos imported.' ) ).toBeVisible( { timeout: 60_000 } );
		await expect( page.locator( '.profotograaf-import__badge' ) ).toHaveCount( 2 );
		await expect( page.getByRole( 'link', { name: 'Open in Media Library' } ).first() ).toBeVisible();
		expect( wp( 'post', 'list', '--post_type=attachment', '--meta_key=_profotograaf_photo_id', '--format=count' ) ).toBe( '2' );

		// A second visit still marks them, from the Media Library lookup.
		await page.reload();
		await expect( page.locator( '.profotograaf-import__badge' ) ).toHaveCount( 2 );

		await page.goto( '/wp-admin/upload.php' );
		const attachments = page.locator( '.attachments .attachment' );
		await expect( attachments ).toHaveCount( 3 );
		await page.locator( '#profotograaf-source-filter' ).selectOption( 'profotograaf' );
		await expect( attachments ).toHaveCount( 2 );
	} );
	test( 'shows the origin of an imported photo and re-imports it in place', async ( { page } ) => {
		mediaSource( true );
		const ids = wp( 'post', 'list', '--post_type=attachment', '--meta_key=_profotograaf_photo_id', '--field=ID' ).split( /\s+/ );
		const id = ids[ 0 ];
		const plain = wp( 'post', 'list', '--post_type=attachment', '--title=Plain upload', '--field=ID' );
		wp( 'post', 'meta', 'update', id, '_profotograaf_version', 'stale-version' );
		await login( page );

		// A plain upload shows neither the gallery link nor the button.
		await page.goto( `/wp-admin/post.php?post=${ plain }&action=edit` );
		await expect( page.locator( '.profotograaf-reimport__button' ) ).toHaveCount( 0 );

		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
		await expect( page.getByRole( 'link', { name: /Spring wedding|Open on Profotograaf/ } ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Re-import' } ).click();
		await expect( page.locator( '.profotograaf-reimport__status' ) ).toContainText( 'replaced with the current version', { timeout: 60_000 } );
		// The mock's image URLs carry no version hash, so the stored version ends up empty. wp post meta get refuses an empty value.
		expect( wp( 'eval', `echo get_post_meta( ${ id }, '_profotograaf_version', true );` ) ).not.toBe( 'stale-version' );
		expect( wp( 'post', 'list', '--post_type=attachment', '--meta_key=_profotograaf_photo_id', '--format=count' ) ).toBe( '2' );

		// A photo that left the catalogue keeps its local copy and says so.
		wp(
			'eval',
			"$c = get_option( 'profotograaf_photo_catalogue' ); $c['photos'] = array(); update_option( 'profotograaf_photo_catalogue', $c );"
		);
		await page.reload();
		await page.getByRole( 'button', { name: 'Re-import' } ).click();
		await expect( page.locator( '.profotograaf-reimport__status' ) ).toContainText( 'no longer available on Profotograaf', { timeout: 30_000 } );
	} );
} );
