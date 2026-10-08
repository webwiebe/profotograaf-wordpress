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

/** Switches "Use Profotograaf photos in the editor" on or off. */
function mediaSource( on ) {
	wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: on } ), '--format=json' );
}

/** Removes the imported photos and the stored catalogue, so the spec can run again. */
function cleanUp() {
	const ids = wp( 'post', 'list', '--post_type=attachment', '--post_status=any', '--field=ID' );
	if ( ids !== '' ) {
		wp( 'post', 'delete', ...ids.split( /\s+/ ), '--force' );
	}
	wp( 'eval', "delete_option( 'profotograaf_photo_catalogue' );" );
}

/**
 * Opens a new post with an empty Gallery block and returns the modal.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openGalleryModal( page ) {
	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp && window.wp.data && window.wp.blocks && window.wp.data.select( 'core/block-editor' ) );
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		const block = window.wp.blocks.createBlock( 'core/gallery' );
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
	} );
	// The block editor draws blocks inside the editor canvas iframe.
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.getByRole( 'button', { name: 'Media Library' } ).click();
	const modal = page.locator( '.media-modal' );
	await expect( modal ).toBeVisible();
	return modal;
}


/**
 * Opens a new post, opens its Featured image panel and the media modal on the Profotograaf tab.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openFeaturedImageModal( page ) {
	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/editor' ) );
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' );
	} );
	// The panel is open by default, so the button is in the document sidebar.
	await page.getByRole( 'button', { name: 'Set featured image' } ).click();
	const modal = page.locator( '.media-modal' );
	await expect( modal ).toBeVisible();
	await modal.getByRole( 'tab', { name: 'Profotograaf' } ).click();
	return modal;
}

/**
 * Picks the Bride photo in the modal and confirms, then saves the post.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} modal
 * @return {Promise<number>} The post id.
 */
