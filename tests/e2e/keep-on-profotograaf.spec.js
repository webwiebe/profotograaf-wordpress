// @ts-check
//
// Issue #151: the per-photo choice "Keep on Profotograaf" on the Import from
// Profotograaf screen, behind the setting "Keep photos on Profotograaf".
//
// The first test proves that nothing changes while the setting is off (its
// default). The others switch it on through the settings screen and keep a
// photo, which must create a virtual attachment without downloading the photo.
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];

/**
 * Runs wp-cli in the WordPress stack started by up.sh.
 *
 * @param {string[]} args
 */
function wp( ...args ) {
	return execFileSync( 'docker', [ 'compose', ...PROJECT, '-f', COMPOSE, 'run', '--rm', '-T', 'cli', 'wp', ...args ], {
		encoding: 'utf8',
		timeout: 180_000,
	} ).trim();
}

/**
 * Runs PHP inside WordPress and returns the JSON it echoes after "JSON:".
 *
 * @param {string} code
 * @return {any}
 */
function php( code ) {
	const text = wp( 'eval', `$out = function ( $data ) { echo "\\nJSON:" . wp_json_encode( $data ) . "\\n"; }; ${ code }` );
	const line = text.split( '\n' ).reverse().find( ( l ) => l.startsWith( 'JSON:' ) );
	if ( ! line ) {
		throw new Error( `No JSON line in: ${ text }` );
	}
	return JSON.parse( line.slice( 5 ) );
}

/**
 * Saves the plugin settings with the media source on.
 *
 * @param {boolean} keep Whether "Keep photos on Profotograaf" is on.
 */
function settings( keep ) {
	wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: true, virtual_attachments: keep } ), '--format=json' );
}

/** Removes the photos added to the Media Library and the stored catalogue. */
function cleanUp() {
	wp(
		'eval',
		"foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_profotograaf_photo_id', 'fields' => 'ids' ) ) as $id ) { wp_delete_attachment( $id, true ); } delete_option( 'profotograaf_photo_catalogue' );"
	);
}

/** Sends POST /profotograaf/v1/photos/keep as a user, from inside WordPress. */
function keepAs( login_name ) {
	return php(
		`$u = get_user_by( 'login', '${ login_name }' ); wp_set_current_user( $u ? $u->ID : 0 );` +
			" $r = new WP_REST_Request( 'POST', '/profotograaf/v1/photos/keep' ); $r->set_body_params( array( 'ids' => array( 'p-3' ) ) );" +
			' $res = rest_do_request( $r ); $data = $res->get_data();' +
			" $out( array( 'status' => $res->get_status(), 'code' => is_array( $data ) && isset( $data['code'] ) ? $data['code'] : null ) );"
	);
}

