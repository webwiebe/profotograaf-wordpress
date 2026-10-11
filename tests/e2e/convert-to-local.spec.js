// @ts-check
//
// Issue #152: "Convert to local" for photos kept on Profotograaf (virtual
// attachments). Issue #155: editing the image of such a photo is refused with
// an explanation that points to Convert to local. A row action, a bulk action in the Media Library list view, a
// button in the attachment details and a notice on photos that left the
// catalogue. A conversion keeps the attachment id, stores the file in uploads
// with generated sizes and removes the virtual marks. A failure leaves the
// attachment virtual and says why. Attachments kept while the setting was on
// stay convertible after it was switched off.
//
// The mock runs in scenic mode, so the downloads are real 1280 px photos and
// WordPress generates the usual sizes from them.
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock, MOCK } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];

/**
 * Runs wp-cli in the WordPress stack started by up.sh.
 *
 * @param {string[]} args
 */
function wp( ...args ) {
	return execFileSync( 'docker', [ 'compose', ...PROJECT, '-f', COMPOSE, 'run', '--rm', '-T', 'cli', 'wp', ...args ], {
		encoding: 'utf8',
		timeout: 180_000,
	} ).trim();
}

/**
 * Runs PHP inside WordPress and returns the JSON it echoes after "JSON:".
 *
 * @param {string} code
 * @return {any}
 */
function php( code ) {
	const text = wp( 'eval', `$out = function ( $data ) { echo "\\nJSON:" . wp_json_encode( $data ) . "\\n"; }; ${ code }` );
	const line = text.split( '\n' ).reverse().find( ( l ) => l.startsWith( 'JSON:' ) );
	if ( ! line ) {
		throw new Error( `No JSON line in: ${ text }` );
	}
	return JSON.parse( line.slice( 5 ) );
}

/**
 * Saves the plugin settings with the media source on.
 *
 * @param {boolean} keep Whether "Keep photos on Profotograaf" is on.
 */
function settings( keep ) {
	wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: true, virtual_attachments: keep } ), '--format=json' );
}

/** Removes the photos added to the Media Library and the stored catalogue. */
function cleanUp() {
	wp(
		'eval',
		"foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_profotograaf_photo_id', 'fields' => 'ids' ) ) as $id ) { wp_delete_attachment( $id, true ); } delete_option( 'profotograaf_photo_catalogue' );"
	);
}

/**
 * Keeps photos on Profotograaf as the admin, through the REST route the
 * Import screen uses, from a fresh catalogue. Returns the attachment id by
 * photo id.
 *
 * @param {string[]} ids Photo ids.
 * @return {Record<string, number>}
 */
function keep( ids ) {
	return php(
		"delete_option( 'profotograaf_photo_catalogue' ); wp_set_current_user( get_user_by( 'login', 'admin' )->ID );" +
			` $r = new WP_REST_Request( 'POST', '/profotograaf/v1/photos/keep' ); $r->set_body_params( array( 'ids' => ${ JSON.stringify( ids ).replace( /^\[/, 'array( ' ).replace( /\]$/, ' )' ) } ) );` +
			" $made = array(); foreach ( rest_do_request( $r )->get_data()['results'] as $row ) { $made[ $row['id'] ] = $row['attachment_id']; } $out( $made );"
	);
}

/**
 * The stored state of an attachment.
 *
 * @param {number} id Attachment id.
 */
function stateOf( id ) {
	return php(
		`$id = ${ id }; $meta = wp_get_attachment_metadata( $id );` +
			" $out( array( 'exists' => (bool) get_post( $id ), 'virtual' => get_post_meta( $id, '_profotograaf_virtual', true ), 'urls' => get_post_meta( $id, '_profotograaf_virtual_urls', true ), 'gone' => get_post_meta( $id, '_profotograaf_virtual_gone', true ), 'photo' => get_post_meta( $id, '_profotograaf_photo_id', true ), 'on_disk' => file_exists( (string) get_attached_file( $id ) ), 'url' => wp_get_attachment_url( $id ), 'sizes' => is_array( $meta ) && isset( $meta['sizes'] ) ? array_keys( $meta['sizes'] ) : array() ) );"
	);
}

