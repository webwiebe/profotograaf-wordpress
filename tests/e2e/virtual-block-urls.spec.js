// @ts-check
//
// Issue #154 (and #219): Image and Gallery blocks saved with the Profotograaf
// URL of a photo kept on Profotograaf show the current URL after the photo is
// converted to local or gets a new version. The front end rewrites the saved
// markup when it renders, and the editor updates the `url` attribute of the
// blocks. A conversion reaches posts also while the setting "Keep photos on
// Profotograaf" is off.
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

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

/** Removes the photos added to the Media Library, the test posts and the stored catalogue. */
function cleanUp() {
	wp(
		'eval',
		"foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_profotograaf_photo_id', 'fields' => 'ids' ) ) as $id ) { wp_delete_attachment( $id, true ); } foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => 'pf_e2e_urls', 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); } delete_option( 'profotograaf_photo_catalogue' ); delete_option( 'profotograaf_virtual_changes_cursor' );"
	);
}

/**
 * Keeps photos on Profotograaf through the REST route the Import screen uses.
 * Returns the attachment id by photo id.
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
 * An Image block as the editor saves it.
 *
 * @param {number} id  Attachment id.
 * @param {string} src Saved URL.
 */
const imageBlock = ( id, src ) =>
	`<!-- wp:image {"id":${ id },"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="${ src }" alt="" class="wp-image-${ id }"/></figure><!-- /wp:image -->`;

/**
 * The `url` attribute of every Image block in the editor, nested ones included.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<Array<{id:number,url:string}>>}
 */
function editorImages( page ) {
	return page.evaluate( () => {
		const flat = [];
		const walk = ( blocks ) =>
			blocks.forEach( ( b ) => {
				if ( b.name === 'core/image' ) {
					flat.push( { id: b.attributes.id, url: b.attributes.url } );
				}
				walk( b.innerBlocks );
			} );
		walk( window.wp.data.select( 'core/block-editor' ).getBlocks() );
		return flat;
	} );
}

/**
 * Opens a post in the block editor and waits for its blocks.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} postId
 */
async function openEditor( page, postId ) {
	await login( page );
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/block-editor' )?.getBlocks().length > 0, null, { timeout: 60_000 } );
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Current URLs in Image and Gallery blocks', () => {
	/** @type {number} */
	let postId = 0;
	/** @type {Record<string, number>} */
	let ids = {};

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

		settings( true );
		ids = keep( [ 'p-1', 'p-2' ] );
		const urls = php( `$out( array( wp_get_attachment_url( ${ ids[ 'p-1' ] } ), wp_get_attachment_url( ${ ids[ 'p-2' ] } ) ) );` );
		expect( urls[ 0 ] ).toContain( 'mock-platform:8090' );
		const gallery = `<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped">${ imageBlock( ids[ 'p-1' ], urls[ 0 ] ) }${ imageBlock( ids[ 'p-2' ], urls[ 1 ] ) }</figure><!-- /wp:gallery -->`;
		const content = `${ imageBlock( ids[ 'p-1' ], urls[ 0 ] ) }\n${ gallery }`;
		postId = php(
			`$post = wp_insert_post( array( "post_title" => "Block URLs", "post_status" => "publish", "post_content" => ${ JSON.stringify( content ) } ) ); update_post_meta( $post, "pf_e2e_urls", "1" ); $out( $post );`
		);
	} );

	test.afterAll( async ( { browser } ) => {
		wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: false, virtual_attachments: false } ), '--format=json' );
		cleanUp();
		await mock( '/__reset' );
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'a photo converted to local shows its local file with the setting off, in the Image block and the gallery', async ( { page } ) => {
		const a = ids[ 'p-1' ];
		const b = ids[ 'p-2' ];
		const converted = php(
			"wp_set_current_user( get_user_by( 'login', 'admin' )->ID ); $s = new Profotograaf\\Settings(); $c = new Profotograaf\\Photo_Catalogue( Profotograaf\\Plugin::instance()->api(), $s );" +
				` $v = new Profotograaf\\Virtual_Attachments( $s, new Profotograaf\\Photo_Importer( $s, null, $c ), $c ); $r = $v->convert_to_local( ${ a } ); $out( is_wp_error( $r ) ? $r->get_error_message() : $r );`
		);
		expect( converted ).toBe( a );
		settings( false );

		await page.goto( `/?p=${ postId }` );
		const local = page.locator( `img.wp-image-${ a }` );
		await expect( local ).toHaveCount( 2 );
		for ( const src of await local.evaluateAll( ( imgs ) => imgs.map( ( img ) => img.getAttribute( 'src' ) ) ) ) {
			expect( src ).toContain( '/wp-content/uploads/' );
		}
		// Still kept on Profotograaf with the setting off: no working URL, so the saved one stays.
		await expect( page.locator( `img.wp-image-${ b }` ) ).toHaveAttribute( 'src', /mock-platform:8090\/img\/p-2\// );

		await openEditor( page, postId );
		await expect
			.poll( async () => ( await editorImages( page ) ).filter( ( img ) => img.id === a ).map( ( img ) => img.url.includes( '/wp-content/uploads/' ) ) )
			.toEqual( [ true, true ] );
		expect( ( await editorImages( page ) ).find( ( img ) => img.id === b )?.url ).toContain( 'mock-platform:8090/img/p-2/' );
	} );

	test( 'a kept photo with a new version shows the new URL', async ( { page } ) => {
		const b = ids[ 'p-2' ];
		settings( true );
		const fresh = 'http://mock-platform:8090/img/p-2/web-v2.jpg';
		php( `update_post_meta( ${ b }, '_profotograaf_virtual_urls', array( 'thumb' => 'http://mock-platform:8090/img/p-2/thumb-v2.jpg', 'web' => '${ fresh }' ) ); $out( true );` );

		await page.goto( `/?p=${ postId }` );
		await expect( page.locator( `img.wp-image-${ b }` ) ).toHaveAttribute( 'src', fresh );

		await openEditor( page, postId );
		await expect.poll( async () => ( await editorImages( page ) ).find( ( img ) => img.id === b )?.url ).toBe( fresh );
		// The refresh adds no undo step of its own.
		expect( await page.evaluate( () => window.wp.data.select( 'core/editor' ).hasEditorUndo() ) ).toBe( false );
	} );
} );
