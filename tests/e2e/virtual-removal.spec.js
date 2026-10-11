// @ts-check
//
// Issue #158: photos kept on Profotograaf (virtual attachments) on deactivation
// and before uninstall. Deactivation leaves them without an image and changes
// nothing, so activating again brings them back. The Plugins screen offers
// "Convert all to local", which converts them in the background through
// WP-Cron, and `wp profotograaf virtual convert` does the same from WP-CLI.
// Uninstall is covered by the unit tests (Uninstall_Test), because deleting the
// plugin here would remove it for the specs that run after this one.
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

/** Removes the photos added to the Media Library, the catalogue and the bulk job. */
function cleanUp() {
	wp(
		'eval',
		"foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_profotograaf_photo_id', 'fields' => 'ids' ) ) as $id ) { wp_delete_attachment( $id, true ); } delete_option( 'profotograaf_photo_catalogue' ); delete_option( 'profotograaf_virtual_bulk' ); wp_clear_scheduled_hook( 'profotograaf_virtual_bulk_convert' );"
	);
}

/**
 * Keeps photos on Profotograaf as the admin, through the REST route the
 * Import screen uses. Returns the attachment id by photo id.
 *
 * @param {string[]} ids Photo ids.
 * @return {Record<string, number>}
 */
function keep( ids ) {
	return php(
		"delete_option( 'profotograaf_photo_catalogue' ); wp_set_current_user( get_user_by( 'login', 'admin' )->ID );" +
			` $r = new WP_REST_Request( 'POST', '/profotograaf/v1/photos/keep' ); $r->set_body_params( array( 'ids' => ${ JSON.stringify( ids ).replace( /^\[/, 'array( ' ).replace( /\]$/, ' )' ) } ) );` +
			" $made = array(); foreach ( rest_do_request( $r )->get_data()['results'] as $row ) { $made[ $row['id'] ] = $row['attachment_id']; } $out( $made );"
	);
}

/**
 * The stored state of an attachment.
 *
 * @param {number} id Attachment id.
 */
function stateOf( id ) {
	return php(
		`$id = ${ id };` +
			" $out( array( 'virtual' => get_post_meta( $id, '_profotograaf_virtual', true ), 'on_disk' => file_exists( (string) get_attached_file( $id ) ), 'url' => wp_get_attachment_url( $id ) ) );"
	);
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Photos kept on Profotograaf on deactivation and uninstall', () => {
	test.beforeAll( async ( { browser } ) => {
		await mock( '/__reset' );
		await mock( '/__scenic' );
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
		settings( true );
	} );

	test.afterAll( async ( { browser } ) => {
		wp( 'plugin', 'activate', 'profotograaf' );
		wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: false, virtual_attachments: false } ), '--format=json' );
		cleanUp();
		await mock( '/__reset' );
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'without kept photos the Plugins screen shows no row', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( 'tr[data-slug="profotograaf"]' ) ).toBeVisible();
		await expect( page.locator( '.profotograaf-virtual-row' ) ).toHaveCount( 0 );
	} );

	test( 'deactivation leaves a kept photo without an image and activation brings it back', () => {
		const id = keep( [ 'p-1' ] )[ 'p-1' ];
		expect( stateOf( id ).url ).toMatch( /\/img\/p-1\/web\.jpg$/ );

		wp( 'plugin', 'deactivate', 'profotograaf' );
		try {
			const off = stateOf( id );
			expect( off ).toMatchObject( { virtual: '1', on_disk: false } );
			expect( off.url ).toContain( '/wp-content/uploads/profotograaf-virtual/' );
		} finally {
			wp( 'plugin', 'activate', 'profotograaf' );
		}
		expect( stateOf( id ).url ).toMatch( /\/img\/p-1\/web\.jpg$/ );
	} );

	test( 'Convert all to local on the Plugins screen converts every kept photo in the background', async ( { page } ) => {
		const made = keep( [ 'p-2', 'p-3' ] );
		await login( page );
		await page.goto( '/wp-admin/plugins.php' );
		const row = page.locator( '.profotograaf-virtual-row' );
		await expect( row ).toContainText(
			'3 photos are kept on Profotograaf and have no copy on this site. They show no image while this plugin is deactivated or after you delete it. Convert them to local first.'
		);
		await expect( row ).toContainText( 'wp profotograaf virtual convert' );

		await row.getByRole( 'link', { name: 'Convert all to local' } ).click();
		await expect( page ).toHaveURL( /\/wp-admin\/plugins\.php$/ );
		await expect( page.locator( '.profotograaf-virtual-row' ) ).toContainText( 'Converting photos kept on Profotograaf to local in the background: 0 converted, 0 could not be converted, 3 left.' );
		expect( stateOf( made[ 'p-2' ] ) ).toMatchObject( { virtual: '1', on_disk: false } );

		// The site URL does not resolve inside the container, so WP-Cron is run here: one batch, then the run that finds nothing left.
		for ( let run = 0; run < 2; run++ ) {
			wp( 'cron', 'event', 'run', 'profotograaf_virtual_bulk_convert' );
		}

		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( '.profotograaf-virtual-row' ) ).toContainText(
			'Every photo kept on Profotograaf is converted to local. You can deactivate or delete this plugin and your images stay in place.'
		);
		for ( const id of Object.values( made ) ) {
			const after = stateOf( id );
			expect( after ).toMatchObject( { virtual: '', on_disk: true } );
			expect( after.url ).toContain( '/wp-content/uploads/' );
		}
	} );

	test( 'wp profotograaf virtual convert converts from the command line', () => {
		const id = keep( [ 'p-4' ] )[ 'p-4' ];

		expect( wp( 'profotograaf', 'virtual', 'convert', '--dry-run' ) ).toBe( '1 photo is kept on Profotograaf.' );
		const out = wp( 'profotograaf', 'virtual', 'convert', '--user=admin', '--batch-size=5' );

		expect( out ).toContain( 'Converted 1, failed 0.' );
		expect( out ).toContain( 'Success: 1 photo converted to local.' );
		expect( stateOf( id ) ).toMatchObject( { virtual: '', on_disk: true } );
	} );
} );
