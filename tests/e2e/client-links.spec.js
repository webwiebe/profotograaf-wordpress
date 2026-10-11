// @ts-check
const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { login } = require( './helpers' );

const compose = path.resolve( __dirname, 'docker-compose.yml' );

/**
 * Runs WP-CLI in the E2E WordPress container.
 *
 * @param {...string} args
 * @return {string} Standard output.
 */
function wp( ...args ) {
	return execFileSync( 'docker', [ 'compose', '-f', compose, 'run', '--rm', '-T', 'cli', 'wp', ...args ], { encoding: 'utf8', timeout: 180_000 } );
}

const SETTINGS = '/wp-admin/options-general.php?page=profotograaf&tab=galleries';

// The gallery list as the gallery picker stores it (Gallery_Index).
const STORED = {
	'g-e2e': { title: 'Spring wedding', url: 'https://profotograaf.nl/share/g/spring-wedding', layout: '', embed_layout: '' },
	'g-e2e-2': { title: 'Autumn family', url: 'https://studio.profotograaf.nl/share/g/autumn-family', layout: '', embed_layout: '' },
};

test.describe( 'Settings > Profotograaf, client galleries list', () => {
	test.afterAll( () => {
		wp( 'option', 'delete', 'profotograaf_gallery_index' );
	} );

	test( 'says how the list fills while no gallery is stored', async ( { page } ) => {
		wp( 'option', 'delete', 'profotograaf_gallery_index' );
		await login( page );
		await page.goto( SETTINGS );

		await expect( page.getByRole( 'heading', { name: 'Client galleries' } ) ).toBeVisible();
		await expect( page.getByText( 'No galleries are stored on this site yet.' ) ).toBeVisible();
		await expect( page.locator( '.profotograaf-client-links' ) ).toHaveCount( 0 );
	} );

	test( 'lists stored galleries with share link, copy button and QR code', async ( { page, context } ) => {
		wp( 'option', 'update', 'profotograaf_gallery_index', JSON.stringify( STORED ), '--format=json' );
		await context.grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
		await login( page );
		await page.goto( SETTINGS );

		const list = page.locator( '.profotograaf-client-links' );
		await expect( list.getByRole( 'rowheader' ) ).toHaveText( [ 'Autumn family', 'Spring wedding' ] );

		const wedding = list.getByRole( 'row', { name: /Spring wedding/ } );
		await expect( wedding.getByRole( 'textbox', { name: 'Share link of Spring wedding' } ) ).toHaveValue( 'https://profotograaf.nl/share/g/spring-wedding' );
		await expect( wedding.getByRole( 'img', { name: 'QR code with the share link of Spring wedding' } ) ).toBeVisible();

		await wedding.getByRole( 'button', { name: 'Copy link' } ).click();
		await expect( wedding.getByRole( 'status' ) ).toHaveText( /Link copied\.|The link is selected/ );

		await expect( page.getByText( 'when the gallery is delivered and has no password' ) ).toBeVisible();
		await expect( page.getByText( 'This list does not show which galleries meet both conditions.' ) ).toBeVisible();
	} );

	test( 'the General tab shows no list', async ( { page } ) => {
		wp( 'option', 'update', 'profotograaf_gallery_index', JSON.stringify( STORED ), '--format=json' );
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf&tab=general' );

		await expect( page.getByRole( 'heading', { name: 'Client galleries' } ) ).toHaveCount( 0 );
	} );
} );
