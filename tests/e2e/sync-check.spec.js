// @ts-check
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

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
} );
