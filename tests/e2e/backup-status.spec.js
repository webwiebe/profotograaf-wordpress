// @ts-check
//
// Issue #144: the backup status on the Backup tab and in the Media Library.
//
// The spec writes the connection, the settings and the backup state through
// wp-cli, so it needs no pairing. A must-use plugin sets DISABLE_WP_CRON, so no
// upload runs while the spec looks at the states.
//
// E2E_COMPOSE_PROJECT names another compose project when a second stack runs.
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
const MU_PLUGIN = "<?php defined( 'DISABLE_WP_CRON' ) || define( 'DISABLE_WP_CRON', true );";

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
 * Runs PHP inside WordPress and returns what it echoes after "JSON:".
 *
 * @param {string} code
 * @return {any}
 */
function php( code ) {
	const text = wp( 'eval', code );
	const line = text.split( '\n' ).reverse().find( ( l ) => l.startsWith( 'JSON:' ) );
	if ( ! line ) {
		throw new Error( `No JSON line in: ${ text }` );
	}
	return JSON.parse( line.slice( 5 ) );
}

/** Removes everything the spec added, so it can run again. */
function cleanUp() {
	wp(
		'eval',
		[
			"foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => 'pf_e2e_backup' ) ) as $id ) { wp_delete_attachment( $id, true ); }",
			"foreach ( array( 'profotograaf_connection', 'profotograaf_settings', 'profotograaf_backup_last_run', 'profotograaf_backup_pause', 'profotograaf_backup_cursor' ) as $o ) { delete_option( $o ); }",
			"wp_clear_scheduled_hook( 'profotograaf_backup_upload' ); wp_clear_scheduled_hook( 'profotograaf_backup_backfill' );",
			"@unlink( WPMU_PLUGIN_DIR . '/e2e-backup.php' );",
		].join( ' ' )
	);
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Backup status', () => {
	/** @type {{ failed: number, plain: number }} */
	let ids;

	test.beforeAll( () => {
		cleanUp();
		ids = php(
			[
				`wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/e2e-backup.php', base64_decode( '${ Buffer.from( MU_PLUGIN ).toString( 'base64' ) }' ) );`,
				"update_option( 'profotograaf_connection', array( 'access_token' => 'access-e2e', 'refresh_token' => 'refresh-e2e', 'expires_at' => time() + 86400, 'device_id' => 'device-e2e', 'connected_at' => time(), 'last_error' => '', 'scopes' => array( 'galleries:read', 'leads:write', 'galleries:embed', 'galleries:write' ) ), false );",
				"update_option( 'profotograaf_settings', array( 'backup' => true, 'backup_scope' => 'marked' ) );",
				"update_option( 'profotograaf_backup_cursor', array( 'cursor' => PHP_INT_MAX, 'complete' => true ) );",
				"update_option( 'profotograaf_backup_last_run', array( 'at' => time() - 120, 'sent' => 0, 'stop' => 'empty', 'last_sent' => 0, 'idle_since' => 0 ) );",
				'$dir = wp_upload_dir(); $out = array();',
				"foreach ( array( 'failed', 'plain' ) as $name ) {",
				`  $file = $dir['path'] . '/pf-backup-e2e-' . $name . '.png'; file_put_contents( $file, base64_decode( '${ PNG }' ) );`,
				"  $id = wp_insert_attachment( array( 'post_title' => 'Backup E2E ' . $name, 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file );",
				"  update_post_meta( $id, 'pf_e2e_backup', '1' ); $out[ $name ] = $id;",
				'}',
				"update_post_meta( $out['failed'], '_profotograaf_backup_state', 'failed' ); update_post_meta( $out['failed'], '_profotograaf_backup_reason', 'http_500' );",
				'echo "\\nJSON:" . wp_json_encode( $out ) . "\\n";',
			].join( ' ' )
		);
	} );

	test.afterAll( async ( { browser } ) => {
		// Put the Media Library back in the grid view for the other specs.
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.close();
		cleanUp();
	} );

	test( 'the Backup tab shows the status line and the failed file', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf&tab=backup' );

		await expect( page.getByRole( 'heading', { name: 'Backup status' } ) ).toBeVisible();
		await expect( page.getByText( '0 of 1 file backed up.' ) ).toBeVisible();
		await expect( page.getByText( '1 file waits for you, see the list below.' ) ).toBeVisible();
		await expect( page.getByText( /Last run \d+ minutes? ago\./ ) ).toBeVisible();

		const row = page.locator( '.profotograaf-backup-failures tbody tr' );
		await expect( row ).toHaveCount( 1 );
		await expect( row ).toContainText( 'Backup E2E failed' );
		await expect( row ).toContainText( 'Backup failed' );
		await expect( row ).toContainText( 'Profotograaf answered with error 500.' );
	} );

	test( 'Back up now schedules a run and says so', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf&tab=backup' );
		await page.getByRole( 'button', { name: 'Back up now' } ).click();

		await expect( page.getByText( 'The backup starts within a minute, in the background. You can leave this page.' ) ).toBeVisible();
		expect( page.url() ).toContain( 'tab=backup' );
		const next = Number( wp( 'eval', "echo (int) wp_next_scheduled( 'profotograaf_backup_upload' );" ) );
		expect( next ).toBeGreaterThan( 0 );
		expect( Math.abs( next - Date.now() / 1000 ) ).toBeLessThan( 600 );
	} );

	test( 'the Media Library list shows the Backup column and marks files in bulk', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/upload.php?mode=list' );

		await expect( page.locator( 'th#profotograaf_backup' ) ).toHaveText( 'Backup' );
		const failed = page.locator( `#post-${ ids.failed } td.column-profotograaf_backup` );
		await expect( failed ).toContainText( 'Backup failed' );
		await expect( failed ).toContainText( 'Profotograaf answered with error 500.' );
		await expect( page.locator( `#post-${ ids.plain } td.column-profotograaf_backup` ) ).toHaveText( '' );

		await page.locator( `#cb-select-${ ids.plain }` ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'profotograaf_backup_mark' );
		await page.locator( '#doaction' ).click();

		await expect( page.getByText( '1 file is marked for the backup. It is copied to Profotograaf in the background.' ) ).toBeVisible();
		await expect( page.locator( `#post-${ ids.plain } td.column-profotograaf_backup` ) ).toHaveText( 'Waiting to be backed up' );
	} );

	test( 'Retry failed queues the failed file again', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf&tab=backup' );
		await page.getByRole( 'button', { name: 'Retry failed' } ).click();

		await expect( page.getByText( '1 file will be sent again in the background.' ) ).toBeVisible();
		await expect( page.locator( '.profotograaf-backup-failures' ) ).toHaveCount( 0 );
		await expect( page.getByRole( 'button', { name: 'Retry failed' } ) ).toHaveCount( 0 );
		expect( wp( 'post', 'meta', 'get', String( ids.failed ), '_profotograaf_backup_state' ) ).toBe( 'queued' );
	} );
} );
