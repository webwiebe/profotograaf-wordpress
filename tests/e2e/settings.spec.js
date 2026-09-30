// @ts-check
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

test.describe( 'Settings > Profotograaf', () => {
	test.beforeEach( async ( { page } ) => {
		await mock( '/__reset' );
		await login( page );
	} );

	test( 'the plugin is active and the settings page loads', async ( { page } ) => {
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );

		await expect( page.getByRole( 'heading', { level: 1, name: 'Profotograaf' } ) ).toBeVisible();
		await expect( page.getByText( 'Not connected' ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Connect to Profotograaf' } ) ).toBeVisible();
		await expect( page.getByLabel( 'Default gallery layout' ) ).toHaveValue( 'grid' );
	} );

	test( 'the menu entry sits under Settings', async ( { page } ) => {
		await page.goto( '/wp-admin/options-general.php' );

		await expect( page.locator( '#menu-settings' ).getByRole( 'link', { name: 'Profotograaf' } ) ).toBeVisible();
	} );

	test( 'the default layout is saved', async ( { page } ) => {
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByLabel( 'Default gallery layout' ).selectOption( 'masonry' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		await expect( page.getByLabel( 'Default gallery layout' ) ).toHaveValue( 'masonry' );

		// Leave the default as found so the tests can run again.
		await page.getByLabel( 'Default gallery layout' ).selectOption( 'grid' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByLabel( 'Default gallery layout' ) ).toHaveValue( 'grid' );
	} );

	test( 'connect shows the code, approval connects the site and disconnect clears it', async ( { page } ) => {
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Connect to Profotograaf' } ).click();

		await expect( page.locator( '.profotograaf-code' ) ).toHaveText( 'WXYZ-2346' );
		const approve = page.getByRole( 'link', { name: 'Open the approval page' } );
		await expect( approve ).toHaveAttribute( 'href', /\/app\/devices\/approve\?code=WXYZ-2346$/ );
		await expect( page.getByRole( 'status' ) ).toContainText( 'Waiting for you to approve' );

		// The photographer approves in the platform. The page polls and reloads.
		await mock( '/__approve' );
		await expect( page.getByText( 'Connected to Profotograaf.' ) ).toBeVisible( { timeout: 20_000 } );
		await expect( page.getByRole( 'button', { name: 'Disconnect' } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'Embedding on this site' } ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await expect( page.getByText( 'Not connected' ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Connect to Profotograaf' } ) ).toBeVisible();
	} );

	test( 'cancel abandons a pairing', async ( { page } ) => {
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Connect to Profotograaf' } ).click();
		await expect( page.locator( '.profotograaf-code' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Cancel' } ).click();

		await expect( page.getByRole( 'button', { name: 'Connect to Profotograaf' } ) ).toBeVisible();
	} );

	test( 'a forged action without a nonce is refused', async ( { page } ) => {
		const response = await page.request.post( '/wp-admin/admin-post.php', {
			form: { action: 'profotograaf_disconnect' },
		} );

		expect( response.status() ).toBeGreaterThanOrEqual( 400 );
	} );
} );
