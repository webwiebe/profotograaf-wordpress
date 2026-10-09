// @ts-check
//
// Spike #104: the virtual attachment prototype (docs/virtual-attachments.md).
//
// The prototype is hidden behind PROFOTOGRAAF_VIRTUAL_ATTACHMENTS or the filter
// profotograaf_virtual_attachments. This spec switches the filter on and off
// through a must-use plugin that reads one option, so it proves both sides: the
// first test shows nothing changes with the flag off, the others run with it on.
//
// Run it against one WordPress version with:
//   WP_VERSION=6.9 tests/e2e/up.sh && pnpm exec playwright test virtual-attachments
// E2E_COMPOSE_PROJECT names another compose project when a second stack runs.
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, MOCK } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];
const WP_PORT = process.env.WP_PORT || 8080;
const SITE = `http://localhost:${ WP_PORT }`;

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

// Services every PHP snippet below starts from. $out() prints one JSON line.
const PRELUDE = [
	'wp_set_current_user( 1 );',
	'$p = Profotograaf\\Plugin::instance(); $s = $p->settings();',
	'$c = new Profotograaf\\Photo_Catalogue( $p->api(), $s );',
	'$i = new Profotograaf\\Photo_Importer( $s, null, $c );',
	'$v = new Profotograaf\\Virtual_Attachments( $s, $i, $c );',
	'$out = function ( $data ) { echo "\\nJSON:" . wp_json_encode( $data ) . "\\n"; };',
	'$row = function ( $id ) use ( $c ) { foreach ( $c->get()["photos"] as $r ) { if ( $r["id"] === $id ) { return $r; } } return null; };',
].join( ' ' );

/**
 * Runs PHP inside WordPress and returns what the snippet passed to $out().
 *
 * @param {string} code
 * @return {any}
 */
function php( code ) {
	const text = wp( 'eval', `${ PRELUDE } ${ code }` );
	const line = text.split( '\n' ).reverse().find( ( l ) => l.startsWith( 'JSON:' ) );
	if ( ! line ) {
		throw new Error( `No JSON line in: ${ text }` );
	}
	return JSON.parse( line.slice( 5 ) );
}

/** @param {boolean} on */
function flag( on ) {
	wp( 'option', 'update', 'pf_e2e_virtual', on ? '1' : '0' );
}

/** @param {'' | 'early' | 'late'} mode */
function offload( mode ) {
	wp( 'option', 'update', 'pf_e2e_offload', mode );
}

/** @param {boolean} on */
function mediaSource( on ) {
	wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: on } ), '--format=json' );
}

const MU_PLUGIN = [
	'<?php',
	// The flag, read from an option so a test can flip it between requests.
	'add_filter( "profotograaf_virtual_attachments", function () { return "1" === get_option( "pf_e2e_virtual", "0" ); } );',
	// A stand-in for an offload plugin: it rewrites every attachment URL to a CDN host.
	'$pf_offload = function ( $url ) { return preg_replace( "~^https?://[^/]+~", "https://cdn.example.test", (string) $url ); };',
	'if ( "early" === get_option( "pf_e2e_offload", "" ) ) { add_filter( "wp_get_attachment_url", $pf_offload, 10 ); }',
	'if ( "late" === get_option( "pf_e2e_offload", "" ) ) { add_filter( "wp_get_attachment_url", $pf_offload, 30 ); }',
	// The editor guard, switched off by a test that records what happens without it.
	'add_filter( "profotograaf_virtual_attachments_block_editing", function () { return "1" !== get_option( "pf_e2e_unguarded", "0" ); } );',
	// The mock is reached under another name than the test browser uses.
	'add_filter( "http_request_host_is_external", "__return_true" );',
	'add_filter( "http_allowed_safe_ports", function ( $p ) { $p[] = 8090; return $p; } );',
].join( ' ' );

