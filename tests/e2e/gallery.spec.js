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
 * Creates a published post and returns its URL.
 *
 * @param {string} title
 * @param {string} content
 */
function publishPost( title, content ) {
	const id = wp( 'post', 'create', '--porcelain', '--post_status=publish', `--post_title=${ title }`, `--post_content=${ content }` );
	return wp( 'post', 'list', '--post__in=' + id, '--field=url', '--post_type=post' );
}

test.describe( 'Gallery block, shortcode and oEmbed', () => {
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

		// Let WordPress fetch oEmbed results and the script version from the mock,
		// which listens on a port the safe HTTP functions refuse by default.
		wp(
			'eval',
			"wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/e2e-http.php', '<?php add_filter( \"http_request_host_is_external\", \"__return_true\" ); add_filter( \"http_allowed_safe_ports\", function ( $p ) { $p[] = 8090; return $p; } );' );"
		);
	} );

	test.afterAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'the editor lists the galleries, and the saved block shows the embed to a visitor', async ( { page, browser } ) => {
		await login( page );
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction( () => window.wp && window.wp.data && window.wp.blocks && window.wp.data.select( 'core/block-editor' ) );
		await page.evaluate( () => {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'fullscreenMode', false );
			window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( 'profotograaf/gallery' ) );
		} );

		// The block editor draws the canvas in an iframe.
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const card = canvas.getByRole( 'button', { name: /Spring wedding/ } );
		await expect( card ).toBeVisible( { timeout: 20_000 } );
		await expect( card ).toContainText( '3 photos' );
		await card.click();
		await expect( canvas.getByText( 'Spring wedding' ).first() ).toBeVisible();

		// Choose the layout in the sidebar through the store: the panel is the same control.
		await page.evaluate( () => {
			const [ block ] = window.wp.data.select( 'core/block-editor' ).getBlocks();
			window.wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, { layout: 'masonry' } );
			window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'Block E2E', status: 'publish' } );
			return window.wp.data.dispatch( 'core/editor' ).savePost();
		} );
		await page.waitForFunction( () => window.wp.data.select( 'core/editor' ).isCurrentPostPublished() );
		const permalink = await page.evaluate( () => window.wp.data.select( 'core/editor' ).getPermalink() );

		const visitor = await browser.newContext();
		const anonymous = await visitor.newPage();
		await anonymous.goto( permalink );

		const embed = anonymous.locator( 'div[data-profotograaf-gallery="g-e2e"]' );
		await expect( embed ).toHaveAttribute( 'data-layout', 'masonry' );
		await expect( embed.getByRole( 'link', { name: 'Spring wedding' } ) ).toHaveAttribute( 'href', /\/share\/g\/spring-wedding$/ );
		const scripts = anonymous.locator( 'script[src*="/share/embed/embed"]' );
		await expect( scripts ).toHaveCount( 1 );
		await expect( scripts.first() ).toHaveAttribute( 'async', '' );
		await visitor.close();
	} );

	test( 'the shortcode renders the same markup for a visitor, with the link looked up by id', async ( { browser } ) => {
		const url = publishPost( 'Shortcode E2E', '[profotograaf_gallery id="g-e2e" layout="slideshow"]' );

		const visitor = await browser.newContext();
		const page = await visitor.newPage();
		await page.goto( url );

		const embed = page.locator( 'div[data-profotograaf-gallery="g-e2e"]' );
		await expect( embed ).toHaveAttribute( 'data-layout', 'slideshow' );
		await expect( embed.getByRole( 'link', { name: 'Spring wedding' } ) ).toBeVisible();
		await expect( page.locator( 'script[src*="/share/embed/embed"]' ) ).toHaveCount( 1 );
		await visitor.close();
	} );

	test( 'a page with two galleries loads the script once', async ( { browser } ) => {
		const url = publishPost(
			'Two shortcodes E2E',
			'[profotograaf_gallery id="g-e2e"] [profotograaf_gallery id="g-e2e" layout="masonry"]'
		);

		const visitor = await browser.newContext();
		const page = await visitor.newPage();
		await page.goto( url );

		await expect( page.locator( 'div[data-profotograaf-gallery="g-e2e"]' ) ).toHaveCount( 2 );
		await expect( page.locator( 'script[src*="/share/embed/embed"]' ) ).toHaveCount( 1 );
		await visitor.close();
	} );

	test( 'a pasted gallery link embeds through the platform oEmbed endpoint', async ( { browser } ) => {
		const url = publishPost( 'Oembed E2E', 'http://mock-platform:8090/share/g/spring-wedding' );

		const visitor = await browser.newContext();
		const page = await visitor.newPage();
		await page.goto( url );

		const embed = page.locator( 'div[data-profotograaf-gallery="g-e2e"]' );
		await expect( embed.getByRole( 'link', { name: 'Spring wedding' } ) ).toHaveAttribute( 'href', 'http://mock-platform:8090/share/g/spring-wedding' );
		const scripts = page.locator( 'script[src*="/share/embed/embed"]' );
		await expect( scripts ).toHaveCount( 1 );
		await expect( scripts.first() ).toHaveAttribute( 'src', /\/share\/embed\/embed\.[a-f0-9]{12}\.js$/ );
		await visitor.close();
	} );

	test( 'the picker route refuses visitors', async ( { request } ) => {
		const response = await request.get( '/?rest_route=/profotograaf/v1/galleries' );

		expect( [ 401, 403 ] ).toContain( response.status() );
	} );
} );