/**
 * Lets the browser load the stored mock-platform URLs from the mock's host port.
 *
 * @param {import('@playwright/test').BrowserContext} context
 */
async function routeMock( context ) {
	await context.route( /^http:\/\/mock-platform:8090\//, ( route ) =>
		route.continue( { url: route.request().url().replace( 'http://mock-platform:8090', MOCK ) } )
	);
}

/**
 * Calls the REST image editor as the admin, the way the block editor does.
 *
 * @param {number} id Attachment id.
 */
function restEdit( id ) {
	return php(
		`$id = ${ id }; wp_set_current_user( get_user_by( 'login', 'admin' )->ID ); $r = new WP_REST_Request( 'POST', "/wp/v2/media/$id/edit" );` +
			" $r->set_body_params( array( 'src' => wp_get_attachment_url( $id ), 'modifiers' => array( array( 'type' => 'rotate', 'args' => array( 'angle' => 90 ) ) ) ) );" +
			" $res = rest_do_request( $r ); $data = $res->get_data(); $out( array( 'status' => $res->get_status(), 'code' => $data['code'] ?? '', 'message' => $data['message'] ?? '', 'data' => $data['data'] ?? null, 'id' => $data['id'] ?? 0 ) );"
	);
}

/** The id of the attachment of a photo id. */
function attachmentOf( photo ) {
	return php( `$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'meta_key' => '_profotograaf_photo_id', 'meta_value' => '${ photo }', 'fields' => 'ids' ) ); $out( (int) $ids[0] );` );
}

const NOT_EDITABLE = 'This photo is kept on Profotograaf, so this site has no file to edit. Convert it to local to crop, rotate or scale it.';

test.describe.configure( { mode: 'serial' } );

