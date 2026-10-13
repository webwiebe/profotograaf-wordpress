// @ts-check
// Sending Media Library files against the mock platform when the token lacks
// galleries:write: the file waits, nothing reaches the platform, the settings
// page says why, and a reconnect with the scope sends it. A must-use plugin sets
// DISABLE_WP_CRON, so the queue runs only when the spec runs it.
const { spawnSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];
const MU_PLUGIN = "<?php defined( 'DISABLE_WP_CRON' ) || define( 'DISABLE_WP_CRON', true );";
const MU_FILE = 'e2e-upload-scope.php';
const META = 'pf_e2e_upload_scope';
const GALLERY = 'g-e2e';
const WITHOUT_WRITE = [ 'galleries:read', 'leads:write', 'galleries:embed' ];
const WITH_WRITE = [ ...WITHOUT_WRITE, 'galleries:write' ];
const NEEDS_PERMISSION = 'Sending files needs a new permission. Connect this site again to grant it. Files wait until then.';
// The plugin's options as stored rows, saved before the spec and put back after it.
const OPTION_ROWS = "$wpdb->get_results( $wpdb->prepare( \"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s\", $wpdb->esc_like( 'profotograaf_' ) . '%' ), ARRAY_A )";

/**
 * Runs wp-cli in the WordPress stack started by up.sh.
 *
 * @param {string[]} args
 */
function wp( ...args ) {
	const result = spawnSync( 'docker', [ 'compose', ...PROJECT, '-f', COMPOSE, 'run', '--rm', '-T', 'cli', 'wp', ...args ], {
		encoding: 'utf8',
		timeout: 180_000,
	} );
	if ( result.status !== 0 ) {
		throw new Error( `wp ${ args[ 0 ] } failed: ${ result.stderr }` );
	}
	return `${ result.stdout }\n${ result.stderr }`.trim();
}

/**
 * Runs PHP inside WordPress and returns what it echoes after "JSON:".
 *
 * @param {string[]} lines
 * @return {any}
 */
function php( ...lines ) {
	const text = wp( 'eval', lines.join( ' ' ) );
	const line = text.split( '\n' ).reverse().find( ( l ) => l.startsWith( 'JSON:' ) );
	if ( ! line ) {
		throw new Error( `No JSON line in: ${ text }` );
	}
	return JSON.parse( line.slice( 5 ) );
}

/** PHP that echoes a value as the JSON line php() reads. */
const out = ( expression ) => `echo "\\nJSON:" . wp_json_encode( ${ expression } ) . "\\n";`;

/** A PHP string literal holding a JS value as JSON. */
const literal = ( value ) => `base64_decode( '${ Buffer.from( JSON.stringify( value ) ).toString( 'base64' ) }' )`;

/**
 * Stores a connection with a valid token. `scopes: null` stores no scope list,
 * like a connection made before the plugin kept the granted list.
 *
 * @param {string[]|null} scopes
 */
function connect( scopes ) {
	const connection = {
		access_token: 'access-e2e',
		refresh_token: 'refresh-e2e',
		device_id: 'device-e2e',
		last_error: '',
		scope_revision: 2,
		...( scopes ? { scopes } : {} ),
	};
	php(
		`$c = json_decode( ${ literal( connection ) }, true );`,
		"$c['connected_at'] = time(); $c['expires_at'] = time() + 86400;",
		"update_option( 'profotograaf_connection', $c, false );",
		out( 'true' )
	);
}

/**
 * Adds a PNG to the Media Library and queues it for the linked gallery.
 *
 * @param {number} index
 * @return {number} The attachment id.
 */
function seedQueuedImage( index ) {
	return php(
		"$dir = wp_upload_dir(); $name = 'pf-upload-scope-" + index + ".png'; $file = $dir['path'] . '/' . $name;",
		`$im = imagecreatetruecolor( 8, 8 ); imagefill( $im, 0, 0, imagecolorallocate( $im, ${ ( index * 70 ) % 256 }, 120, 90 ) ); imagepng( $im, $file ); imagedestroy( $im );`,
		"$id = wp_insert_attachment( array( 'post_title' => 'Upload scope " + index + "', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file );",
		`update_post_meta( $id, '${ META }', '1' );`,
		"require_once ABSPATH . 'wp-admin/includes/image.php'; wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );",
		`$queued = \\Profotograaf\\Plugin::instance()->upload_queue()->assign( $id, '${ GALLERY }' );`,
		out( '$queued === true ? $id : 0' )
	);
}

