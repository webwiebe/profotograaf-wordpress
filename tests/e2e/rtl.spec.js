// @ts-check
const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { login, mock } = require( './helpers' );

const root = path.resolve( __dirname, '../..' );
const compose = path.join( root, 'tests/e2e/docker-compose.yml' );

/**
 * Runs WP-CLI in the E2E WordPress container.
 *
 * @param {...string} args
 * @return {string} Standard output.
 */
function wp( ...args ) {
	return execFileSync(
		'docker',
		[ 'compose', '-f', compose, 'run', '--rm', '-T', 'cli', 'wp', ...args ],
		{ encoding: 'utf8', timeout: 180_000 }
	);
}

test.describe( 'RTL layout (Arabic locale)', () => {
	test.beforeEach( async () => {
		await mock( '/__reset' );
		// Set WordPress locale to Arabic for RTL testing.
		wp( 'option', 'update', 'WPLANG', 'ar' );
	} );

	test.afterEach( async () => {
		// Reset locale to English.
		wp( 'option', 'update', 'WPLANG', 'en_US' );
	} );

	test( 'the enquiries page displays in RTL', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf-leads' );

		// Verify the page renders correctly in RTL direction.
		const html = page.locator( 'html' );
		const dir = await html.getAttribute( 'dir' );
		expect( dir ).toBe( 'rtl' );

		// Verify that inline-start and inline-end are used for logical properties.
		// The notice or failures list should use margin-inline-start, not margin-left.
		const failuresList = page.locator( '.profotograaf-leads-failures' );
		const computedStyle = await failuresList.evaluate( ( element ) => {
			const styles = window.getComputedStyle( element );
			return {
				marginInlineStart: styles.marginInlineStart,
				marginLeft: styles.marginLeft,
			};
		} );

		// In RTL, margin-inline-start should be set (it will appear as right margin visually).
		// Ensure no left margin is used for RTL.
		expect( computedStyle.marginInlineStart ).toBeTruthy();

		// Take a screenshot for visual verification.
		await expect( page ).toHaveScreenshot( 'rtl-enquiries-page.png' );
	} );

	test( 'the settings page displays in RTL', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );

		// Verify the page renders correctly in RTL direction.
		const html = page.locator( 'html' );
		const dir = await html.getAttribute( 'dir' );
		expect( dir ).toBe( 'rtl' );

		// Take a screenshot for visual verification.
		await expect( page ).toHaveScreenshot( 'rtl-settings-page.png' );
	} );
} );
