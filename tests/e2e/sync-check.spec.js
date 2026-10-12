// @ts-check
const { spawnSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];
const MU_FILE = 'e2e-sync-check-no-cron.php';

/**
 * Runs PHP inside WordPress in the stack started by up.sh.
 *
 * @param {string} code
 */
function evalPhp( code ) {
	const result = spawnSync( 'docker', [ 'compose', ...PROJECT, '-f', COMPOSE, 'run', '--rm', '-T', 'cli', 'wp', 'eval', code ], { encoding: 'utf8', timeout: 180_000 } );
	if ( result.status !== 0 ) {
		throw new Error( `wp eval failed: ${ result.stderr }` );
	}
	return result.stdout.trim();
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Check now for photo changes', () => {
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
	} );

	test( 'Check now asks the change feed and updates the last checked line', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		const line = page.locator( '.profotograaf-sync__text' );
		await expect( line ).toBeVisible();

		const before = /** @type {unknown[]} */ ( await mock( '/__changes', 'GET' ) ).length;
		await page.getByRole( 'button', { name: 'Check now' } ).click();

		await expect( page.locator( '.profotograaf-sync__status' ) ).toHaveText( 'Photo changes were checked.' );
		await expect( line ).toContainText( 'Photo changes last checked' );
		const after = /** @type {unknown[]} */ ( await mock( '/__changes', 'GET' ) ).length;
		expect( after ).toBeGreaterThan( before );
	} );

	test( 'Check now works on a host where WP-Cron never runs', async ( { page } ) => {
		evalPhp( `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/${ MU_FILE }', "<?php defined( 'DISABLE_WP_CRON' ) || define( 'DISABLE_WP_CRON', true );" );` );
		try {
			await login( page );
			await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
			const before = /** @type {unknown[]} */ ( await mock( '/__changes', 'GET' ) ).length;
			await page.getByRole( 'button', { name: 'Check now' } ).click();

			await expect( page.locator( '.profotograaf-sync__status' ) ).toHaveText( 'Photo changes were checked.' );
			const after = /** @type {unknown[]} */ ( await mock( '/__changes', 'GET' ) ).length;
			expect( after ).toBeGreaterThan( before );
			expect( evalPhp( "echo defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'off' : 'on';" ) ).toBe( 'off' );
		} finally {
			evalPhp( `@unlink( WPMU_PLUGIN_DIR . '/${ MU_FILE }' );` );
		}
	} );
} );
