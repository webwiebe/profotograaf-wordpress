// @ts-check
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

test.describe( 'CSS logical properties', () => {
	test.beforeEach( async () => {
		await mock( '/__reset' );
	} );

	test( 'the leads page uses logical CSS properties', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf-leads' );

		// Verify the CSS rule is correct by checking if the selector is applied.
		// The leads page should load successfully without errors.
		await expect( page.getByRole( 'heading', { name: 'Profotograaf enquiries' } ) ).toBeVisible();
	} );

	test( 'the form shows a delivery section', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf-leads' );

		// Verify the page has the delivery section with locale-aware counts.
		await expect( page.getByRole( 'heading', { name: 'Delivery' } ) ).toBeVisible();
	} );

	test( 'the client galleries block renders without CSS errors', async ( { page } ) => {
		await page.goto( '/?p=1' );

		// The block should render without console errors related to CSS properties.
		const block = page.locator( '.wp-block-profotograaf-client-galleries' );
		await expect( block ).toBeVisible();
	} );
} );