/** Removes everything the spec created, so it can run again. */
function cleanUp() {
	wp(
		'eval',
		"foreach ( get_posts( array( 'post_type' => array( 'attachment', 'post' ), 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => 'pf_e2e', 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); } foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_profotograaf_photo_id', 'fields' => 'ids' ) ) as $id ) { wp_delete_attachment( $id, true ); } delete_option( 'profotograaf_photo_catalogue' );"
	);
}

/**
 * Sends the browser's requests for the stored platform host to the mock. WordPress
 * stores the host it reached the mock under (mock-platform), the browser reaches it
 * on localhost.
 *
 * @param {import('@playwright/test').BrowserContext} context
 */
async function routeMock( context ) {
	await context.route( /^http:\/\/mock-platform:8090\//, ( route ) =>
		route.continue( { url: route.request().url().replace( 'http://mock-platform:8090', MOCK ) } )
	);
}

/**
 * Talks to the mock platform's control endpoints. The spec waits longer between
 * calls than the mock keeps an idle connection, so each call opens its own.
 *
 * @param {string} route
 * @param {'GET'|'POST'} method
 */
async function mock( route, method = 'POST' ) {
	const response = await fetch( `${ MOCK }${ route }`, { method, headers: { connection: 'close' } } );
	return response.json();
}

const VERSION = () => wp( 'core', 'version' );