async function pickBrideAsFeaturedImage( page, modal ) {
	const bride = modal.getByRole( 'button', { name: 'Bride' } );
	await expect( bride ).toBeVisible( { timeout: 30_000 } );
	await bride.click();
	await modal.locator( '.media-toolbar-primary .button-primary' ).click();
	await expect
		.poll( () => page.evaluate( () => window.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'featured_media' ) ), { timeout: 60_000 } )
		.toBeGreaterThan( 0 );
	await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'Featured image test' } ) );
	await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).savePost() );
	await expect
		.poll( () => page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPost().featured_media ), { timeout: 30_000 } )
		.toBeGreaterThan( 0 );
	return page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() );
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Profotograaf tab in the media modal', () => {
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

		// The mock listens on a port and a private address the safe HTTP functions refuse by default.
		wp(
			'eval',
			"wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/e2e-http.php', '<?php add_filter( \"http_request_host_is_external\", \"__return_true\" ); add_filter( \"http_allowed_safe_ports\", function ( $p ) { $p[] = 8090; return $p; } );' );"
		);
		cleanUp();
	} );

	test.afterAll( async ( { browser } ) => {
		mediaSource( false );
		cleanUp();
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'the tab is missing while the setting is off', async ( { page } ) => {
		mediaSource( false );
		await login( page );

		const modal = await openGalleryModal( page );

		await expect( modal.getByRole( 'tab', { name: 'Media Library' } ).first() ).toBeVisible();
		await expect( modal.getByRole( 'tab', { name: 'Profotograaf' } ) ).toHaveCount( 0 );
	} );

	test( 'adds two Profotograaf photos to a Gallery block as normal attachments', async ( { page } ) => {
		mediaSource( true );
		await login( page );
		const modal = await openGalleryModal( page );

		await modal.getByRole( 'tab', { name: 'Profotograaf' } ).click();
		const bride = modal.getByRole( 'button', { name: 'Bride' } );
		await expect( bride ).toBeVisible( { timeout: 30_000 } );
		await bride.click();
		await modal.getByRole( 'button', { name: 'Rings' } ).click();
		await expect( modal.getByText( '2 photos selected' ) ).toBeVisible();

		// The primary button imports the photos, then the frame goes on to its gallery step.
		await modal.locator( '.media-toolbar-primary .button-primary' ).click();
		await modal.getByRole( 'button', { name: /Insert gallery|Update gallery/ } ).click();

		await expect
			.poll(
				() =>
					page.evaluate( () =>
						window.wp.data
							.select( 'core/block-editor' )
							.getBlocks()
							.filter( ( block ) => block.name === 'core/gallery' )
							.flatMap( ( block ) => block.innerBlocks.map( ( inner ) => inner.attributes.id || 0 ) )
					),
				{ timeout: 60_000 }
			)
			.toHaveLength( 2 );
		const ids = await page.evaluate( () =>
			window.wp.data
				.select( 'core/block-editor' )
				.getBlocks()
				.flatMap( ( block ) => block.innerBlocks.map( ( inner ) => inner.attributes.id ) )
		);
		for ( const id of ids ) {
			expect( id ).toBeGreaterThan( 0 );
			expect( wp( 'post', 'meta', 'get', String( id ), '_profotograaf_gallery_id' ) ).toBe( 'g-e2e' );
		}
		expect( wp( 'post', 'list', '--post_type=attachment', '--meta_key=_profotograaf_photo_id', '--format=count' ) ).toBe( '2' );
	} );

	test( 'sets a Profotograaf photo as the featured image and reuses the attachment', async ( { page } ) => {
		mediaSource( true );
		cleanUp();
		await login( page );

		const first = await pickBrideAsFeaturedImage( page, await openFeaturedImageModal( page ) );

		const thumbnail = wp( 'post', 'meta', 'get', String( first ), '_thumbnail_id' );
		expect( Number( thumbnail ) ).toBeGreaterThan( 0 );
		expect( wp( 'post', 'meta', 'get', thumbnail, '_profotograaf_gallery_id' ) ).toBe( 'g-e2e' );
		expect( wp( 'post', 'meta', 'get', thumbnail, '_profotograaf_photo_id' ) ).not.toBe( '' );

		// Themes and SEO plugins read the thumbnail through core functions and the REST field.
		const check = wp(
			'eval',
			`wp_set_current_user( 1 ); $id = ${ first };$att = (int) get_post_thumbnail_id( $id ); $r = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) ); echo wp_json_encode( array( 'att' => $att, 'html' => str_contains( (string) wp_get_attachment_image( $att, 'large' ), '<img' ), 'url' => str_contains( (string) get_the_post_thumbnail_url( $id, 'full' ), '/wp-content/uploads/' ), 'rest' => (int) $r->get_data()['featured_media'], 'has' => has_post_thumbnail( $id ) ) );`
		);
		const seen = JSON.parse( check );
		expect( seen.att ).toBe( Number( thumbnail ) );
		expect( seen.html ).toBe( true );
		expect( seen.url ).toBe( true );
		expect( seen.rest ).toBe( Number( thumbnail ) );
		expect( seen.has ).toBe( true );

		// The same photo on another post reuses the attachment.
		const second = await pickBrideAsFeaturedImage( page, await openFeaturedImageModal( page ) );
		expect( second ).not.toBe( first );
		expect( wp( 'post', 'meta', 'get', String( second ), '_thumbnail_id' ) ).toBe( thumbnail );
		expect( wp( 'post', 'list', '--post_type=attachment', '--meta_key=_profotograaf_photo_id', '--format=count' ) ).toBe( '1' );
	} );

	test( 'a platform outage shows a message and leaves the modal usable', async ( { page } ) => {
		mediaSource( true );
		await login( page );
		await page.route( /profotograaf(?:\/|%2F)v1(?:\/|%2F)photos(?!(?:\/|%2F))/, ( route ) =>
			route.fulfill( {
				status: 502,
				contentType: 'application/json',
				body: '{"code":"profotograaf_http","message":"Profotograaf is not reachable right now.","data":{"status":502}}',
			} )
		);
		const modal = await openGalleryModal( page );

		await modal.getByRole( 'tab', { name: 'Profotograaf' } ).click();

		await expect( modal.getByText( 'Profotograaf is not reachable right now.' ) ).toBeVisible( { timeout: 30_000 } );
		await modal.getByRole( 'tab', { name: 'Media Library' } ).first().click();
		await expect( modal.locator( '.attachments:visible' ).first() ).toBeVisible();
	} );
} );
