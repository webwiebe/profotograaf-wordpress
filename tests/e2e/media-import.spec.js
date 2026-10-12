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

test.describe.configure( { mode: 'serial' } );

test.describe( 'Media > Import from Profotograaf', () => {
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

	test( 'the entry and the screen are absent while the setting is off', async ( { page } ) => {
		mediaSource( false );
		await login( page );
		await page.goto( '/wp-admin/upload.php' );
		await expect( page.locator( '#menu-media' ).getByRole( 'link', { name: 'Import from Profotograaf' } ) ).toHaveCount( 0 );

		const response = await page.goto( '/wp-admin/upload.php?page=profotograaf-import' );
		expect( response?.status() ).toBeGreaterThanOrEqual( 400 );
	} );

	test( 'browses, searches and filters the photos', async ( { page } ) => {
		mediaSource( true );
		await login( page );
		await page.goto( '/wp-admin/upload.php' );
		await page.locator( '#menu-media' ).getByRole( 'link', { name: 'Import from Profotograaf' } ).click();

		await expect( page.getByRole( 'heading', { level: 1, name: 'Import from Profotograaf' } ) ).toBeVisible();
		const cards = page.locator( '.profotograaf-import__card' );
		await expect( cards ).toHaveCount( 4, { timeout: 30_000 } );

		await page.getByLabel( 'Search photos' ).fill( 'leaves' );
		await page.getByRole( 'button', { name: 'Search' } ).click();
		await expect( cards ).toHaveCount( 1 );
		await expect( cards.first() ).toContainText( 'Falling leaves' );

		await page.getByLabel( 'Search photos' ).fill( '' );
		await page.getByRole( 'button', { name: 'Search' } ).click();
		await expect( cards ).toHaveCount( 4 );
		await page.getByLabel( 'Gallery' ).selectOption( { label: 'Spring wedding (3)' } );
		await expect( cards ).toHaveCount( 3 );
	} );

	test( 'imports the selected photos, marks them and lists them under the Profotograaf source', async ( { page } ) => {
		mediaSource( true );
		wp(
			'eval',
			"wp_insert_attachment( array( 'post_title' => 'Plain upload', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ) );"
		);
		await login( page );
		await page.goto( '/wp-admin/upload.php?page=profotograaf-import' );
		const cards = page.locator( '.profotograaf-import__card' );
		await expect( cards ).toHaveCount( 4, { timeout: 30_000 } );

		await page.getByRole( 'button', { name: /Bride/ } ).click();
		await page.getByRole( 'button', { name: /Rings/ } ).click();
		await page.getByRole( 'button', { name: 'Import 2 photos' } ).click();

		await expect( page.locator( '#profotograaf-import-root' ).getByText( '2 photos imported.' ) ).toBeVisible( { timeout: 60_000 } );
		await expect( page.locator( '.profotograaf-import__badge' ) ).toHaveCount( 2 );
		await expect( page.getByRole( 'link', { name: 'Open in Media Library' } ).first() ).toBeVisible();
		expect( wp( 'post', 'list', '--post_type=attachment', '--meta_key=_profotograaf_photo_id', '--format=count' ) ).toBe( '2' );

		// A second visit still marks them, from the Media Library lookup.
		await page.reload();
		await expect( page.locator( '.profotograaf-import__badge' ) ).toHaveCount( 2 );

		await page.goto( '/wp-admin/upload.php' );
		const attachments = page.locator( '.attachments .attachment' );
		await expect( attachments ).toHaveCount( 3 );
		await page.locator( '#profotograaf-source-filter' ).selectOption( 'profotograaf' );
		await expect( attachments ).toHaveCount( 2 );
	} );
	test( 'shows the origin of an imported photo and re-imports it in place', async ( { page } ) => {
		mediaSource( true );
		const ids = wp( 'post', 'list', '--post_type=attachment', '--meta_key=_profotograaf_photo_id', '--field=ID' ).split( /\s+/ );
		const id = ids[ 0 ];
		const plain = wp( 'post', 'list', '--post_type=attachment', '--title=Plain upload', '--field=ID' );
		wp( 'post', 'meta', 'update', id, '_profotograaf_version', 'stale-version' );
		await login( page );

		// A plain upload shows neither the gallery link nor the button.
		await page.goto( `/wp-admin/post.php?post=${ plain }&action=edit` );
		await expect( page.locator( '.profotograaf-reimport__button' ) ).toHaveCount( 0 );

		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
		await expect( page.getByRole( 'link', { name: /Spring wedding|Open on Profotograaf/ } ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Re-import' } ).click();
		await expect( page.locator( '.profotograaf-reimport__status' ) ).toContainText( 'replaced with the current version', { timeout: 60_000 } );
		// The mock's image URLs carry no version hash, so the stored version ends up empty. wp post meta get refuses an empty value.
		expect( wp( 'eval', `echo get_post_meta( ${ id }, '_profotograaf_version', true );` ) ).not.toBe( 'stale-version' );
		expect( wp( 'post', 'list', '--post_type=attachment', '--meta_key=_profotograaf_photo_id', '--format=count' ) ).toBe( '2' );

		// A photo that left the catalogue keeps its local copy and says so.
		wp(
			'eval',
			"$c = get_option( 'profotograaf_photo_catalogue' ); $c['photos'] = array(); update_option( 'profotograaf_photo_catalogue', $c );"
		);
		await page.reload();
		await page.getByRole( 'button', { name: 'Re-import' } ).click();
		await expect( page.locator( '.profotograaf-reimport__status' ) ).toContainText( 'no longer available on Profotograaf', { timeout: 30_000 } );

		// Flag the copy as the poll would. The details say so in words and offer Keep and Remove, not Re-import.
		const state = () => wp( 'eval', `echo get_post_meta( ${ id }, '_profotograaf_sync_state', true );` );
		wp( 'eval', `update_post_meta( ${ id }, '_profotograaf_sync_state', 'missing' ); update_post_meta( ${ id }, '_profotograaf_sync_reason', 'deleted' );` );
		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
		await expect( page.getByText( 'This photo was deleted on Profotograaf.' ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Re-import' } ) ).toHaveCount( 0 );

		// The flag shows in the library list, on the Import screen and as a notice, and none of them uses colour alone.
		wp( 'eval', "update_option( 'profotograaf_sync_counts', array( 'changed' => 0, 'missing' => 1, 'conflict' => 0 ) );" );
		await page.goto( '/wp-admin/upload.php?mode=list&profotograaf_source=attention' );
		await expect( page.locator( '.column-profotograaf_sync' ).getByText( 'Removed on Profotograaf' ) ).toBeVisible();
		await expect( page.locator( '.profotograaf-notice--sync' ) ).toContainText( '1 imported photo needs attention' );
		await page.goto( '/wp-admin/upload.php?page=profotograaf-import' );
		await expect( page.getByRole( 'region', { name: /needs attention/ } ) ).toContainText( 'Removed on Profotograaf: 1' );
		await expect( page.getByRole( 'button', { name: 'Remove all copies removed on Profotograaf' } ) ).toBeVisible();
		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );

		// Keep stops the watching and records the decision.
		await page.getByRole( 'button', { name: 'Keep', exact: true } ).click();
		await expect( page.getByText( 'Kept as this site' ).first() ).toBeVisible( { timeout: 30_000 } );
		expect( state() ).toBe( 'kept' );
		await page.getByRole( 'button', { name: 'Watch again' } ).click();
		await expect( page.getByRole( 'button', { name: 'Stop watching' } ) ).toBeVisible( { timeout: 30_000 } );
		expect( state() ).toBe( '' );

		// Remove asks first, names the used-in count and deletes only after the confirmation.
		wp( 'eval', `update_post_meta( ${ id }, '_profotograaf_sync_state', 'missing' ); update_post_meta( ${ id }, '_profotograaf_sync_reason', 'deleted' );` );
		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
		let asked = '';
		page.once( 'dialog', async ( dialog ) => {
			asked = dialog.message();
			await dialog.dismiss();
		} );
		await page.getByRole( 'button', { name: 'Remove', exact: true } ).click();
		await expect.poll( () => asked ).toContain( 'No post uses this photo.' );
		expect( wp( 'eval', `echo get_post( ${ id } ) ? 'present' : 'gone';` ) ).toBe( 'present' );
		page.once( 'dialog', async ( dialog ) => {
			await dialog.accept();
		} );
		await page.getByRole( 'button', { name: 'Remove', exact: true } ).click();
		await expect.poll( () => wp( 'eval', `echo get_post( ${ id } ) ? 'present' : 'gone';` ), { timeout: 30_000 } ).toBe( 'gone' );
	} );

	test.describe( 'resolving a conflict', () => {
		/** The imported copy the conflict tests work on, and wp-cli helpers bound to it. */
		function copy() {
			const id = wp( 'post', 'list', '--post_type=attachment', '--meta_key=_profotograaf_photo_id', '--field=ID' ).split( /\s+/ )[ 0 ];
			return {
				id,
				meta: ( key ) => wp( 'eval', `echo get_post_meta( ${ id }, '${ key }', true );` ),
				attachments: () => Number( wp( 'post', 'list', '--post_type=attachment', '--format=count' ) ),
				/** Flags the copy as a conflict with a platform photo that has other text and another file version. */
				flag: ( fileEdited ) =>
					wp(
						'eval',
						`$pid = get_post_meta( ${ id }, '_profotograaf_photo_id', true ); $c = get_option( 'profotograaf_photo_catalogue' );` +
							" foreach ( $c['photos'] as $i => $p ) { if ( (string) $p['id'] === (string) $pid ) { $c['photos'][ $i ]['title'] = 'Their title'; $c['photos'][ $i ]['caption'] = 'Their caption'; $c['photos'][ $i ]['alt'] = ''; $c['photos'][ $i ]['version'] = 'remote-v2'; } }" +
							" update_option( 'profotograaf_photo_catalogue', $c );" +
							` wp_update_post( array( 'ID' => ${ id }, 'post_title' => 'My title', 'post_excerpt' => 'My caption' ) ); update_post_meta( ${ id }, '_wp_attachment_image_alt', 'My alt' );` +
							` update_post_meta( ${ id }, '_profotograaf_sync_state', 'conflict' ); update_post_meta( ${ id }, '_profotograaf_remote_version', 'remote-v2' ); update_post_meta( ${ id }, '_profotograaf_sync_reason', '' );` +
							( fileEdited ? ` update_post_meta( ${ id }, '_profotograaf_file_hash', 'not-the-current-file' );` : '' ) +
							" update_option( 'profotograaf_sync_counts', array( 'changed' => 0, 'missing' => 0, 'conflict' => 1 ) );"
					),
			};
		}

		/** Opens the dialog of the copy. Each click that changes the copy reloads the page, which the tests wait for. */
		async function open( page, id ) {
			await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
			await page.getByRole( 'button', { name: 'Resolve conflict' } ).click();
			const dialog = page.getByRole( 'dialog', { name: 'Resolve conflict' } );
			await expect( dialog ).toBeVisible( { timeout: 30_000 } );
			return dialog;
		}

		test.beforeAll( async ( { browser } ) => {
			mediaSource( true );
			// Refetch the catalogue so it lists the photos again.
			wp( 'eval', "delete_option( 'profotograaf_photo_catalogue' );" );
			const page = await browser.newPage();
			await login( page );
			await page.goto( '/wp-admin/upload.php?page=profotograaf-import' );
			await expect( page.locator( '.profotograaf-import__card' ) ).toHaveCount( 4, { timeout: 30_000 } );
			await page.close();
		} );

		test( 'shows both images, warns before the local edit is lost and updates only the ticked text fields', async ( { page } ) => {
			const { id, meta, flag } = copy();
			flag( true );
			await login( page );
			await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
			await expect( page.getByRole( 'button', { name: 'Re-import' } ) ).toHaveCount( 0 );
			const dialog = await open( page, id );
			await expect( dialog.locator( 'img' ) ).toHaveCount( 2 );
			await expect( dialog ).toContainText( 'You edited the file on this site' );
			await expect( dialog.getByRole( 'link', { name: 'Download my version first' } ) ).toBeVisible();
			await expect( dialog.getByText( 'Kept: Profotograaf has no value' ) ).toBeVisible();
			expect( meta( '_profotograaf_sync_state' ) ).toBe( 'conflict' );

			// Update text takes only the ticked field and never an empty platform alt.
			await dialog.getByLabel( 'Title' ).check();
			await Promise.all( [ page.waitForEvent( 'load' ), dialog.getByRole( 'button', { name: 'Update text' } ).click() ] );
			expect( wp( 'post', 'get', id, '--field=post_title' ) ).toBe( 'Their title' );
			expect( wp( 'post', 'get', id, '--field=post_excerpt' ) ).toBe( 'My caption' );
			expect( meta( '_wp_attachment_image_alt' ) ).toBe( 'My alt' );
			expect( meta( '_profotograaf_sync_state' ) ).toBe( 'conflict' );
		} );

		test( 'Keep both saves the local file as a separate attachment and replaces the original', async ( { page } ) => {
			const { id, meta, attachments, flag } = copy();
			flag( true );
			const before = attachments();
			await login( page );
			await open( page, id );
			await Promise.all( [ page.waitForEvent( 'load' ), page.getByRole( 'button', { name: 'Keep both' } ).click() ] );
			expect( meta( '_profotograaf_sync_state' ) ).toBe( '' );
			expect( attachments() ).toBe( before + 1 );
			expect( wp( 'post', 'list', '--post_type=attachment', '--meta_key=_profotograaf_photo_id', '--format=count' ) ).toBe( '1' );
		} );

		test( "Use Profotograaf's version asks first because the file was edited", async ( { page } ) => {
			const { id, meta, attachments, flag } = copy();
			flag( true );
			const before = attachments();
			await login( page );
			await open( page, id );
			let asked = '';
			page.once( 'dialog', async ( confirmation ) => {
				asked = confirmation.message();
				await confirmation.dismiss();
			} );
			await page.getByRole( 'button', { name: "Use Profotograaf's version" } ).click();
			await expect.poll( () => asked ).toContain( 'Your edit is lost.' );
			expect( meta( '_profotograaf_sync_state' ) ).toBe( 'conflict' );

			page.once( 'dialog', async ( confirmation ) => {
				await confirmation.accept();
			} );
			await Promise.all( [ page.waitForEvent( 'load' ), page.getByRole( 'button', { name: "Use Profotograaf's version" } ).click() ] );
			expect( meta( '_profotograaf_sync_state' ) ).toBe( '' );
			expect( attachments() ).toBe( before );
		} );

		test( 'Keep mine accepts the platform version and its text, so the copy stays in sync', async ( { page } ) => {
			const { id, meta, flag } = copy();
			flag( false );
			await login( page );
			await open( page, id );
			await Promise.all( [ page.waitForEvent( 'load' ), page.getByRole( 'button', { name: 'Keep mine' } ).click() ] );
			expect( meta( '_profotograaf_sync_state' ) ).toBe( '' );
			expect( meta( '_profotograaf_version' ) ).toBe( 'remote-v2' );
			expect( wp( 'post', 'get', id, '--field=post_title' ) ).toBe( 'My title' );
		} );
	} );
} );
