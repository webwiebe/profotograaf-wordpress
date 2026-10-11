// @ts-check
//
// Issue #149: the backup against the mock platform. The photographer switches
// the backup on, the backfill sends the seeded images to a private monthly
// gallery, a full storage (413) pauses it, and a token without
// galleries:write asks for a new pairing and sends nothing.
//
// The spec writes the connection through wp-cli, so it needs no pairing. A
// must-use plugin sets DISABLE_WP_CRON, so no run starts from a page request;
// the spec starts each run itself with wp-cli. The mock records every call to
// its write routes in GET /__state.
//
// E2E_COMPOSE_PROJECT names another compose project when a second stack runs.
const { spawnSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];
const MU_PLUGIN = "<?php defined( 'DISABLE_WP_CRON' ) || define( 'DISABLE_WP_CRON', true );";
const MU_FILE = 'e2e-backup-flow.php';
const META = 'pf_e2e_backup_flow';
const SCOPES = [ 'galleries:read', 'leads:write', 'galleries:embed', 'galleries:write' ];
const BACKUP_TAB = '/wp-admin/options-general.php?page=profotograaf&tab=backup';
// The plugin's options, as stored rows. Saved before the spec and put back
// after it, so the settings, the connection and everything the backup and
// the other modules stored while connected are as they were.
const OPTION_ROWS = "$wpdb->get_results( $wpdb->prepare( \"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s\", $wpdb->esc_like( 'profotograaf_' ) . '%' ), ARRAY_A )";

/**
 * Runs wp-cli in the WordPress stack started by up.sh and returns stdout and
 * stderr together, because WP_CLI::warning() writes to stderr.
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
 * Stores a connection with a valid token. `scopes: null` stores none, like a
 * connection made before the plugin kept the granted list.
 *
 * @param {{ scopes: string[]|null, expired?: boolean }} options
 */
function connect( { scopes, expired = false } ) {
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
		`$c['connected_at'] = time(); $c['expires_at'] = time() + ( ${ expired ? 'true' : 'false' } ? -60 : 86400 );`,
		"update_option( 'profotograaf_connection', $c, false );",
		out( 'true' )
	);
}

/**
 * Adds a PNG to the Media Library, drawn in its own colour so no two files
 * have the same bytes. Returns its id and file name.
 *
 * @param {number} index
 * @return {{ id: number, file: string, bytes: number }}
 */
function seedImage( index ) {
	return php(
		"$dir = wp_upload_dir(); $name = 'pf-backup-flow-" + index + ".png'; $file = $dir['path'] . '/' . $name;",
		`$im = imagecreatetruecolor( 8, 8 ); imagefill( $im, 0, 0, imagecolorallocate( $im, ${ ( index * 60 ) % 256 }, 90, 160 ) ); imagepng( $im, $file ); imagedestroy( $im );`,
		"$id = wp_insert_attachment( array( 'post_title' => 'Backup flow " + index + "', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file );",
		`update_post_meta( $id, '${ META }', '1' );`,
		"require_once ABSPATH . 'wp-admin/includes/image.php'; wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );",
		out( "array( 'id' => $id, 'file' => $name, 'bytes' => filesize( $file ) )" )
	);
}

/** @param {number} id */
const backupState = ( id ) => php( `${ out( `get_post_meta( ${ id }, '_profotograaf_backup_state', true )` ) }` );

/** The write calls the mock received. */
const received = async () => {
	const state = await mock( '/__state', 'GET' );
	return { creates: state.galleryCreates, uploads: state.uploads, embeddable: state.embeddable, apiHits: state.apiHits.length };
};

test.describe.configure( { mode: 'serial' } );