/** Runs one unit of the upload queue, as WP-Cron would. */
const runQueue = () => php( '\\Profotograaf\\Plugin::instance()->upload_queue()->run();', out( 'true' ) );

/** @param {number} id */
const uploadState = ( id ) => php( out( `get_post_meta( ${ id }, '_profotograaf_upload_state', true )` ) );

/** The uploads the mock received. */
const uploads = async () => ( await mock( '/__state', 'GET' ) ).uploads;

test.describe.configure( { mode: 'serial' } );

test.describe( 'Sending files without the galleries:write scope', () => {
	/** @type {{ option_name: string, option_value: string, autoload: string }[]} */
	let saved;

	test.beforeAll( async () => {
		await mock( '/__reset' );
		saved = php( 'global $wpdb;', out( OPTION_ROWS ) );
		php(
			`wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/${ MU_FILE }', base64_decode( '${ Buffer.from( MU_PLUGIN ).toString( 'base64' ) }' ) );`,
			"$s = get_option( 'profotograaf_settings', array() ); $s = is_array( $s ) ? $s : array(); $s['media_upload'] = true; unset( $s['backup'] ); update_option( 'profotograaf_settings', $s );",
			`update_option( 'profotograaf_upload_links', array( array( 'gallery_id' => '${ GALLERY }', 'title' => 'E2E gallery', 'created_by' => 1, 'created_at' => time(), 'auto' => false, 'disabled' => false ) ) );`,
			out( 'true' )
		);
	} );

	test.afterAll( async () => {
		php(
			`foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '${ META }' ) ) as $id ) { wp_delete_attachment( $id, true ); }`,
			'global $wpdb;',
			"$wpdb->query( $wpdb->prepare( \"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s\", $wpdb->esc_like( 'profotograaf_' ) . '%' ) );",
			`foreach ( json_decode( ${ literal( saved ) }, true ) as $row ) { $wpdb->insert( $wpdb->options, $row ); }`,
			'wp_cache_flush();',
			"wp_clear_scheduled_hook( 'profotograaf_upload_queue' );",
			`@unlink( WPMU_PLUGIN_DIR . '/${ MU_FILE }' );`,
			out( 'true' )
		);
		await mock( '/__mode?mode=up' );
		await mock( '/__reset' );
	} );

	test( 'a token that lists no write scope holds the file and the settings page says why', async ( { page } ) => {
		connect( WITHOUT_WRITE );
		const id = seedQueuedImage( 1 );
		expect( id ).toBeGreaterThan( 0 );

		runQueue();

		expect( await uploads() ).toHaveLength( 0 );
		expect( uploadState( id ) ).toBe( 'queued' );

		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await expect( page.getByText( NEEDS_PERMISSION ) ).toBeVisible();
	} );

	test( 'after the site connects again with the scope, the held file is sent', async () => {
		const id = php( out( `(int) get_posts( array( 'post_type' => 'attachment', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '${ META }' ) )[0]` ) );
		connect( WITH_WRITE );

		runQueue();

		const sent = await uploads();
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ] ).toMatchObject( { status: 202, gallery_id: GALLERY } );
		expect( uploadState( id ) ).toBe( 'uploaded' );
	} );

	test( 'a token with no stored scopes that the platform refuses is held after the 403', async ( { page } ) => {
		connect( null );
		await mock( '/__mode?mode=no_write' );
		const before = ( await uploads() ).length;
		const id = seedQueuedImage( 2 );

		runQueue();

		const sent = await uploads();
		expect( sent.slice( before ) ).toEqual( [ expect.objectContaining( { status: 403 } ) ] );
		expect( uploadState( id ) ).toBe( 'queued' );
		expect( php( out( "get_option( 'profotograaf_connection' )['write_denied']" ) ) ).toBe( true );

		// The next run stops before it calls the platform.
		runQueue();
		expect( await uploads() ).toHaveLength( sent.length );

		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await expect( page.getByText( NEEDS_PERMISSION ) ).toBeVisible();
	} );
} );
