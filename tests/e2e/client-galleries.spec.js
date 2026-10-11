// @ts-check
const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { login, mock } = require( './helpers' );

const compose = [
	'compose',
	'-f',
	path.join( __dirname, 'docker-compose.yml' ),
	'--profile',
	'tools',
];

/**
 * Creates a published page with the given content through WP-CLI and returns its URL.
 *
 * @param {string} title
 * @param {string} content
 */
function createPage( title, content ) {
	const output = execFileSync(
		'docker',
		[ ...compose, 'run', '--rm', '-T', 'cli', 'wp', 'post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${ title }`, `--post_content=${ content }`, '--porcelain' ],
		{ encoding: 'utf8' }
	);
	return `/?page_id=${ output.trim() }`;
}

test.describe( 'Client galleries block, logged out', () => {
	test( 'links to the client portal without being connected', async ( { page } ) => {
		const url = createPage( 'Client galleries E2E', '<!-- wp:profotograaf/client-galleries {"portal":"studio","heading":"Find your gallery"} /-->' );

		await page.goto( url );

		const block = page.locator( '.wp-block-profotograaf-client-galleries' );
		await expect( block ).toBeVisible();
		await expect( block.getByRole( 'heading', { name: 'Find your gallery' } ) ).toBeVisible();
		await expect( block.getByRole( 'link', { name: 'Open my gallery' } ) ).toHaveAttribute( 'href', /\/studio\/client$/ );
	} );

	test( 'a block without an address shows nothing to visitors', async ( { page } ) => {
		const url = createPage( 'Client galleries empty E2E', '<!-- wp:profotograaf/client-galleries /-->' );

		await page.goto( url );

		await expect( page.locator( '.wp-block-profotograaf-client-galleries' ) ).toHaveCount( 0 );
		await expect( page.getByText( 'Only editors see this note' ) ).toHaveCount( 0 );
	} );
} );

test.describe( 'Client galleries block linked to one gallery, logged out', () => {
	test( 'opens the gallery share link in the same tab and says nothing about installing', async ( { page } ) => {
		const url = createPage(
			'Client gallery link E2E',
			'<!-- wp:profotograaf/client-galleries {"portal":"studio","openInNewTab":true,"gallery":"https://profotograaf.nl/share/g/spring-wedding","linkTo":"gallery"} /-->'
		);

		await page.goto( url );

		const block = page.locator( '.wp-block-profotograaf-client-galleries' );
		await expect( block.getByRole( 'heading', { name: 'Your photos' } ) ).toBeVisible();
		const link = block.getByRole( 'link', { name: 'Open your gallery' } );
		await expect( link ).toHaveAttribute( 'href', 'https://profotograaf.nl/share/g/spring-wedding' );
		await expect( link ).not.toHaveAttribute( 'target', /.*/ );
		await expect( block ).not.toContainText( /install/i );
	} );

	test( 'the variation without a gallery shows nothing to visitors', async ( { page } ) => {
		const url = createPage( 'Client gallery link empty E2E', '<!-- wp:profotograaf/client-galleries {"portal":"studio","linkTo":"gallery"} /-->' );

		await page.goto( url );

		await expect( page.locator( '.wp-block-profotograaf-client-galleries' ) ).toHaveCount( 0 );
	} );
} );

test.describe( 'Open a delivered gallery, in the editor', () => {
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

	test.afterAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'the variation asks for a gallery and stores the picked share link', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction( () => window.wp && window.wp.data && window.wp.blocks && window.wp.data.select( 'core/block-editor' ) );
		const variation = await page.evaluate( () => {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'fullscreenMode', false );
			const found = window.wp.blocks.getBlockVariations( 'profotograaf/client-galleries' ).find( ( v ) => v.name === 'delivered-gallery' );
			window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( 'profotograaf/client-galleries', found.attributes ) );
			return found.title;
		} );
		expect( variation ).toBe( 'Open a delivered gallery' );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await expect( canvas.getByText( 'Choose the gallery this button opens.' ) ).toBeVisible( { timeout: 20_000 } );
		await canvas.getByRole( 'button', { name: /Spring wedding/ } ).click();

		await expect( canvas.getByText( 'Open your gallery' ) ).toBeVisible();
		const gallery = await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ].attributes.gallery );
		expect( gallery ).toMatch( /\/share\/g\/spring-wedding$/ );
	} );
} );
