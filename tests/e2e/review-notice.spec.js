// @ts-check
const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { login, mock } = require( './helpers' );

const compose = path.resolve( __dirname, 'docker-compose.yml' );
const CONNECTION = '{"access_token":"a","refresh_token":"r","expires_at":4102444800,"device_id":"d","connected_at":1700000000,"last_error":""}';
const STATUS = '{"fetched_at":1760000000,"review_prompt":{"eligible":true,"reason":"first_client_download","at":"2026-10-09T10:00:00Z"}}';
const OPTIONS = [ 'profotograaf_connection', 'profotograaf_settings', 'profotograaf_platform_status', 'profotograaf_review_state', 'profotograaf_telemetry_prompt' ];

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
 * Leaves the site as the other tests expect it.
 */
function cleanUp() {
	for ( const name of OPTIONS ) {
		try {
			wp( 'option', 'delete', name );
		} catch {
			// The option does not exist yet.
		}
	}
	try {
		wp( 'cron', 'event', 'delete', 'profotograaf_platform_status_soon' );
	} catch {
		// No event is queued.
	}
}

/**
 * Runs the queued status calls the way WP-Cron would.
 */
function runStatusCalls() {
	for ( let attempt = 0; attempt < 2; attempt++ ) {
		try {
			wp( 'cron', 'event', 'run', 'profotograaf_platform_status_soon' );
		} catch {
			// The site's own WP-Cron already ran the event.
		}
	}
}

test.describe( 'Review notice', () => {
	test.beforeEach( async ( { page } ) => {
		await mock( '/__reset' );
		cleanUp();
		wp( 'option', 'update', 'profotograaf_connection', CONNECTION, '--format=json' );
		await login( page );
	} );

	test.afterEach( () => {
		cleanUp();
	} );

	test( 'a site the platform has not cleared sees no notice', async ( { page } ) => {
		await page.goto( '/wp-admin/index.php' );

		await expect( page.locator( '.profotograaf-notice--review' ) ).toHaveCount( 0 );
	} );

	test( 'an eligible site sees the notice on the dashboard and the settings page only', async ( { page } ) => {
		wp( 'option', 'update', 'profotograaf_platform_status', STATUS, '--format=json' );

		await page.goto( '/wp-admin/index.php' );
		const notice = page.locator( '.profotograaf-notice--review' );
		await expect( notice ).toHaveCount( 1 );
		await expect( notice ).toContainText( 'downloaded photos' );

		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await expect( page.locator( '.profotograaf-notice--review' ) ).toHaveCount( 1 );

		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( '.profotograaf-notice--review' ) ).toHaveCount( 0 );
	} );

	test( 'Later hides the notice and reports shown and later to the platform', async ( { page } ) => {
		wp( 'option', 'update', 'profotograaf_platform_status', STATUS, '--format=json' );
		await mock( '/__review' );

		await page.goto( '/wp-admin/index.php' );
		await page.locator( '.profotograaf-notice--review' ).getByRole( 'link', { name: 'Later' } ).click();
		await expect( page.locator( '.profotograaf-notice--review' ) ).toHaveCount( 0 );
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.profotograaf-notice--review' ) ).toHaveCount( 0 );

		runStatusCalls();
		const { statusCalls } = await mock( '/__state', 'GET' );
		expect( statusCalls.map( ( /** @type {{review_event?: string}} */ call ) => call.review_event ) ).toEqual( [ 'shown', 'later' ] );
	} );

	test( 'Leave a review records clicked and the notice stays gone', async ( { page, context } ) => {
		wp( 'option', 'update', 'profotograaf_platform_status', STATUS, '--format=json' );
		await mock( '/__review' );
		await context.route( 'https://wordpress.org/**', ( route ) => route.fulfill( { status: 200, contentType: 'text/html', body: '<title>Reviews</title>' } ) );

		await page.goto( '/wp-admin/index.php' );
		const popup = context.waitForEvent( 'page' );
		await page.locator( '.profotograaf-notice--review' ).getByRole( 'link', { name: 'Leave a review' } ).click();
		await ( await popup ).waitForURL( /wordpress\.org\/support\/plugin\/profotograaf\/reviews/ );

		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.profotograaf-notice--review' ) ).toHaveCount( 0 );

		runStatusCalls();
		const { statusCalls } = await mock( '/__state', 'GET' );
		expect( statusCalls.map( ( /** @type {{review_event?: string}} */ call ) => call.review_event ) ).toEqual( [ 'shown', 'clicked' ] );
	} );

	test( "Don't ask again removes the notice for good", async ( { page } ) => {
		wp( 'option', 'update', 'profotograaf_platform_status', STATUS, '--format=json' );

		await page.goto( '/wp-admin/index.php' );
		await page.locator( '.profotograaf-notice--review' ).getByRole( 'link', { name: "Don't ask again" } ).click();

		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await expect( page.locator( '.profotograaf-notice--review' ) ).toHaveCount( 0 );
	} );
} );
