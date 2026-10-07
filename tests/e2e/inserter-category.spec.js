// @ts-check
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );

/**
 * Runs wp-cli in the WordPress stack started by up.sh.
 *
 * @param {string[]} args
 */
function wp( ...args ) {
	return execFileSync(
		'docker',
		[ 'compose', '-f', COMPOSE, 'run', '--rm', '-T', 'cli', 'wp', ...args ],
		{ encoding: 'utf8', timeout: 120_000 }
	).trim();
}

/**
 * Switches the media source setting on or off.
 *
 * @param {boolean} on
 */
function setMediaSource( on ) {
	wp(
		'eval',
		`$o = get_option( 'profotograaf_settings', array() ); $o['media_source'] = ${ on ? 'true' : 'false' }; update_option( 'profotograaf_settings', $o );`
	);
}

/**
 * Attachments that carry the photo meta for one platform photo.
 *
 * @param {string} photoId
 */
function importedCount( photoId ) {
	return Number(
		wp( 'post', 'list', '--post_type=attachment', '--post_status=any', '--meta_key=_profotograaf_photo_id', `--meta_value=${ photoId }`, '--format=count' )
	);
}

/**
 * Opens a new post and its inserter on the media tab.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openMediaTab( page ) {
	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp && window.wp.data && window.wp.blocks && window.wp.data.select( 'core/block-editor' ) );
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'fullscreenMode', false );
		window.wp.data.dispatch( 'core/editor' ).setIsInserterOpened( true );
	} );
	await page.getByRole( 'tab', { name: 'Media' } ).click();
}

/**
 * The attachment ids of the image blocks in the open post.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<number[]>}
 */
function imageIds( page ) {
	return page.evaluate( () =>
		window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.filter( ( block ) => block.name === 'core/image' )
			.map( ( block ) => block.attributes.id || 0 )
	);
}

test.describe( 'Profotograaf category in the block inserter', () => {
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

		// Photos imported by an earlier run would be inserted by id and skip the upload.
		wp(
			'eval',
			"foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_profotograaf_photo_id', 'fields' => 'ids' ) ) as $id ) { wp_delete_attachment( $id, true ); }"
		);

		// The mock listens on a port and host the safe HTTP functions refuse by default.
		wp(
			'eval',
			"wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/e2e-http.php', '<?php add_filter( \"http_request_host_is_external\", \"__return_true\" ); add_filter( \"http_allowed_safe_ports\", function ( $p ) { $p[] = 8090; return $p; } );' );"
		);
	} );

	test.afterAll( async ( { browser } ) => {
		setMediaSource( false );
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'the category is missing while the setting is off', async ( { page } ) => {
		setMediaSource( false );
		await login( page );
		await openMediaTab( page );

		await expect( page.getByRole( 'tabpanel', { name: 'Media' } ) ).toBeVisible();
		await expect( page.getByRole( 'tab', { name: 'Profotograaf' } ) ).toHaveCount( 0 );
	} );

	test( 'inserting a photo imports it once with the meta, and inserting it again reuses it', async ( { page } ) => {
		setMediaSource( true );
		await login( page );
		await openMediaTab( page );

		await page.getByRole( 'tab', { name: 'Profotograaf' } ).click();
		const bride = page.getByRole( 'option', { name: 'Bride' } );
		await expect( bride ).toBeVisible( { timeout: 30_000 } );
		await bride.click();

		await expect.poll( async () => ( await imageIds( page ) )[ 0 ] ?? 0, { timeout: 30_000 } ).toBeGreaterThan( 0 );
		const [ first ] = await imageIds( page );
		expect( importedCount( 'p-1' ) ).toBe( 1 );
		expect( wp( 'post', 'meta', 'get', String( first ), '_profotograaf_gallery_id' ) ).toBe( 'g-e2e' );
		expect( wp( 'post', 'meta', 'get', String( first ), '_profotograaf_version' ) ).toBe( '0123456789ab' );
		// The block preview in the inserter loads the same URL, so the first insert may download more than once.
		const downloads = ( await mock( '/__state', 'GET' ) ).jpegHits.filter( ( hit ) => hit.includes( '/p-1/web-' ) );
		expect( downloads.length ).toBeGreaterThan( 0 );

		// A fresh editor: the list now carries the attachment id, so core inserts without uploading.
		await openMediaTab( page );
		await page.getByRole( 'tab', { name: 'Profotograaf' } ).click();
		await expect( bride ).toBeVisible( { timeout: 30_000 } );
		await bride.click();

		await expect.poll( async () => ( await imageIds( page ) )[ 0 ] ?? 0, { timeout: 30_000 } ).toBe( first );
		expect( importedCount( 'p-1' ) ).toBe( 1 );
		const again = ( await mock( '/__state', 'GET' ) ).jpegHits.filter( ( hit ) => hit.includes( '/p-1/web-' ) );
		expect( again ).toHaveLength( downloads.length );
	} );

	test( 'a platform outage shows an empty list and leaves the editor usable', async ( { page } ) => {
		setMediaSource( true );
		await login( page );
		await page.route( '**/profotograaf/v1/photos*', ( route ) => route.fulfill( { status: 502, contentType: 'application/json', body: '{"code":"profotograaf_http","message":"down","data":{"status":502}}' } ) );
		await openMediaTab( page );

		await page.getByRole( 'tab', { name: 'Profotograaf' } ).click();

		await expect( page.getByRole( 'option' ) ).toHaveCount( 0 );
		await expect( page.locator( '.components-spinner' ) ).toHaveCount( 0, { timeout: 15_000 } );
		await expect( page.getByRole( 'searchbox' ).first() ).toBeEnabled();
		await expect( page.getByRole( 'tab', { name: 'Blocks' } ) ).toBeVisible();
	} );
} );
