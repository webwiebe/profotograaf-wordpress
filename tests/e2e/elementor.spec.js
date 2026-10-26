// @ts-check
//
// Issue #1708: the Elementor widget "Profotograaf Gallery" next to Elementor
// (free), the most-installed page builder on wordpress.org. The spec installs a
// pinned version from wordpress.org, saves a page whose Elementor data holds
// the widget, and checks that a visitor and the Elementor preview canvas get
// the markup the gallery block renders. Without network access to
// wordpress.org the spec is skipped.
//
// The widget is checked through the page it saves. The Elementor editor UI is
// not driven: its server render is what the canvas shows.
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );
const { openEmbed } = require( './embed-helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];
const ELEMENTOR = 'elementor';
const ELEMENTOR_VERSION = '4.3.5';

/**
 * Runs wp-cli in the WordPress stack started by up.sh.
 *
 * @param {string[]} args
 */
function wp( ...args ) {
	return execFileSync( 'docker', [ 'compose', ...PROJECT, '-f', COMPOSE, 'run', '--rm', '-T', 'cli', 'wp', ...args ], {
		encoding: 'utf8',
		timeout: 240_000,
	} ).trim();
}

/**
 * Installs and activates the pinned plugin. Tries twice, because it downloads
 * from wordpress.org. Returns false when both tries fail.
 */
function installElementor() {
	for ( let attempt = 0; attempt < 2; attempt++ ) {
		try {
			wp( 'plugin', 'install', ELEMENTOR, `--version=${ ELEMENTOR_VERSION }`, '--force', '--activate' );
			return true;
		} catch {
			// Retried once below.
		}
	}
	return false;
}

/**
 * Elementor data for a page with one Profotograaf Gallery widget.
 *
 * @param {Record<string,string>} settings
 */
function elementorData( settings ) {
	return JSON.stringify( [
		{
			id: 'e2econ1',
			elType: 'container',
			settings: {},
			elements: [ { id: 'e2ewid1', elType: 'widget', widgetType: 'profotograaf_gallery', settings, elements: [] } ],
		},
	] );
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Elementor gallery widget', () => {
	let installed = false;
	let pageId = '';

	test.beforeAll( async ( { browser } ) => {
		installed = installElementor();
		if ( ! installed ) {
			return;
		}
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
		// The widget's picker reads the stored gallery list, which the block's picker fills through this route.
		wp(
			'eval',
			"wp_set_current_user( get_user_by( 'login', 'admin' )->ID ); rest_do_request( new WP_REST_Request( 'GET', '/profotograaf/v1/galleries' ) );"
		);
		pageId = wp( 'post', 'create', '--porcelain', '--post_type=page', '--post_status=publish', '--post_title=Elementor E2E' );
		wp( 'post', 'meta', 'update', pageId, '_elementor_edit_mode', 'builder' );
		wp( 'post', 'meta', 'update', pageId, '_elementor_template_type', 'wp-page' );
		wp( 'post', 'meta', 'update', pageId, '_elementor_data', elementorData( { gallery_id: 'g-e2e', layout: 'masonry', columns: '3', lightbox: 'off' } ) );
	} );

	test.afterAll( async ( { browser } ) => {
		if ( ! installed ) {
			return;
		}
		if ( pageId ) {
			wp( 'post', 'delete', pageId, '--force' );
		}
		wp( 'plugin', 'deactivate', ELEMENTOR );
		wp( 'plugin', 'delete', ELEMENTOR );
		await mock( '/__reset' );
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test.beforeEach( () => {
		test.skip( ! installed, `${ ELEMENTOR } ${ ELEMENTOR_VERSION } could not be installed from wordpress.org` );
	} );

	test( 'a visitor gets the gallery block markup with the chosen options', async ( { browser } ) => {
		// The server leaves a gallery with the lightbox off to the script, which reaches the mock through this context.
		const { context: visitor, page } = await openEmbed( browser, `${ test.info().project.use.baseURL }/?page_id=${ pageId }` );

		const embed = page.locator( '.elementor-widget-profotograaf_gallery div[data-profotograaf-gallery="g-e2e"]' );
		await expect( embed ).toHaveAttribute( 'data-layout', 'masonry' );
		await expect( embed ).toHaveAttribute( 'data-columns', '3' );
		await expect( embed ).toHaveAttribute( 'data-lightbox', 'off' );
		await expect( embed.locator( '.photos .tile' ).first() ).toBeVisible();
		await visitor.close();
	} );

	test( 'the Elementor preview canvas renders the same gallery', async ( { page } ) => {
		await login( page );
		await page.goto( `/?p=${ pageId }&elementor-preview=${ pageId }` );

		const embed = page.locator( '.elementor-widget-profotograaf_gallery div[data-profotograaf-gallery="g-e2e"]' );
		await expect( embed ).toHaveAttribute( 'data-layout', 'masonry' );
	} );

	test( 'the widget is registered under the name and keywords the panel search uses', () => {
		const out = wp(
			'eval',
			"$w = \\Elementor\\Plugin::$instance->widgets_manager->get_widget_types( 'profotograaf_gallery' ); echo 'WIDGET:' . ( $w ? $w->get_title() . '|' . implode( ',', $w->get_keywords() ) : 'missing' );"
		);
		expect( out ).toContain( 'WIDGET:Profotograaf Gallery|' );
		expect( out ).toContain( 'gallery' );
	} );
} );
