// @ts-check
// Takes screenshots 5 to 9 of the wordpress.org plugin page (assets/wporg/).
//
// This spec is not part of the normal E2E run: playwright.config.js skips it
// unless WPORG_SCREENSHOTS is set. Run it with `make wporg-screenshots`, which
// starts the E2E stack, captures the PNGs, optimises them and stops the stack.
// The mock platform runs in scenic mode (POST /__scenic) so the library shows
// nine pictures instead of the 48x32 test JPEG.
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const OUTPUT = path.join( __dirname, '..', '..', 'assets', 'wporg' );
const SITE_TITLE = 'Harbour Light Photography';
// The mock answers WordPress as mock-platform:8090, so the photo URLs in the
// library point there. The browser reaches the same mock on the published port.
const MOCK_ORIGIN = new RegExp( '^http://mock-platform:8090/' );
const MOCK_PUBLISHED = `http://localhost:${ process.env.MOCK_PORT || 8090 }/`;
// Hides the "update available" notices of the throwaway test site.
const QUIET_PLUGIN = `<?php
$quiet = static function ( $transient ) {
	$transient->updates      = array();
	$transient->response     = array();
	$transient->translations = array();
	return $transient;
};
foreach ( array( 'update_core', 'update_plugins', 'update_themes' ) as $name ) {
	add_filter( "site_transient_$name", static function ( $value ) use ( $quiet ) {
		return $quiet( is_object( $value ) ? $value : new stdClass() );
	} );
}
`;

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

/** Removes the imported photos, the stored lists and the copy state, so the capture can run again. */
function cleanUp() {
	const ids = wp( 'post', 'list', '--post_type=attachment', '--post_status=any', '--field=ID' );
	if ( ids !== '' ) {
		wp( 'post', 'delete', ...ids.split( /\s+/ ), '--force' );
	}
	wp(
		'eval',
		"foreach ( array( 'profotograaf_photo_catalogue', 'profotograaf_gallery_index', 'profotograaf_backup_last_run', 'profotograaf_backup_pause', 'profotograaf_backup_cursor' ) as $o ) { delete_option( $o ); } wp_clear_scheduled_hook( 'profotograaf_backup_upload' ); wp_clear_scheduled_hook( 'profotograaf_backup_backfill' );"
	);
}

// Files on the Copy to Profotograaf tab: nine copied, two waiting, one failed.
const COPY_FILES = [
	[ 'harbour-sunrise', 'done' ],
	[ 'lighthouse-portrait', 'done' ],
	[ 'spring-wedding-01', 'done' ],
	[ 'spring-wedding-02', 'done' ],
	[ 'spring-wedding-03', 'done' ],
	[ 'family-on-the-pier', 'done' ],
	[ 'boats-at-dusk', 'done' ],
	[ 'studio-headshot', 'done' ],
	[ 'misty-lake', 'done' ],
	[ 'autumn-family-01', 'queued' ],
	[ 'autumn-family-02', 'queued' ],
	[ 'harbour-at-night', 'failed' ],
];

// Galleries on the Galleries tab, as the editor's gallery picker stores them.
const CLIENT_GALLERIES = {
	'g-wedding': { title: 'Spring wedding, Anna and Tom', url: 'https://profotograaf.nl/share/g/spring-wedding-anna-tom', layout: '', embed_layout: '' },
	'g-family': { title: 'Autumn family shoot', url: 'https://profotograaf.nl/share/g/autumn-family-shoot', layout: '', embed_layout: '' },
	'g-headshots': { title: 'Team headshots', url: 'https://profotograaf.nl/share/g/team-headshots', layout: '', embed_layout: '' },
};

/**
 * Waits until every image inside the locator has loaded.
 *
 * @param {import('@playwright/test').Locator} scope
 * @param {number} minimum The number of images that must be there.
 */
async function imagesLoaded( scope, minimum ) {
	const images = scope.locator( 'img' );
	await expect( images.nth( minimum - 1 ) ).toBeVisible( { timeout: 30_000 } );
	await expect
		.poll( () => images.evaluateAll( ( list ) => list.every( ( img ) => /** @type {HTMLImageElement} */ ( img ).complete && /** @type {HTMLImageElement} */ ( img ).naturalWidth > 0 ) ), { timeout: 30_000 } )
		.toBe( true );
}

/**
 * Closes the welcome or starter pattern dialog a fresh editor can open on top of the page.
 *
 * @param {import('@playwright/test').Page} page
 */