/** The /p-1/web.jpg downloads the mock has served since its last reset. */
async function webDownloads() {
	return ( await mock( '/__state', 'GET' ) ).jpegHits.filter( ( /** @type {string} */ hit ) => hit.includes( '/p-1/web.' ) );
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Keep on Profotograaf', () => {
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
		wp( 'eval', "if ( ! get_user_by( 'login', 'pf-contributor' ) ) { wp_insert_user( array( 'user_login' => 'pf-contributor', 'user_pass' => wp_generate_password(), 'role' => 'contributor' ) ); }" );
		cleanUp();
	} );

	test.afterAll( async ( { browser } ) => {
		wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: false, virtual_attachments: false } ), '--format=json' );
		cleanUp();
		wp( 'eval', "$u = get_user_by( 'login', 'pf-contributor' ); if ( $u ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $u->ID ); }" );
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'with the setting off the Import screen and the routes stay as they were', async ( { page } ) => {
		// Saving keeps the stored value of a key the input leaves out, so start from no settings at all.
		wp( 'option', 'delete', 'profotograaf_settings' );
		wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: true } ), '--format=json' );
		await login( page );

		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await expect( page.getByLabel( 'Keep photos on Profotograaf' ) ).not.toBeChecked();

		await page.goto( '/wp-admin/upload.php?page=profotograaf-import' );
		await expect( page.locator( '.profotograaf-import__card' ) ).toHaveCount( 4, { timeout: 30_000 } );
		await expect( page.getByRole( 'button', { name: 'Import 0 photos' } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: /on Profotograaf/ } ) ).toHaveCount( 0 );
		await expect( page.getByText( /Keep on Profotograaf/ ) ).toHaveCount( 0 );
		expect( await page.locator( '#profotograaf-import-root' ).getAttribute( 'data-keep' ) ).toBeNull();

		const state = php(
			"$routes = rest_get_server()->get_routes(); global $wp_filter; $hooked = false; foreach ( ( isset( $wp_filter['wp_get_attachment_url'] ) ? $wp_filter['wp_get_attachment_url']->callbacks : array() ) as $cbs ) { foreach ( $cbs as $cb ) { if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof Profotograaf\\Virtual_Attachments ) { $hooked = true; } } }" +
				" $out( array( 'enabled' => Profotograaf\\Virtual_Attachments::enabled(), 'keep' => isset( $routes['/profotograaf/v1/photos/keep'] ), 'import' => isset( $routes['/profotograaf/v1/photos/import'] ), 'hooked' => $hooked ) );"
		);
		expect( state ).toEqual( { enabled: false, keep: false, import: true, hooked: false } );
		expect( keepAs( 'admin' ) ).toEqual( { status: 404, code: 'rest_no_route' } );
	} );

	test( 'the setting explains the unpublish behaviour and switches the choice on', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		const box = page.getByLabel( 'Keep photos on Profotograaf' );
		await expect( box ).not.toBeChecked();
		const row = page.locator( 'tr', { has: box } );
		await expect( row ).toContainText( 'edge caches' );
		await expect( row ).toContainText( 'up to a year' );

		await box.check();
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByLabel( 'Keep photos on Profotograaf' ) ).toBeChecked();
		expect( php( '$out( Profotograaf\\Virtual_Attachments::enabled() );' ) ).toBe( true );
	} );

	test( 'keeps a photo on Profotograaf without downloading it', async ( { page } ) => {
		settings( true );
		const before = ( await webDownloads() ).length;
		await login( page );
		await page.goto( '/wp-admin/upload.php?page=profotograaf-import' );
		await expect( page.locator( '.profotograaf-import__card' ) ).toHaveCount( 4, { timeout: 30_000 } );
		await expect( page.getByText( /Keep on Profotograaf adds a photo to the Media Library without a download/ ) ).toContainText(
			"copies already held by edge caches and visitors' browsers can keep showing it for up to a year"
		);

		await page.getByRole( 'button', { name: /Bride/ } ).click();
		await page.getByRole( 'button', { name: 'Keep 1 photo on Profotograaf' } ).click();
		await expect( page.locator( '#profotograaf-import-root' ).getByText( '1 photo kept on Profotograaf.' ) ).toBeVisible( { timeout: 60_000 } );
		await expect( page.locator( '.profotograaf-import__badge' ) ).toHaveCount( 1 );
		await expect( page.locator( '.profotograaf-import__badge' ) ).toContainText( 'Kept on Profotograaf' );

		const kept = php(
			"$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'meta_key' => '_profotograaf_photo_id', 'meta_value' => 'p-1', 'fields' => 'ids' ) ); $id = (int) $ids[0];" +
				" $out( array( 'count' => count( $ids ), 'id' => $id, 'virtual' => get_post_meta( $id, '_profotograaf_virtual', true ), 'url' => wp_get_attachment_url( $id ), 'on_disk' => file_exists( (string) get_attached_file( $id ) ) ) );"
		);
		expect( kept.count ).toBe( 1 );
		expect( kept.virtual ).toBe( '1' );
		expect( kept.url ).toMatch( /\/img\/p-1\/web\.jpg$/ );
		expect( kept.on_disk ).toBe( false );
		expect( await webDownloads() ).toHaveLength( before );

		// A second visit still marks it, from the photo list.
		await page.reload();
		await expect( page.locator( '.profotograaf-import__badge' ) ).toHaveCount( 1, { timeout: 30_000 } );
		await expect( page.locator( '.profotograaf-import__badge' ) ).toContainText( 'Kept on Profotograaf' );

		// The attachment says where the photo lives and offers no Re-import.
		await page.goto( `/wp-admin/post.php?post=${ kept.id }&action=edit` );
		await expect( page.getByText( 'Kept on Profotograaf. This site shows the photo from Profotograaf and stores no copy.' ) ).toBeVisible();
		await expect( page.locator( '.profotograaf-reimport__button' ) ).toHaveCount( 0 );
	} );

	test( 'the keep action needs the upload_files capability', async () => {
		settings( true );
		expect( keepAs( 'pf-contributor' ) ).toEqual( { status: 403, code: 'rest_forbidden' } );
		expect( php( "$out( count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'meta_key' => '_profotograaf_photo_id', 'meta_value' => 'p-3', 'fields' => 'ids' ) ) ) );" ) ).toBe( 0 );
	} );
} );