test.describe( 'Backup against the mock platform', () => {
	/** @type {{ option_name: string, option_value: string, autoload: string }[]} */
	let saved;
	/** @type {{ id: number, file: string, bytes: number }[]} */
	let seeded = [];

	test.beforeAll( async () => {
		await mock( '/__reset' );
		saved = php( 'global $wpdb;', out( OPTION_ROWS ) );
		php(
			`wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/${ MU_FILE }', base64_decode( '${ Buffer.from( MU_PLUGIN ).toString( 'base64' ) }' ) );`,
			"$s = get_option( 'profotograaf_settings', array() ); $s = is_array( $s ) ? $s : array(); unset( $s['backup'], $s['backup_scope'] ); update_option( 'profotograaf_settings', $s );",
			out( 'true' )
		);
		connect( { scopes: SCOPES } );
		seeded = [ 1, 2, 3 ].map( seedImage );
	} );

	test.afterAll( async ( { browser } ) => {
		// Put the Media Library back in the grid view for the other specs.
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.close();

		php(
			`foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '${ META }' ) ) as $id ) { wp_delete_attachment( $id, true ); }`,
			'global $wpdb;',
			"foreach ( array( '_profotograaf_backup_', '_profotograaf_copy_' ) as $prefix ) { $wpdb->query( $wpdb->prepare( \"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s\", $wpdb->esc_like( $prefix ) . '%' ) ); }",
			"$wpdb->query( $wpdb->prepare( \"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s\", $wpdb->esc_like( 'profotograaf_' ) . '%' ) );",
			`foreach ( json_decode( ${ literal( saved ) }, true ) as $row ) { $wpdb->insert( $wpdb->options, $row ); }`,
			'wp_cache_flush();',
			'\\Profotograaf\\Backup_Usage::forget();',
			"wp_clear_scheduled_hook( 'profotograaf_backup_upload' ); wp_clear_scheduled_hook( 'profotograaf_backup_backfill' ); wp_unschedule_hook( 'profotograaf_backup_scan_post' );",
			`@unlink( WPMU_PLUGIN_DIR . '/${ MU_FILE }' );`,
			out( 'true' )
		);
		await mock( '/__mode?mode=up' );
		await mock( '/__reset' );
	} );

	test( 'the backup is off by default and switches on in the Backup tab', async ( { page } ) => {
		await login( page );
		await page.goto( BACKUP_TAB );

		const toggle = page.getByLabel( 'Back up the Media Library to Profotograaf' );
		await expect( toggle ).not.toBeChecked();
		await expect( page.getByRole( 'heading', { name: 'Backup status' } ) ).toHaveCount( 0 );
		expect( php( out( "(int) wp_next_scheduled( 'profotograaf_backup_upload' )" ) ) ).toBe( 0 );

		await toggle.check();
		await page.getByLabel( 'What to back up' ).selectOption( 'all' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		await expect( page.getByLabel( 'Back up the Media Library to Profotograaf' ) ).toBeChecked();
		await expect( page.getByRole( 'heading', { name: 'Backup status' } ) ).toBeVisible();
		await expect( page.getByText( 'Still looking through the Media Library for files to back up.', { exact: false } ) ).toBeVisible();
		await expect( page.getByText( 'The first run starts within a few minutes.', { exact: false } ) ).toBeVisible();

		// Switching it on starts the walk of the Media Library from the first
		// item. The page request itself sends nothing; only WP-Cron and WP-CLI do.
		expect( php( out( "get_option( 'profotograaf_backup_cursor' )" ) ) ).toEqual( { cursor: 0, complete: false } );
		const { creates, uploads } = await received();
		expect( creates ).toHaveLength( 0 );
		expect( uploads ).toHaveLength( 0 );
	} );

	test( 'the backfill sends the seeded images to a private monthly gallery', async ( { page } ) => {
		// The command walks the whole Media Library, then sends the queue.
		const output = wp( 'profotograaf', 'backup', 'run' );
		expect( output ).toMatch( /Checked \d+ Media Library items?\./ );
		expect( output ).toMatch( /Success: Sent \d+ files? to Profotograaf\./ );
		// A WP-Cron run of the worker finds the queue empty and records the heartbeat the status shows.
		wp( 'cron', 'event', 'run', 'profotograaf_backup_upload' );

		const { creates, uploads, embeddable } = await received();
		expect( creates ).toHaveLength( 1 );
		expect( creates[ 0 ].status ).toBe( 201 );
		// Only a title: the platform creates the gallery private.
		expect( Object.keys( creates[ 0 ].body ) ).toEqual( [ 'title' ] );
		expect( creates[ 0 ].body.title ).toMatch( /^Profotograaf E2E backup \d{4}-\d{2}$/ );
		for ( const image of seeded ) {
			expect( uploads ).toContainEqual( { status: 202, gallery_id: 'g-e2e-new', filename: image.file, type: 'image/png', bytes: image.bytes } );
			expect( backupState( image.id ) ).toBe( 'done' );
		}
		expect( uploads.every( ( upload ) => upload.gallery_id === 'g-e2e-new' ) ).toBe( true );
		// Nothing switches the backup gallery on for embedding.
		expect( embeddable ).toEqual( [] );
		expect( php( out( "get_option( 'profotograaf_backup_galleries' )" ) ) ).toEqual( { [ creates[ 0 ].body.title.slice( -7 ) ]: 'g-e2e-new' } );

		await login( page );
		await page.goto( BACKUP_TAB );
		await expect( page.locator( '.profotograaf-backup-status__line' ) ).toHaveText( /\b(\d+) of \1 files backed up\..* Last run .+ ago\./ );

		await page.goto( '/wp-admin/upload.php?mode=list' );
		await expect( page.locator( 'th#profotograaf_backup' ) ).toHaveText( 'Backup' );
		for ( const image of seeded ) {
			await expect( page.locator( `#post-${ image.id } td.column-profotograaf_backup` ) ).toHaveText( 'Backed up' );
		}
	} );

	test( 'a full storage pauses the backup and keeps the file queued', async ( { page } ) => {
		// A new upload joins the queue at once under the scope "every file".
		const image = seedImage( 4 );
		seeded.push( image );
		expect( backupState( image.id ) ).toBe( 'queued' );

		await mock( '/__mode?mode=storage_full' );
		const output = wp( 'profotograaf', 'backup', 'run' );
		expect( output ).toContain( 'Your Profotograaf storage is full, so the backup is paused.' );

		const { uploads } = await received();
		expect( uploads.at( -1 ) ).toEqual( { status: 413, gallery_id: 'g-e2e-new', filename: image.file, type: 'image/png', bytes: image.bytes } );
		expect( backupState( image.id ) ).toBe( 'queued' );
		expect( php( out( "get_option( 'profotograaf_backup_pause' )['reason']" ) ) ).toBe( 'storage_full' );

		// A paused backup sends nothing until it is resumed.
		const again = wp( 'profotograaf', 'backup', 'run' );
		expect( again ).toContain( 'The backup is paused.' );
		expect( ( await received() ).uploads ).toHaveLength( uploads.length );

		await login( page );
		await page.goto( BACKUP_TAB );
		await expect( page.getByText( /Your Profotograaf storage is full, so the backup is paused until .+\. Free up space or upgrade your plan, then press Back up now\./ ) ).toBeVisible();
		await expect( page.locator( '.profotograaf-backup-status__line' ) ).toContainText( '1 file waits to be sent.' );

		await page.goto( '/wp-admin/upload.php?mode=list' );
		await expect( page.locator( '.profotograaf-notice--backup_storage' ) ).toContainText( 'Your Profotograaf storage is full, so the backup is paused.' );
		await expect( page.locator( `#post-${ image.id } td.column-profotograaf_backup` ) ).toHaveText( 'Waiting to be backed up' );

		// Space freed on Profotograaf: --resume lifts the pause and sends the file.
		await mock( '/__mode?mode=up' );
		expect( wp( 'profotograaf', 'backup', 'run', '--resume' ) ).toContain( 'Sent 1 file to Profotograaf.' );
		expect( backupState( image.id ) ).toBe( 'done' );
		expect( ( await received() ).uploads.at( -1 ) ).toMatchObject( { status: 202, filename: image.file } );
	} );

	test( 'a token without galleries:write asks to connect again and sends nothing', async ( { page } ) => {
		// A site paired before the backup existed: the stored token has expired
		// and its scope list was never stored. The platform grants no galleries:write.
		connect( { scopes: null, expired: true } );
		php( "delete_option( 'profotograaf_backup_galleries' );", out( 'true' ) );
		await mock( '/__mode?mode=no_write' );
		const image = seedImage( 5 );
		seeded.push( image );
		const before = await received();

		const output = wp( 'profotograaf', 'backup', 'run' );
		expect( output ).toContain( 'The backup needs a new permission. Connect this site again under Settings > Profotograaf.' );

		const after = await received();
		expect( after.creates.slice( before.creates.length ) ).toEqual( [ { status: 403, body: { title: before.creates[ 0 ].body.title } } ] );
		expect( after.uploads ).toHaveLength( before.uploads.length );
		expect( backupState( image.id ) ).toBe( 'queued' );
		const connection = php( out( "get_option( 'profotograaf_connection' )" ) );
		expect( connection.scopes ).not.toContain( 'galleries:write' );
		expect( connection.write_denied ).toBe( true );

		// The next run stops before it calls the platform at all.
		expect( wp( 'profotograaf', 'backup', 'run' ) ).toContain( 'The backup needs a new permission.' );
		expect( ( await received() ).apiHits ).toBe( after.apiHits );

		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await expect( page.getByText( 'The backup needs a new permission to create its galleries and copy files. Nothing is copied until you connect this site again to grant it.', { exact: false } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Connect again' } ) ).toBeVisible();

		await page.goto( BACKUP_TAB );
		await expect( page.locator( '.profotograaf-backup-status' ) ).toContainText( 'The backup needs a new permission to create its galleries and copy files.' );
		await expect( page.locator( '.profotograaf-backup-status' ).getByRole( 'link', { name: 'Connect this site again' } ) ).toBeVisible();

		await page.goto( '/wp-admin/index.php' );
		await expect( page.getByText( 'The backup to Profotograaf needs a new permission.' ) ).toBeVisible();

		expect( ( await received() ).uploads ).toHaveLength( before.uploads.length );
	} );
} );