async function closeEditorDialog( page ) {
	const dialog = page.locator( '.components-modal__screen-overlay' );
	const shown = await dialog.waitFor( { state: 'visible', timeout: 4_000 } ).then( () => true, () => false );
	if ( shown ) {
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
	}
}

/**
 * Lets the browser load the photo thumbnails the library lists under the mock's container name.
 *
 * @param {import('@playwright/test').Page} page
 */
async function reachMock( page ) {
	await page.route( MOCK_ORIGIN, ( route ) =>
		route.continue( { url: route.request().url().replace( MOCK_ORIGIN, MOCK_PUBLISHED ) } )
	);
}

/**
 * Waits until the element stops moving, such as a panel that slides in.
 *
 * @param {import('@playwright/test').Locator} locator
 */
async function settled( locator ) {
	let previous = '';
	await expect
		.poll(
			async () => {
				const now = JSON.stringify( await locator.boundingBox() );
				const same = now === previous;
				previous = now;
				return same;
			},
			{ intervals: [ 400 ], timeout: 15_000 }
		)
		.toBe( true );
}

test.describe.configure( { mode: 'serial' } );
test.use( { viewport: { width: 1280, height: 800 } } );

test.describe( 'wordpress.org screenshots', () => {
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
		wp( 'option', 'update', 'blogname', SITE_TITLE );
		wp( 'eval', `file_put_contents( WPMU_PLUGIN_DIR . '/e2e-quiet.php', base64_decode( '${ Buffer.from( QUIET_PLUGIN ).toString( 'base64' ) }' ) );` );
		cleanUp();
		mediaSource( true );
	} );

	test.afterAll( async ( { browser } ) => {
		mediaSource( false );
		cleanUp();
		wp( 'option', 'update', 'blogname', 'Profotograaf E2E' );
		wp( 'eval', "wp_delete_file( WPMU_PLUGIN_DIR . '/e2e-quiet.php' );" );
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
		await mock( '/__reset' );
	} );

	test( 'screenshot 5: the Profotograaf category in the block inserter', async ( { page } ) => {
		await reachMock( page );
		await login( page );
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction( () => window.wp && window.wp.data && window.wp.blocks && window.wp.data.select( 'core/block-editor' ) );
		await page.evaluate( () => {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'fullscreenMode', false );
			window.wp.data.dispatch( 'core/editor' ).setIsInserterOpened( true );
		} );
		await closeEditorDialog( page );
		// The inserter redraws while the dialog closes and can drop the first click.
		const mediaTab = page.getByRole( 'tab', { name: 'Media', exact: true } );
		await expect( async () => {
			await mediaTab.click();
			await expect( mediaTab ).toHaveAttribute( 'aria-selected', 'true', { timeout: 2_000 } );
		} ).toPass( { timeout: 20_000 } );
		await page.getByRole( 'tab', { name: 'Profotograaf' } ).click();

		const panel = page.locator( '.block-editor-inserter__media-panel' );
		await expect( page.getByRole( 'option', { name: 'Golden hour' } ) ).toBeVisible( { timeout: 30_000 } );
		await imagesLoaded( panel, 6 );
		await settled( panel );
		await page.mouse.move( 900, 600 );

		await page.screenshot( { path: path.join( OUTPUT, 'screenshot-5.png' ) } );
	} );

	test( 'screenshot 6: selecting photos under Media > Import from Profotograaf', async ( { page } ) => {
		await reachMock( page );
		await login( page );
		await page.goto( '/wp-admin/upload.php?page=profotograaf-import' );
		// The telemetry consent notice shows once per site, so a second capture run finds it gone.
		const notNow = page.getByRole( 'button', { name: 'Not now' } );
		if ( await notNow.isVisible() ) {
			await notNow.click();
		}
		const cards = page.locator( '.profotograaf-import__card' );
		await expect( cards ).toHaveCount( 9, { timeout: 30_000 } );
		await imagesLoaded( page.locator( '#profotograaf-import-root' ), 9 );

		for ( const title of [ 'Golden hour', 'Misty lake', 'Spring meadow', 'Orchard' ] ) {
			await page.getByRole( 'button', { name: new RegExp( title ) } ).click();
		}
		await expect( page.getByRole( 'button', { name: 'Import 4 photos' } ) ).toBeVisible();
		await page.mouse.move( 1250, 780 );

		await page.screenshot( { path: path.join( OUTPUT, 'screenshot-6.png' ) } );
	} );

	test( 'screenshot 7: the Profotograaf tab of the media modal for the featured image', async ( { page } ) => {
		await reachMock( page );
		await login( page );
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/editor' ) );
		await page.evaluate( () => {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
			window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' );
			window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'A spring wedding by the harbour' } );
		} );
		await closeEditorDialog( page );
		await page.getByRole( 'button', { name: 'Set featured image' } ).click();
		const modal = page.locator( '.media-modal' );
		await expect( modal ).toBeVisible();
		await modal.getByRole( 'tab', { name: 'Profotograaf' } ).click();
		await expect( modal.getByRole( 'button', { name: 'Golden hour' } ) ).toBeVisible( { timeout: 30_000 } );
		await imagesLoaded( modal, 9 );
		await modal.getByRole( 'button', { name: 'Golden hour' } ).click();
		await page.mouse.move( 1250, 780 );

		await page.screenshot( { path: path.join( OUTPUT, 'screenshot-7.png' ) } );
	} );

	test( 'screenshot 8: the Copy to Profotograaf tab with its status', async ( { page } ) => {
		const seed = COPY_FILES.map( ( [ name, state ] ) => `array( '${ name }', '${ state }' )` ).join( ', ' );
		wp(
			'eval',
			[
				"update_option( 'profotograaf_settings', array( 'backup' => true, 'backup_scope' => 'used' ) );",
				// The mock grants the scopes of an older connection; copying needs galleries:write.
				"$c = get_option( 'profotograaf_connection' ); $c['scopes'] = array_values( array_unique( array_merge( (array) ( $c['scopes'] ?? array() ), array( 'galleries:write' ) ) ) ); update_option( 'profotograaf_connection', $c, false );",
				"update_option( 'profotograaf_backup_cursor', array( 'cursor' => PHP_INT_MAX, 'complete' => true ) );",
				"update_option( 'profotograaf_backup_last_run', array( 'at' => time() - 180, 'sent' => 3, 'stop' => 'empty', 'last_sent' => time() - 180, 'idle_since' => 0 ) );",
				'$dir = wp_upload_dir();',
				`foreach ( array( ${ seed } ) as $row ) {`,
				"  $file = $dir['path'] . '/' . $row[0] . '.png'; file_put_contents( $file, str_repeat( 'x', 2400000 ) );",
				"  $id = wp_insert_attachment( array( 'post_title' => $row[0], 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file );",
				"  update_post_meta( $id, '_profotograaf_backup_state', $row[1] );",
				"  if ( 'done' === $row[1] ) { update_post_meta( $id, '_profotograaf_backup_at', time() - 3600 ); }",
				"  if ( 'failed' === $row[1] ) { update_post_meta( $id, '_profotograaf_backup_reason', 'http_500' ); }",
				'}',
				"wp_clear_scheduled_hook( 'profotograaf_backup_upload' ); wp_clear_scheduled_hook( 'profotograaf_backup_backfill' );",
			].join( ' ' )
		);
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf&tab=backup' );
		await expect( page.getByRole( 'heading', { name: 'Copy status' } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Copy now' } ) ).toBeVisible();
		await expect( page.getByText( 'needs a new permission' ) ).toHaveCount( 0 );
		await page.getByRole( 'heading', { name: 'Copy status' } ).evaluate( ( heading ) => heading.scrollIntoView( { block: 'start' } ) );
		await page.mouse.move( 1250, 780 );

		await page.screenshot( { path: path.join( OUTPUT, 'screenshot-8.png' ) } );
	} );

	test( 'screenshot 9: share links and QR codes on the Galleries tab', async ( { page } ) => {
		wp( 'option', 'update', 'profotograaf_gallery_index', JSON.stringify( CLIENT_GALLERIES ), '--format=json' );
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf&tab=galleries' );
		const list = page.locator( '.profotograaf-client-links' );
		await expect( list.getByRole( 'rowheader' ) ).toHaveCount( 3 );
		await expect( list.getByRole( 'img', { name: /QR code with the share link of/ } ) ).toHaveCount( 3 );
		await page.getByRole( 'heading', { name: 'Client galleries' } ).evaluate( ( heading ) => heading.scrollIntoView( { block: 'start' } ) );
		await settled( list );
		await page.mouse.move( 1250, 780 );

		await page.screenshot( { path: path.join( OUTPUT, 'screenshot-9.png' ) } );
	} );
} );
