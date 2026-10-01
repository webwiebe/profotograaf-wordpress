// @ts-check
const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { login, mock } = require( './helpers' );

const compose = path.resolve( __dirname, 'docker-compose.yml' );
const SETTINGS_URL = '/wp-admin/options-general.php?page=profotograaf&tab=advanced';
const CONNECTION = '{"access_token":"a","refresh_token":"r","expires_at":4102444800,"device_id":"d","connected_at":1700000000,"last_error":""}';

/**
 * Runs WP-CLI in the E2E WordPress container.
 *
 * @param {...string} args
 * @return {string} Standard output.
 */
function wp( ...args ) {
	return execFileSync( 'docker', [ 'compose', '-f', compose, 'run', '--rm', '-T', 'cli', 'wp', ...args ], { encoding: 'utf8', timeout: 180_000 } );
}

/**
 * Whether an option exists.
 *
 * @param {string} name
 * @return {boolean}
 */
function hasOption( name ) {
	try {
		wp( 'option', 'get', name );
		return true;
	} catch {
		return false;
	}
}

/**
 * Leaves the site as the other tests expect it.
 */
function cleanUp() {
	for ( const name of [ 'profotograaf_connection', 'profotograaf_settings', 'profotograaf_telemetry_prompt', 'profotograaf_telemetry_queued_batches' ] ) {
		wp( 'option', 'delete', name );
	}
}

test.describe( 'Telemetry consent', () => {
	test.beforeEach( async ( { page } ) => {
		await mock( '/__reset' );
		cleanUp();
		await login( page );
	} );

	test.afterEach( () => {
		cleanUp();
	} );

	test( 'a site that has not connected sees no prompt and telemetry is off', async ( { page } ) => {
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.profotograaf-notice--telemetry' ) ).toHaveCount( 0 );

		await page.goto( SETTINGS_URL );
		await expect( page.getByLabel( 'Share anonymous usage data' ) ).not.toBeChecked();
		await expect( page.getByRole( 'link', { name: 'What is shared' } ) ).toHaveAttribute( 'href', /docs\/telemetry\.md$/ );
	} );

	test( 'opting in through the prompt turns telemetry on and removes the prompt', async ( { page } ) => {
		wp( 'option', 'update', 'profotograaf_connection', CONNECTION, '--format=json' );

		await page.goto( '/wp-admin/index.php' );
		const prompt = page.locator( '.profotograaf-notice--telemetry' );
		await expect( prompt ).toHaveCount( 1 );
		await prompt.getByRole( 'button', { name: 'Enable telemetry' } ).click();

		await expect( page.locator( '.profotograaf-notice--telemetry' ) ).toHaveCount( 0 );
		await page.goto( SETTINGS_URL );
		await expect( page.getByLabel( 'Share anonymous usage data' ) ).toBeChecked();
	} );

	test( 'not now hides the prompt and leaves telemetry off', async ( { page } ) => {
		wp( 'option', 'update', 'profotograaf_connection', CONNECTION, '--format=json' );

		await page.goto( '/wp-admin/index.php' );
		await page.locator( '.profotograaf-notice--telemetry' ).getByRole( 'button', { name: 'Not now' } ).click();

		await expect( page.locator( '.profotograaf-notice--telemetry' ) ).toHaveCount( 0 );
		await page.goto( SETTINGS_URL );
		await expect( page.getByLabel( 'Share anonymous usage data' ) ).not.toBeChecked();
	} );

	test( 'opting out on the settings screen clears the queued batches', async ( { page } ) => {
		await page.goto( SETTINGS_URL );
		await page.getByLabel( 'Share anonymous usage data' ).check();
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByLabel( 'Share anonymous usage data' ) ).toBeChecked();

		wp( 'option', 'update', 'profotograaf_telemetry_queued_batches', '[{"type":"usage"}]', '--format=json' );
		expect( hasOption( 'profotograaf_telemetry_queued_batches' ) ).toBe( true );

		await page.getByLabel( 'Share anonymous usage data' ).uncheck();
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		await expect( page.getByLabel( 'Share anonymous usage data' ) ).not.toBeChecked();
		expect( hasOption( 'profotograaf_telemetry_queued_batches' ) ).toBe( false );
	} );
} );