/** The observed values, printed so the matrix in the docs can be checked against a run. */
function observe( label, value ) {
	console.log( `OBSERVED ${ label }: ${ JSON.stringify( value ) }` );
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Virtual attachments prototype', () => {
	/** @type {Record<string, number>} */
	let ids = {};
	let postId = 0;
	let wpVersion = '';

	test.beforeAll( async ( { browser } ) => {
		wpVersion = VERSION();
		await mock( '/__reset' );
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Connect to Profotograaf' } ).click();
		await expect( page.locator( '.profotograaf-code' ) ).toBeVisible();
		await mock( '/__approve' );
		await expect( page.getByText( 'Connected to Profotograaf.' ) ).toBeVisible( { timeout: 20_000 } );
		await page.close();

		wp( 'eval', `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/e2e-virtual.php', base64_decode( '${ Buffer.from( MU_PLUGIN ).toString( 'base64' ) }' ) );` );
		flag( false );
		offload( '' );
		mediaSource( true );
		cleanUp();
	} );

	test.afterAll( async ( { browser } ) => {
		await mock( '/__mode?mode=up' );
		flag( false );
		offload( '' );
		mediaSource( false );
		cleanUp();
		wp( 'eval', "@unlink( WPMU_PLUGIN_DIR . '/e2e-virtual.php' ); delete_option( 'pf_e2e_virtual' ); delete_option( 'pf_e2e_offload' ); delete_option( 'pf_e2e_unguarded' );" );
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'with the flag off nothing hooks in, and a marked attachment keeps its core URLs', async () => {
		flag( true );
		const made = php(
			'$cat = $c->refresh(); $made = array(); foreach ( array( "p-1", "p-2", "p-3" ) as $pid ) { $made[ $pid ] = $v->create( $row( $pid ) ); } $out( $made );'
		);
		ids = made;
		expect( Object.values( made ).every( ( id ) => Number.isInteger( id ) && id > 0 ) ).toBe( true );

		flag( false );
		const off = php(
			'global $wp_filter; $hooked = false; foreach ( array( "wp_get_attachment_url", "image_downsize", "wp_calculate_image_srcset", "wp_prepare_attachment_for_js" ) as $h ) { foreach ( ( isset( $wp_filter[ $h ] ) ? $wp_filter[ $h ]->callbacks : array() ) as $cbs ) { foreach ( $cbs as $cb ) { if ( is_array( $cb["function"] ) && $cb["function"][0] instanceof Profotograaf\\Virtual_Attachments ) { $hooked = true; } } } }' +
				` $id = ${ made[ 'p-1' ] }; $out( array( "enabled" => Profotograaf\\Virtual_Attachments::enabled(), "hooked" => $hooked, "cron" => (bool) wp_next_scheduled( "profotograaf_virtual_sync" ), "url" => wp_get_attachment_url( $id ), "src" => wp_get_attachment_image_src( $id, "full" ) ) );`
		);
		observe( 'flag off', off );
		expect( off.enabled ).toBe( false );
		expect( off.hooked ).toBe( false );
		expect( off.url ).toContain( '/wp-content/uploads/profotograaf-virtual/' );
		expect( off.url ).not.toContain( 'mock-platform' );

		// A normal import still works and still yields a local file with the flag off.
		const imported = php( `$id = $i->import( $row( "p-4" ) ); $out( array( "id" => $id, "url" => wp_get_attachment_url( $id ), "virtual" => get_post_meta( $id, "_profotograaf_virtual", true ) ) );` );
		expect( imported.url ).toContain( '/wp-content/uploads/' );
		expect( imported.virtual ).toBe( '' );
	} );

	test( 'core functions return the stored platform URLs and sizes', async () => {
		flag( true );
		const id = ids[ 'p-1' ];
		const core = php(
			`$id = ${ id };` +
				' $img = wp_get_attachment_image( $id, "large" );' +
				' $req = new WP_REST_Request( "GET", "/wp/v2/media/" . $id ); $res = rest_do_request( $req ); $media = $res->get_data();' +
				' $post = wp_insert_post( array( "post_title" => "thumb", "post_status" => "publish" ) ); $set = set_post_thumbnail( $post, $id );' +
				' $out( array(' +
				' "url" => wp_get_attachment_url( $id ),' +
				' "full" => wp_get_attachment_image_src( $id, "full" ),' +
				' "large" => wp_get_attachment_image_src( $id, "large" ),' +
				' "thumbnail" => wp_get_attachment_image_src( $id, "thumbnail" ),' +
				' "box" => wp_get_attachment_image_src( $id, array( 300, 300 ) ),' +
				' "img" => $img,' +
				' "srcset" => wp_get_attachment_image_srcset( $id, "large" ),' +
				' "meta" => wp_get_attachment_metadata( $id ),' +
				' "original" => wp_get_original_image_path( $id ),' +
				' "file_exists" => file_exists( (string) get_attached_file( $id ) ),' +
				' "rest" => array( "status" => $res->get_status(), "source_url" => $media["source_url"] ?? null, "mime" => $media["mime_type"] ?? null, "details" => $media["media_details"] ?? null ),' +
				' "thumbnail_set" => $set, "thumbnail_url" => get_the_post_thumbnail_url( $post, "full" ), "thumbnail_html" => get_the_post_thumbnail( $post, "medium" ), "post" => $post,' +
				' "edit_supported" => wp_image_editor_supports( array( "mime_type" => "image/jpeg", "methods" => array( "rotate" ) ) ) ) );'
		);
		observe( 'core', core );
		wp( 'post', 'meta', 'add', String( core.post ), 'pf_e2e', '1' );

		expect( core.url ).toMatch( /^http:\/\/mock-platform:8090\/img\/p-1\/web\.jpg$/ );
		expect( core.full ).toEqual( [ core.url, 1600, 1067, false ] );
		expect( core.large[ 0 ] ).toBe( core.url );
		expect( core.thumbnail ).toEqual( [ 'http://mock-platform:8090/img/p-1/thumb.jpg', 400, 400, true ] );
		expect( core.box[ 0 ] ).toContain( '/thumb.jpg' );
		expect( core.img ).toContain( `src="${ core.url }"` );
		expect( core.img ).toContain( 'width="1600"' );
		expect( core.srcset ).toBe( false );
		expect( core.meta.sizes.thumbnail.width ).toBe( 400 );
		expect( core.file_exists ).toBe( false );
		expect( core.rest.status ).toBe( 200 );
		expect( core.rest.source_url ).toBe( core.url );
		expect( core.rest.mime ).toBe( 'image/jpeg' );
		expect( core.rest.details.width ).toBe( 1600 );
		expect( core.thumbnail_set ).toBeTruthy();
		expect( core.thumbnail_url ).toBe( core.url );
		expect( core.thumbnail_html ).toContain( 'mock-platform:8090' );
	} );

	test( 'Image block (crop, duotone, lightbox, caption), Gallery block and featured image render from stored data', async ( { page, context } ) => {
		await routeMock( context );
		const [ a, b, c ] = [ ids[ 'p-1' ], ids[ 'p-2' ], ids[ 'p-3' ] ];
		const image = ( id, extra = '', attrs = '' ) =>
			`<!-- wp:image {"id":${ id },"sizeSlug":"large","linkDestination":"none"${ attrs }} --><figure class="wp-block-image size-large"><img src="http://mock-platform:8090/img/p-${ id === a ? 1 : id === b ? 2 : 3 }/web.jpg" alt="" class="wp-image-${ id }"${ extra }/>CAPTION</figure><!-- /wp:image -->`;
		const single = image(
			a,
			' style="aspect-ratio:3/2;object-fit:cover"',
			',"aspectRatio":"3/2","scale":"cover","lightbox":{"enabled":true},"style":{"color":{"duotone":"var:preset|duotone|dark-grayscale"}}'
		).replace( 'CAPTION', '<figcaption class="wp-element-caption">First dance</figcaption>' );
		const gallery = `<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped">${ [ a, b, c ].map( ( id ) => image( id ).replace( 'CAPTION', '' ) ).join( '' ) }</figure><!-- /wp:gallery -->`;
		const shortcode = `<!-- wp:shortcode -->[gallery ids="${ a },${ b }" link="none"]<!-- /wp:shortcode -->`;
		const content = `${ single }\n${ gallery }\n${ shortcode }`;

		postId = php(
			`$post = wp_insert_post( array( "post_title" => "Virtual blocks", "post_status" => "publish", "post_content" => ${ JSON.stringify( content ) } ) ); update_post_meta( $post, "pf_e2e", "1" ); set_post_thumbnail( $post, ${ a } ); $out( $post );`
		);

		const calls = ( await mock( '/__state', 'GET' ) ).apiHits.length;
		await page.goto( `/?p=${ postId }` );
		expect( ( await mock( '/__state', 'GET' ) ).apiHits.length ).toBe( calls );

		const html = await page.content();
		observe( 'front end sizes attr', await page.locator( 'figure.wp-block-image img' ).first().getAttribute( 'sizes' ) );
		const platformImages = page.locator( 'img[src*="mock-platform:8090/img/"]' );
		await expect.poll( () => platformImages.count() ).toBeGreaterThanOrEqual( 6 );

		// Every platform image loaded: the stored URL is a working one.
		const broken = await page.evaluate( () =>
			Array.from( document.querySelectorAll( 'img[src*="mock-platform"]' ) )
				.filter( ( img ) => ! ( /** @type {HTMLImageElement} */ ( img ).complete ) || /** @type {HTMLImageElement} */ ( img ).naturalWidth === 0 )
				.map( ( img ) => img.getAttribute( 'src' ) )
		);
		expect( broken ).toEqual( [] );

		// Caption.
		await expect( page.locator( 'figcaption' ).first() ).toHaveText( 'First dance' );
		// Crop (aspect ratio and object-fit are CSS on the image).
		const style = await page.locator( 'figure.wp-block-image img' ).first().getAttribute( 'style' );
		expect( style ).toContain( 'aspect-ratio' );
		expect( style ).toContain( 'object-fit:cover' );
		// Duotone.
		expect( html ).toMatch( /wp-duotone-/ );
		// srcset: one candidate only, so core leaves it out.
		expect( await page.locator( 'figure.wp-block-image img' ).first().getAttribute( 'srcset' ) ).toBeNull();
		// Gallery block: three nested images.
		await expect( page.locator( '.wp-block-gallery figure.wp-block-image' ) ).toHaveCount( 3 );
		// The gallery shortcode asks for the thumbnail size.
		await expect( page.locator( '.gallery img[src*="thumb.jpg"]' ) ).toHaveCount( 2 );
		// Featured image: shown by the theme when it prints one.
		const featured = await page.locator( '.wp-block-post-featured-image img, img.wp-post-image' ).count();
		observe( 'featured image elements on the single post page', featured );

		// Lightbox.
		await page.locator( 'figure.wp-block-image img' ).first().click();
		const overlay = page.locator( '.wp-lightbox-overlay.active' );
		await expect( overlay ).toBeVisible( { timeout: 10_000 } );
		const lightboxSrc = await overlay.locator( 'img' ).first().getAttribute( 'src' );
		observe( 'lightbox image src', lightboxSrc );
		expect( lightboxSrc ).toContain( 'mock-platform:8090/img/p-1/' );
	} );

	test( 'the block editor opens the blocks without a validation error and REST media serves the attachment', async ( { page, context } ) => {
		await routeMock( context );
		await login( page );
		await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
		await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/block-editor' ) && window.wp.data.select( 'core/block-editor' ).getBlocks().length > 0, null, { timeout: 60_000 } );
		await page.evaluate( () => {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		} );

		const state = await page.evaluate( () => {
			const select = window.wp.data.select( 'core/block-editor' );
			const flat = [];
			const walk = ( blocks ) => blocks.forEach( ( b ) => { flat.push( b ); walk( b.innerBlocks ); } );
			walk( select.getBlocks() );
			return {
				invalid: flat.filter( ( b ) => ! b.isValid ).map( ( b ) => b.name ),
				images: flat.filter( ( b ) => b.name === 'core/image' ).map( ( b ) => ( { id: b.attributes.id, url: b.attributes.url } ) ),
			};
		} );
		observe( 'editor', state );
		expect( state.invalid ).toEqual( [] );
		expect( state.images.length ).toBe( 4 );
		expect( state.images.every( ( i ) => i.url && i.url.includes( 'mock-platform:8090/img/' ) ) ).toBe( true );
		await expect( page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( 'figure.wp-block-image img' ).first() ).toBeVisible( { timeout: 30_000 } );
		await expect.poll( () => page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( 'figure.wp-block-image img' ).first().evaluate( ( img ) => /** @type {HTMLImageElement} */ ( img ).naturalWidth ) ).toBeGreaterThan( 0 );

		// REST media as the editor reads it.
		const id = ids[ 'p-1' ];
		const media = await page.evaluate( async ( attachment ) => {
			const item = await window.wp.apiFetch( { path: `/wp/v2/media/${ attachment }?context=edit` } );
			return { source_url: item.source_url, sizes: Object.keys( item.media_details.sizes || {} ), width: item.media_details.width, alt: item.alt_text };
		}, id );
		observe( 'rest media', media );
		expect( media.source_url ).toContain( 'mock-platform:8090/img/p-1/web.jpg' );
		expect( media.sizes ).toContain( 'thumbnail' );
		expect( media.width ).toBe( 1600 );

		// Rotate and crop write a new file from the one on disk. There is none.
		const edit = await page.evaluate( async ( [ attachment, src ] ) => {
			const result = {};
			for ( const [ key, modifiers ] of Object.entries( {
				rotate: [ { type: 'rotate', args: { angle: 90 } } ],
				crop: [ { type: 'crop', args: { left: 0, top: 0, width: 50, height: 50 } } ],
			} ) ) {
				try {
					const item = await window.wp.apiFetch( { path: `/wp/v2/media/${ attachment }/edit`, method: 'POST', data: { src, modifiers } } );
					result[ key ] = { ok: true, id: item.id };
				} catch ( error ) {
					result[ key ] = { ok: false, code: error.code, message: error.message };
				}
			}
			return result;
		}, [ id, media.source_url ] );
		observe( 'rest edit', edit );
		expect( edit.rotate.ok ).toBe( false );
		expect( edit.crop.ok ).toBe( false );
	} );

	test( 'the Media Library shows the attachment, and the legacy image editor cannot change it', async ( { page, context } ) => {
		await routeMock( context );
		await login( page );
		const id = ids[ 'p-1' ];
		await page.goto( `/wp-admin/upload.php?item=${ id }` );
		await page.waitForFunction( ( attachment ) => window.wp && window.wp.media && window.wp.media.attachment( attachment ), id );
		const modal = await page.evaluate( async ( attachment ) => {
			const model = window.wp.media.attachment( attachment );
			await model.fetch();
			return { virtual: model.get( 'profotograafVirtual' ), url: model.get( 'url' ), thumb: model.get( 'sizes' )?.thumbnail?.url, full: model.get( 'sizes' )?.full?.url };
		}, id );
		observe( 'media modal', modal );
		expect( modal.virtual ).toBe( true );
		expect( modal.url ).toContain( 'mock-platform:8090/img/p-1/web.jpg' );
		expect( modal.thumb ).toContain( '/thumb.jpg' );

		await expect( page.locator( '.media-modal .attachment-details' ) ).toBeVisible( { timeout: 30_000 } );
		await expect( page.locator( '.media-modal .details-image' ) ).toHaveAttribute( 'src', /mock-platform:8090\/img\/p-1\// );

		// Legacy editor on the attachment screen (Edit image): open it, rotate, save.
		/** @type {string[]} */
		const ajax = [];
		page.on( 'response', async ( response ) => {
			if ( response.url().includes( 'admin-ajax.php' ) ) {
				const data = response.request().postData() || response.url();
				const action = /action=([\w-]+)/.exec( data )?.[ 1 ] || '?';
				ajax.push( `${ action }:${ response.status() }:${ response.headers()[ 'content-type' ] || '' }` );
			}
		} );
		const legacyEdit = async ( label ) => {
			await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
			const open = page.locator( `#imgedit-open-btn-${ id }` );
			observe( `${ label } edit image button`, await open.count() );
			await open.click();
			const panel = page.locator( `#image-editor-${ id }` );
			await expect( panel ).toBeVisible( { timeout: 15_000 } );
			await page.waitForTimeout( 2000 );
			const preview = await page.evaluate( ( attachment ) => {
				const img = document.querySelector( `#image-editor-${ attachment } img` );
				return img ? { src: img.getAttribute( 'src' ), width: /** @type {HTMLImageElement} */ ( img ).naturalWidth } : null;
			}, id );
			observe( `${ label } legacy editor preview`, preview );
			// The buttons sit in a toolbar the 48 px mock image keeps hidden, so call the editor the way they do.
			await page.evaluate( ( attachment ) => {
				const img = document.querySelector( `#image-editor-${ attachment } img` );
				if ( ! img ) {
					return null;
				}
				const nonce = /_ajax_nonce=(\w+)/.exec( img.getAttribute( 'src' ) )[ 1 ];
				window.imageEdit.rotate( 90, attachment, nonce, document.querySelector( `#image-editor-${ attachment } .imgedit-rleft` ) );
				return nonce;
			}, id );
			await page.waitForTimeout( 3000 );
			await page.evaluate( ( attachment ) => {
				const img = document.querySelector( `#image-editor-${ attachment } img` );
				if ( ! img ) {
					return null;
				}
				const nonce = /_ajax_nonce=(\w+)/.exec( img.getAttribute( 'src' ) )[ 1 ];
				window.imageEdit.save( attachment, nonce );
			}, id );
			await page.waitForTimeout( 5000 );
			observe( `${ label } legacy editor ajax`, ajax );
			observe( `${ label } legacy editor message`, ( await page.locator( `#imgedit-response-${ id }` ).innerText() ).trim().slice( 0, 200 ) );

			const after = php( `$id = ${ id }; $out( array( "url" => wp_get_attachment_url( $id ), "virtual" => get_post_meta( $id, "_profotograaf_virtual", true ), "file" => get_post_meta( $id, "_wp_attached_file", true ), "exists" => file_exists( (string) get_attached_file( $id ) ), "backup" => get_post_meta( $id, "_wp_attachment_backup_sizes", true ), "meta" => wp_get_attachment_metadata( $id ) ) );` );
			observe( `${ label } attachment after the legacy editor`, after );
			return after;
		};

		// With the guard (the default) the editor refuses and nothing is written.
		const before = php( `$id = ${ id }; $out( array( "file" => get_post_meta( $id, "_wp_attached_file", true ), "url" => wp_get_attachment_url( $id ) ) );` );
		const guarded = await legacyEdit( 'guarded' );
		expect( guarded.file ).toBe( before.file );
		expect( guarded.url ).toBe( before.url );
		expect( guarded.backup ).toBeFalsy();

		// Without it, the editor writes a local copy while the attachment keeps serving the platform URL.
		wp( 'eval', "update_option( 'pf_e2e_unguarded', '1' );" );
		await legacyEdit( 'unguarded' );
		wp( 'eval', "delete_option( 'pf_e2e_unguarded' );" );
	} );

	test( 'an offload plugin that filters the URL at the default priority does not take over a virtual attachment', async () => {
		const id = ids[ 'p-1' ];
		const normal = php( `$out( wp_get_attachment_url( ${ ids[ 'p-1' ] } ) );` );
		expect( normal ).toContain( 'mock-platform' );

		offload( 'early' );
		const early = php( `$id = ${ id }; $out( array( "virtual" => wp_get_attachment_url( $id ), "local" => wp_get_attachment_url( ${ php( 'global $wpdb; $out( (int) $wpdb->get_var( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = \'_profotograaf_photo_id\' AND meta_value = \'p-4\' LIMIT 1" ) );' ) } ) ) );` );
		observe( 'offload early', early );
		// Ours runs at priority 20, after the offload plugin's 10: the platform URL wins for the virtual one.
		expect( early.virtual ).toContain( 'mock-platform' );
		expect( early.local ).toContain( 'cdn.example.test' );

		offload( 'late' );
		const late = php( `$out( wp_get_attachment_url( ${ id } ) );` );
		observe( 'offload late', late );
		// An offload plugin at a later priority rewrites the host of our URL.
		expect( late ).toContain( 'cdn.example.test' );
		offload( '' );
	} );

	test( 'platform down: the front end makes no call and keeps the stored URLs, the sync skips', async ( { page, context } ) => {
		await routeMock( context );
		await mock( '/__mode?mode=down' );
		const before = ( await mock( '/__state', 'GET' ) ).apiHits.length;

		const response = await page.goto( `/?p=${ postId }` );
		expect( response?.status() ).toBe( 200 );
		const html = await page.content();
		expect( html ).toContain( 'mock-platform:8090/img/p-1/web.jpg' );
		expect( ( await mock( '/__state', 'GET' ) ).apiHits.length ).toBe( before );
		// The browser asks the platform for the pixels, and the platform is down.
		await expect.poll( () => page.evaluate( () => Array.from( document.querySelectorAll( 'img[src*="mock-platform"]' ) ).every( ( img ) => /** @type {HTMLImageElement} */ ( img ).complete && /** @type {HTMLImageElement} */ ( img ).naturalWidth === 0 ) ) ).toBe( true );

		const sync = php( '$cat = $c->refresh(); $out( array( "stale" => $cat["stale"], "sync" => $v->sync( $cat ) ) );' );
		observe( 'sync while down', sync );
		expect( sync.stale ).toBe( true );
		expect( sync.sync.skipped ).toBe( true );
		expect( sync.sync.gone ).toBe( 0 );

		const convert = php( `$r = $v->convert_to_local( ${ ids[ 'p-1' ] } ); $out( is_wp_error( $r ) ? $r->get_error_code() : $r );` );
		observe( 'convert while down', convert );
		expect( typeof convert ).toBe( 'string' );
		const state = php( `$out( array( "virtual" => $v->is_virtual( ${ ids[ 'p-1' ] } ), "url" => wp_get_attachment_url( ${ ids[ 'p-1' ] } ) ) );` );
		expect( state.virtual ).toBe( true );
		await mock( '/__mode?mode=up' );
	} );

	test( 'unpublish: the stored URL 404s, the sync marks the attachment gone, convert to local cannot rescue it', async ( { page, context } ) => {
		await routeMock( context );
		await mock( '/__mode?mode=unpublished' );

		const response = await page.goto( `/?p=${ postId }` );
		expect( response?.status() ).toBe( 200 );
		await expect.poll( () => page.evaluate( () => Array.from( document.querySelectorAll( 'img[src*="mock-platform"]' ) ).every( ( img ) => /** @type {HTMLImageElement} */ ( img ).complete && /** @type {HTMLImageElement} */ ( img ).naturalWidth === 0 ) ) ).toBe( true );

		const sync = php( '$cat = $c->refresh(); $out( array( "stale" => $cat["stale"], "photos" => count( $cat["photos"] ), "sync" => $v->sync( $cat ) ) );' );
		observe( 'sync after unpublish', sync );
		expect( sync.photos ).toBe( 0 );
		expect( sync.sync.gone ).toBe( 3 );
		const again = php( '$cat = $c->get(); $out( $v->sync( $cat ) );' );
		expect( again.gone ).toBe( 0 );

		const id = ids[ 'p-1' ];
		const gone = php( `$out( array( "meta" => (int) get_post_meta( ${ id }, "_profotograaf_virtual_gone", true ), "url" => wp_get_attachment_url( ${ id } ) ) );` );
		expect( gone.meta ).toBeGreaterThan( 0 );
		expect( gone.url ).toContain( 'mock-platform:8090' );

		const convert = php( `$r = $v->convert_to_local( ${ id } ); $out( is_wp_error( $r ) ? $r->get_error_code() : $r );` );
		expect( convert ).toBe( 'profotograaf_photo_gone' );
		await mock( '/__mode?mode=up' );
	} );

	test( 'back online: the sync clears the gone mark, and convert to local keeps the id and serves a local file', async ( { page } ) => {
		const sync = php( '$cat = $c->refresh(); $out( array( "stale" => $cat["stale"], "sync" => $v->sync( $cat ) ) );' );
		expect( sync.sync.updated ).toBe( 3 );
		expect( sync.sync.gone ).toBe( 0 );

		const id = ids[ 'p-1' ];
		const converted = php( `$r = $v->convert_to_local( ${ id } ); $out( array( "result" => is_wp_error( $r ) ? $r->get_error_code() : $r, "url" => wp_get_attachment_url( ${ id } ), "virtual" => get_post_meta( ${ id }, "_profotograaf_virtual", true ), "photo" => get_post_meta( ${ id }, "_profotograaf_photo_id", true ), "file" => file_exists( get_attached_file( ${ id } ) ), "meta" => wp_get_attachment_metadata( ${ id } ) ) );` );
		observe( 'convert', converted );
		expect( converted.result ).toBe( id );
		expect( converted.url ).toContain( `${ SITE.replace( /:\d+$/, '' ) }` );
		expect( converted.url ).toContain( '/wp-content/uploads/' );
		expect( converted.virtual ).toBe( '' );
		expect( converted.photo ).toBe( 'p-1' );
		expect( converted.file ).toBe( true );

		// Posts that used the attachment keep working: the id is the same, the file is now local.
		await page.goto( `/?p=${ postId }` );
		await expect( page.locator( 'figure.wp-block-image img' ).first() ).toBeVisible();
		const src = await page.locator( 'figure.wp-block-image img' ).first().getAttribute( 'src' );
		observe( 'front end src after convert', src );
		expect( src ).toContain( '/wp-content/uploads/' );

		// Deleting a virtual attachment removes the post and calls nothing on the platform.
		const before = ( await mock( '/__state', 'GET' ) ).apiHits.length;
		const removed = php( `$out( (bool) wp_delete_attachment( ${ ids[ 'p-3' ] }, true ) );` );
		expect( removed ).toBe( true );
		expect( ( await mock( '/__state', 'GET' ) ).apiHits.length ).toBe( before );
		observe( 'wordpress version', wpVersion );
	} );
} );
