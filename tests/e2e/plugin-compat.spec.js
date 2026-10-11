// @ts-check
//
// Issue #156: photos kept on Profotograaf (virtual attachments) next to
// Regenerate Thumbnails, the most-installed thumbnail tool on wordpress.org.
// A kept photo has no file on this site, so the plugin can only fail on it.
// Profotograaf takes kept photos out of its bulk list, removes its link and
// buttons for them and answers its regenerate route with a clear error. After
// Convert to local the photo has a file again and the plugin can handle it.
//
// The setup installs a pinned version of the plugin from wordpress.org and the
// teardown removes it, so the stack stays as other specs expect it. Without
// network access to wordpress.org the spec is skipped.
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];
const RT = 'regenerate-thumbnails';
const RT_VERSION = '3.1.6';
const NO_FILE = 'This photo is kept on Profotograaf, so this site has no file to make thumbnails from. Profotograaf serves its sizes. Convert it to local if you need the files on this site.';

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
 * Runs PHP inside WordPress as the admin and returns the JSON it echoes after "JSON:".
 *
 * @param {string} code
 * @return {any}
 */
function php( code ) {
	const text = wp( 'eval', `wp_set_current_user( get_user_by( 'login', 'admin' )->ID ); $out = function ( $data ) { echo "\\nJSON:" . wp_json_encode( $data ) . "\\n"; }; ${ code }` );
	const line = text.split( '\n' ).reverse().find( ( l ) => l.startsWith( 'JSON:' ) );
	if ( ! line ) {
		throw new Error( `No JSON line in: ${ text }` );
	}
	return JSON.parse( line.slice( 5 ) );
}

/**
 * Installs and activates the pinned plugin. Tries twice, because it downloads
 * from wordpress.org. Returns false when both tries fail.
 */
function installPlugin() {
	for ( let attempt = 0; attempt < 2; attempt++ ) {
		try {
			wp( 'plugin', 'install', RT, `--version=${ RT_VERSION }`, '--force', '--activate' );
			return true;
		} catch {
			// Retried once below.
		}
	}
	return false;
}

/** Removes the photos added to the Media Library, the local control image and the stored catalogue. */
function cleanUp() {
	wp(
		'eval',
		"foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_profotograaf_photo_id', 'fields' => 'ids' ) ) as $id ) { wp_delete_attachment( $id, true ); } foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_pf_e2e_compat', 'fields' => 'ids' ) ) as $id ) { wp_delete_attachment( $id, true ); } delete_option( 'profotograaf_photo_catalogue' );"
	);
}

/**
 * Keeps a photo on Profotograaf through the REST route the Import screen uses.
 *
 * @param {string} photo Photo id.
 * @return {number} Attachment id.
 */
function keep( photo ) {
	return php(
		"delete_option( 'profotograaf_photo_catalogue' );" +
			` $r = new WP_REST_Request( 'POST', '/profotograaf/v1/photos/keep' ); $r->set_body_params( array( 'ids' => array( '${ photo }' ) ) );` +
			' $out( (int) rest_do_request( $r )->get_data()["results"][0]["attachment_id"] );'
	);
}

/** Adds an ordinary uploaded image, downloaded from the mock. */
function localImage() {
	return php(
		"require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php';" +
			" $tmp = download_url( 'http://mock-platform:8090/img/p-3/web.jpg' ); $id = media_handle_sideload( array( 'name' => 'compat-local.jpg', 'tmp_name' => $tmp ), 0 );" +
			" update_post_meta( $id, '_pf_e2e_compat', '1' ); $out( (int) $id );"
	);
}

/** The ids in Regenerate Thumbnails' bulk list, fetched the way its page does. */
function regenerateList() {
	return php(
		"$r = new WP_REST_Request( 'GET', '/wp/v2/media' ); $r->set_query_params( array( 'is_regeneratable' => 1, 'exclude_site_icons' => 1, 'per_page' => 100 ) );" +
			" $res = rest_do_request( $r ); $out( array( 'ids' => array_map( 'intval', array_column( $res->get_data(), 'id' ) ), 'total' => (int) $res->get_headers()['X-WP-Total'] ) );"
	);
}

/**
 * Calls the plugin's regenerate route for one attachment.
 *
 * @param {number} id Attachment id.
 */
function regenerate( id ) {
	return php(
		`$id = ${ id }; $before = wp_get_attachment_metadata( $id ); $r = new WP_REST_Request( 'POST', "/regenerate-thumbnails/v1/regenerate/$id" );` +
			' $res = rest_do_request( $r ); $data = $res->get_data();' +
			" $out( array( 'status' => $res->get_status(), 'code' => $data['code'] ?? '', 'message' => $data['message'] ?? '', 'meta_same' => $before == wp_get_attachment_metadata( $id ) ) );"
	);
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Regenerate Thumbnails next to photos kept on Profotograaf', () => {
	/** @type {number} */
	let kept = 0;
	/** @type {number} */
	let local = 0;
	let installed = false;

	test.beforeAll( async ( { browser } ) => {
		installed = installPlugin();
		if ( ! installed ) {
			return;
		}
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
		wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: true, virtual_attachments: true } ), '--format=json' );
		kept = keep( 'p-1' );
		local = localImage();
	} );

	test.afterAll( async ( { browser } ) => {
		if ( ! installed ) {
			return;
		}
		wp( 'plugin', 'deactivate', RT );
		wp( 'plugin', 'delete', RT );
		wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: false, virtual_attachments: false } ), '--format=json' );
		cleanUp();
		await mock( '/__reset' );
		const page = await browser.newPage();
		await login( page );
		// mode=list is saved per user; later specs expect the grid.
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test.beforeEach( () => {
		test.skip( ! installed, `${ RT } ${ RT_VERSION } could not be installed from wordpress.org` );
	} );

	test( 'a kept photo is left out of the bulk list and its regenerate call gets a clear error', () => {
		const list = regenerateList();
		expect( list.ids ).toContain( local );
		expect( list.ids ).not.toContain( kept );

		expect( regenerate( kept ) ).toEqual( { status: 409, code: 'profotograaf_virtual_no_file', message: NO_FILE, meta_same: true } );
		expect( regenerate( local ) ).toMatchObject( { status: 200 } );
	} );

	test( 'the Media Library offers Regenerate Thumbnails for local images only', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/upload.php?mode=list' );
		await expect( page.locator( `#post-${ local } .row-actions .regenerate_thumbnails` ) ).toHaveCount( 1 );
		await expect( page.locator( `#post-${ kept } .row-actions .regenerate_thumbnails` ) ).toHaveCount( 0 );

		await page.goto( `/wp-admin/post.php?post=${ kept }&action=edit` );
		await expect( page.locator( '.misc-pub-regenerate-thumbnails' ) ).toBeHidden();
		await page.goto( `/wp-admin/post.php?post=${ local }&action=edit` );
		await expect( page.locator( '.misc-pub-regenerate-thumbnails' ) ).toBeVisible();
	} );

	test( 'after Convert to local the photo can be regenerated again', () => {
		const result = php( `$out( ( new \\Profotograaf\\Modules\\Virtual_Convert() )->convert_many( array( ${ kept } ) ) );` );
		expect( result ).toMatchObject( { converted: 1, failed: [] } );

		expect( regenerateList().ids ).toContain( kept );
		expect( regenerate( kept ) ).toMatchObject( { status: 200 } );
	} );
} );
