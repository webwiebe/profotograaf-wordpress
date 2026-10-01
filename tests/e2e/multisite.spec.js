// @ts-check
const { test, expect } = require( '@playwright/test' );
const { login } = require( './helpers' );

// Runs only in the multisite job, which converts the E2E site to a network
// (tests/e2e/multisite.sh) and sets PROFOTOGRAAF_MULTISITE=1.
test.skip( ! process.env.PROFOTOGRAAF_MULTISITE, 'needs the multisite E2E stack' );

test.describe( 'Network admin > Settings > Profotograaf', () => {
	test( 'lists every subsite with its connection state', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/network/settings.php?page=profotograaf-network' );

		await expect( page.getByRole( 'heading', { level: 1, name: 'Profotograaf' } ) ).toBeVisible();
		await expect( page.getByRole( 'row', { name: /Subsite One/ } ) ).toContainText( 'Not connected' );
		await expect( page.getByRole( 'row', { name: /Subsite Two/ } ) ).toContainText( 'Not connected' );
	} );
} );