test.describe( 'Convert to local', () => {
	test.beforeAll( async ( { browser } ) => {
		await mock( '/__reset' );
		await mock( '/__scenic' );
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
		await mock( '/__mode?mode=up' );
		wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: false, virtual_attachments: false } ), '--format=json' );
		cleanUp();
		await mock( '/__reset' );
		const page = await browser.newPage();
		await login( page );
		// mode=list is saved per user; later specs expect the grid.
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'with the setting off and nothing kept the Media Library has no convert action', async ( { page } ) => {
		settings( false );
		await login( page );
		await page.goto( '/wp-admin/upload.php?mode=list' );
		await expect( page.locator( '#bulk-action-selector-top option[value="profotograaf_convert_local"]' ) ).toHaveCount( 0 );
	} );

	test( 'the row action converts a kept photo in place', async ( { page } ) => {
		settings( true );
		const made = keep( [ 'p-1' ] );
		const id = made[ 'p-1' ];
		expect( stateOf( id ) ).toMatchObject( { virtual: '1', on_disk: false } );

		await login( page );
		await page.goto( '/wp-admin/upload.php?mode=list' );
		await expect( page.locator( '#bulk-action-selector-top option[value="profotograaf_convert_local"]' ) ).toHaveText( 'Convert to local' );
		const row = page.locator( `#post-${ id }` );
		await row.hover();
		await row.getByRole( 'link', { name: 'Convert “Golden hour” to local' } ).click();

		await expect( page.locator( '.profotograaf-convert-notice p' ) ).toHaveText( '1 photo converted to local.' );
		expect( page.url() ).not.toContain( 'profotograaf_converted' );
		const after = stateOf( id );
		expect( after ).toMatchObject( { exists: true, virtual: '', urls: '', gone: '', photo: 'p-1', on_disk: true } );
		expect( after.url ).toContain( '/wp-content/uploads/' );
		expect( after.sizes ).toEqual( expect.arrayContaining( [ 'thumbnail', 'medium' ] ) );
		await expect( page.locator( `#post-${ id }` ).getByRole( 'link', { name: /to local/ } ) ).toHaveCount( 0 );
	} );

	test( 'a failed conversion keeps the photo on Profotograaf and says why', async ( { page } ) => {
		settings( true );
		const id = keep( [ 'p-2' ] )[ 'p-2' ];
		await login( page );
		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
		await expect( page.getByText( 'This site stores no copy of this photo.', { exact: false } ) ).toBeVisible();

		await mock( '/__mode?mode=down' );
		try {
			await page.locator( '.profotograaf-convert__button' ).click();
			await expect( page.locator( '.profotograaf-convert-notice.notice-error' ) ).toContainText( '1 photo could not be converted and stays kept on Profotograaf:' );
			await expect( page.locator( '.profotograaf-convert-notice.notice-error' ) ).toContainText( 'Misty lake: The photo could not be downloaded from Profotograaf. Try again later.' );
		} finally {
			await mock( '/__mode?mode=up' );
		}
		expect( stateOf( id ) ).toMatchObject( { virtual: '1', on_disk: false } );
		expect( stateOf( id ).url ).toMatch( /\/img\/p-2\/web\.jpg$/ );
	} );

	test( 'a photo that left the catalogue gets a notice that offers conversion', async ( { page } ) => {
		settings( true );
		const id = keep( [ 'p-3' ] )[ 'p-3' ];

		// The library stops listing every photo while the images still answer.
		await mock( '/__mode?mode=unlisted' );
		try {
			wp( 'eval', "do_action( 'profotograaf_virtual_sync' );" );
			expect( stateOf( id ).gone ).not.toBe( '' );

			await login( page );
			await page.goto( '/wp-admin/upload.php?mode=list' );
			const list = page.locator( '.profotograaf-gone-notice' );
			await expect( list ).toContainText( 'kept on Profotograaf' );
			await expect( list ).toContainText( 'no longer listed there and may stop showing on your site' );
			await expect( list.getByRole( 'link', { name: 'Falling leaves' } ) ).toBeVisible();

			await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
			const notice = page.locator( '.profotograaf-gone-notice' );
			await expect( notice ).toContainText( 'Profotograaf no longer lists this photo, so it may stop showing on your site. Convert it to local while Profotograaf still serves it.' );
			await notice.getByRole( 'link', { name: 'Convert to local' } ).click();

			await expect( page.locator( '.profotograaf-convert-notice p' ) ).toHaveText( '1 photo converted to local.' );
			await expect( page.locator( '.profotograaf-gone-notice' ) ).toHaveCount( 0 );
		} finally {
			await mock( '/__mode?mode=up' );
		}
		expect( stateOf( id ) ).toMatchObject( { virtual: '', gone: '', on_disk: true } );
	} );

	test( 'with the setting switched off the bulk action converts a selection and reports failures', async ( { page } ) => {
		settings( true );
		const made = keep( [ 'p-4', 'p-5', 'p-6' ] );
		const local = php( "$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'meta_key' => '_profotograaf_photo_id', 'meta_value' => 'p-1', 'fields' => 'ids' ) ); $out( (int) $ids[0] );" );
		// p-6 points at a photo Profotograaf no longer serves, so its conversion fails.
		php(
			`$id = ${ made[ 'p-6' ] }; $urls = get_post_meta( $id, '_profotograaf_virtual_urls', true ); $urls['web'] = str_replace( '/p-6/', '/p-99/', $urls['web'] );` +
				" update_post_meta( $id, '_profotograaf_virtual_urls', $urls ); update_post_meta( $id, '_profotograaf_photo_id', 'p-99' ); update_post_meta( $id, '_profotograaf_virtual_gone', time() ); $out( true );"
		);
		settings( false );

		await login( page );
		await page.goto( '/wp-admin/upload.php?mode=list' );
		for ( const id of [ made[ 'p-4' ], made[ 'p-5' ], made[ 'p-6' ], local ] ) {
			await page.locator( `#cb-select-${ id }` ).check();
		}
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'profotograaf_convert_local' );
		await page.locator( '#doaction' ).click();

		const notices = page.locator( '.profotograaf-convert-notice p' );
		await expect( notices.filter( { hasText: 'converted to local' } ) ).toHaveText( '2 photos converted to local.' );
		await expect( page.locator( '.profotograaf-convert-notice', { hasText: 'could not be converted' } ) ).toContainText(
			'Forest path: Profotograaf no longer serves this photo, so no local copy could be made. It stays kept on Profotograaf.'
		);
		await expect( notices.filter( { hasText: 'skipped' } ) ).toHaveText( '1 file was skipped because it is not kept on Profotograaf.' );

		expect( stateOf( made[ 'p-4' ] ) ).toMatchObject( { virtual: '', on_disk: true } );
		expect( stateOf( made[ 'p-5' ] ) ).toMatchObject( { virtual: '', on_disk: true } );
		expect( stateOf( made[ 'p-6' ] ) ).toMatchObject( { virtual: '1', on_disk: false } );

		// The one left is still marked, so the action stays available.
		await page.goto( '/wp-admin/upload.php?mode=list' );
		await expect( page.locator( '#bulk-action-selector-top option[value="profotograaf_convert_local"]' ) ).toHaveCount( 1 );
	} );
	test( 'the REST image editor answers a kept photo with a specific error and leaves a local photo alone', () => {
		settings( true );
		const kept = keep( [ 'p-7' ] )[ 'p-7' ];
		const refused = restEdit( kept );
		expect( refused ).toMatchObject( {
			status: 409,
			code: 'profotograaf_virtual_not_editable',
			message: 'This photo is kept on Profotograaf, so this site has no file to edit. Convert it to local in the Media Library first, then edit it.',
		} );
		expect( refused.data.attachment_id ).toBe( kept );
		expect( refused.data.convert_url ).toContain( `admin-post.php?action=profotograaf_convert_local&attachment=${ kept }&_wpnonce=` );
		expect( stateOf( kept ) ).toMatchObject( { virtual: '1', on_disk: false } );

		// p-1 was converted to local earlier: core edits it into a new attachment.
		const edited = restEdit( attachmentOf( 'p-1' ) );
		expect( edited.status ).toBe( 201 );
		expect( edited.code ).toBe( '' );
		wp( 'post', 'delete', String( edited.id ), '--force' );
	} );

	test( 'the media modal and the edit screen swap Edit Image for the explanation', async ( { page, context } ) => {
		test.setTimeout( 120_000 );
		settings( true );
		await routeMock( context );
		const kept = attachmentOf( 'p-7' );
		const local = attachmentOf( 'p-1' );
		await login( page );

		// The grid router knows ?item= only on its own, so the view is set first.
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.goto( `/wp-admin/upload.php?item=${ kept }` );
		const details = page.locator( '.media-modal .attachment-details' );
		await expect( details ).toBeVisible( { timeout: 30_000 } );
		await expect( details.locator( '.profotograaf-not-editable' ) ).toContainText( NOT_EDITABLE );
		await expect( details.locator( '.profotograaf-not-editable' ).getByRole( 'link', { name: 'Convert to local' } ) ).toHaveAttribute( 'href', new RegExp( `attachment=${ kept }&_wpnonce=` ) );
		await expect( details.locator( '.edit-attachment' ) ).toHaveCount( 0 );

		await page.goto( `/wp-admin/upload.php?item=${ local }` );
		await expect( page.locator( '.media-modal .attachment-details .edit-attachment' ) ).toBeVisible( { timeout: 30_000 } );
		await expect( page.locator( '.media-modal .profotograaf-not-editable' ) ).toHaveCount( 0 );

		await page.goto( `/wp-admin/post.php?post=${ kept }&action=edit` );
		await expect( page.locator( '.profotograaf-not-editable' ) ).toContainText( NOT_EDITABLE );
		await expect( page.locator( `#imgedit-open-btn-${ kept }` ) ).toBeHidden();

		await page.goto( `/wp-admin/post.php?post=${ local }&action=edit` );
		await expect( page.locator( `#imgedit-open-btn-${ local }` ) ).toBeVisible();
		await expect( page.locator( '.profotograaf-not-editable' ) ).toHaveCount( 0 );
	} );

	test( 'the Image block of a kept photo has no Crop button and says why', async ( { page, context } ) => {
		test.setTimeout( 180_000 );
		settings( true );
		await routeMock( context );
		const kept = attachmentOf( 'p-7' );
		const local = attachmentOf( 'p-1' );
		const urls = php( `$out( array( wp_get_attachment_url( ${ kept } ), wp_get_attachment_url( ${ local } ) ) );` );
		const block = ( id, url ) =>
			`<!-- wp:image {"id":${ id },"sizeSlug":"full","linkDestination":"none"} --><figure class="wp-block-image size-full"><img src="${ url }" alt="" class="wp-image-${ id }"/></figure><!-- /wp:image -->`;
		const postId = wp( 'post', 'create', '--post_type=post', '--post_status=draft', '--post_title=Edit guard', `--post_content=${ block( kept, urls[ 0 ] ) }${ block( local, urls[ 1 ] ) }`, '--porcelain' );

		try {
			await login( page );
			await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
			await page.waitForFunction( () => window.wp?.data?.select( 'core/block-editor' )?.getBlocks().length === 2, null, { timeout: 60_000 } );
			await page.evaluate( () => {
				window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
				window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/block' );
			} );
			const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
			// The editor renders the image without the wp-image class, so it is found by its URL.
			const keptImage = canvas.locator( 'img[src*="/img/p-7/"]' );
			const localImage = canvas.locator( 'img[src*="/uploads/"]' );
			await expect.poll( () => localImage.evaluate( ( img ) => /** @type {HTMLImageElement} */ ( img ).naturalWidth ), { timeout: 30_000 } ).toBeGreaterThan( 0 );
			await expect.poll( () => keptImage.evaluate( ( img ) => /** @type {HTMLImageElement} */ ( img ).naturalWidth ), { timeout: 30_000 } ).toBeGreaterThan( 0 );
			const select = ( index ) =>
				page.evaluate( ( i ) => {
					const editor = window.wp.data.select( 'core/block-editor' );
					window.wp.data.dispatch( 'core/block-editor' ).selectBlock( editor.getBlocks()[ i ].clientId );
				}, index );
			const crop = page.locator( '.block-editor-block-toolbar button[aria-label="Crop"]' );

			await select( 1 );
			await expect( crop ).toBeVisible();
			await expect( page.locator( '.profotograaf-not-editable' ) ).toHaveCount( 0 );

			await select( 0 );
			// The notice fills the Content tab and the Settings tab; the open one shows it.
			const notice = page.locator( '.profotograaf-not-editable' ).filter( { visible: true } );
			await expect( notice ).toContainText( NOT_EDITABLE );
			await expect( notice.getByRole( 'link', { name: 'Convert to local' } ) ).toHaveAttribute( 'href', new RegExp( `attachment=${ kept }&_wpnonce=` ) );
			await expect( page.locator( '.block-editor-block-toolbar' ).first() ).toBeVisible();
			await expect( crop ).toBeHidden();

			// The error the block editor shows when the edit is sent anyway.
			const error = await page.evaluate( async ( [ id, src ] ) => {
				try {
					await window.wp.apiFetch( { path: `/wp/v2/media/${ id }/edit`, method: 'POST', data: { src, modifiers: [ { type: 'rotate', args: { angle: 90 } } ] } } );
					return null;
				} catch ( e ) {
					return { code: e.code, status: e.data?.status };
				}
			}, [ kept, urls[ 0 ] ] );
			expect( error ).toEqual( { code: 'profotograaf_virtual_not_editable', status: 409 } );
		} finally {
			wp( 'post', 'delete', postId, '--force' );
		}
	} );
} );
