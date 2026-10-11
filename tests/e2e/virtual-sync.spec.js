// @ts-check
//
// Issue #153: photos kept on Profotograaf (virtual attachments) follow the
// change feed GET /api/v1/embed/changes with a stored cursor. Without a cursor
// the feed answers resync and the sync reads the full photo list. A deleted
// photo and an unavailable gallery mark attachments gone, a gallery that is
// available again clears the mark, and a failed feed changes nothing.
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login, mock, MOCK } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];
const CURSOR = 'profotograaf_virtual_changes_cursor';

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

/** Removes the photos added to the Media Library, the stored catalogue and the cursor. */
function cleanUp() {
	wp(
		'eval',
		`foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_profotograaf_photo_id', 'fields' => 'ids' ) ) as $id ) { wp_delete_attachment( $id, true ); } delete_option( 'profotograaf_photo_catalogue' ); delete_option( '${ CURSOR }' );`
	);
}

/**
 * Keeps photos on Profotograaf through the REST route the Import screen uses.
 *
 * @param {string[]} ids Photo ids.
 * @return {Record<string, number>} Attachment id by photo id.
 */
function keep( ids ) {
	const list = ids.map( ( id ) => `'${ id }'` ).join( ', ' );
	return php(
		"delete_option( 'profotograaf_photo_catalogue' ); wp_set_current_user( get_user_by( 'login', 'admin' )->ID );" +
			` $r = new WP_REST_Request( 'POST', '/profotograaf/v1/photos/keep' ); $r->set_body_params( array( 'ids' => array( ${ list } ) ) );` +
			" $made = array(); foreach ( rest_do_request( $r )->get_data()['results'] as $row ) { $made[ $row['id'] ] = $row['attachment_id']; } $out( $made );"
	);
}

/**
 * Runs the scheduled sync once and returns the cursor and the gone mark of each attachment.
 *
 * @param {Record<string, number>} made Attachment id by photo id.
 * @return {{ cursor: string, gone: Record<string, boolean> }}
 */
function sync( made ) {
	return php(
		"do_action( 'profotograaf_virtual_sync' ); $gone = array();" +
			` foreach ( json_decode( '${ JSON.stringify( made ) }', true ) as $photo => $id ) { $gone[ $photo ] = '' !== (string) get_post_meta( $id, '_profotograaf_virtual_gone', true ); }` +
			` $out( array( 'cursor' => (string) get_option( '${ CURSOR }', '' ), 'gone' => $gone ) );`
	);
}

/**
 * Calls a control endpoint of the mock. A wp-cli run takes longer than the
 * mock keeps an idle connection open, so a reused connection can be closed
 * under the request; that one attempt is repeated on a new connection.
 *
 * @param {string}      route
 * @param {'GET'|'POST'} method
 * @param {unknown}     [body]
 */
async function control( route, method, body ) {
	const init = body === undefined ? { method } : { method, headers: { 'content-type': 'application/json' }, body: JSON.stringify( body ) };
	try {
		return await ( await fetch( `${ MOCK }${ route }`, init ) ).json();
	} catch {
		return ( await fetch( `${ MOCK }${ route }`, init ) ).json();
	}
}

/**
 * Queues feed pages on the mock.
 *
 * @param {Record<string, unknown>[]} pages
 */
async function queue( pages ) {
	await control( '/__changes', 'POST', { pages } );
}

/** The since values the plugin sent to the change feed. */
async function sinces() {
	return /** @type {{ since: string }[]} */ ( await control( '/__changes', 'GET' ) ).map( ( call ) => call.since );
}

/**
 * Switches the mock platform mode.
 *
 * @param {string} name
 */
async function mode( name ) {
	await control( `/__mode?mode=${ name }`, 'POST' );
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Virtual attachment sync from the change feed', () => {
	/** @type {Record<string, number>} */
	let made = {};

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

		wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: true, virtual_attachments: true } ), '--format=json' );
		cleanUp();
		made = keep( [ 'p-1', 'p-2', 'p-4' ] );
	} );

	test.afterAll( async ( { browser } ) => {
		await mode( 'up' );
		wp( 'option', 'update', 'profotograaf_settings', JSON.stringify( { media_source: false, virtual_attachments: false } ), '--format=json' );
		cleanUp();
		await control( '/__reset', 'POST' );
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'the first run has no cursor, so the feed answers resync and the full list is read', async () => {
		const result = sync( made );

		expect( ( await sinces() ).at( -1 ) ).toBe( '' );
		expect( result ).toEqual( { cursor: 'cursor-1', gone: { 'p-1': false, 'p-2': false, 'p-4': false } } );
	} );

	test( 'a deleted photo and an unavailable gallery mark their attachments gone', async () => {
		await queue( [
			{
				galleries: { unavailable: [ { id: 'g-e2e-autumn', reason: 'password' } ] },
				photos: { deleted: [ { id: 'p-1', gallery_id: '' } ] },
			},
		] );

		const result = sync( made );

		expect( ( await sinces() ).at( -1 ) ).toBe( 'cursor-1' );
		expect( result ).toEqual( { cursor: 'cursor-next', gone: { 'p-1': true, 'p-2': false, 'p-4': true } } );
	} );

	test( 'a failed feed changes nothing', async () => {
		await queue( [ { galleries: { deleted: [ 'g-e2e' ] } } ] );
		await mode( 'down' );
		try {
			const result = sync( made );
			expect( result ).toEqual( { cursor: 'cursor-next', gone: { 'p-1': true, 'p-2': false, 'p-4': true } } );
		} finally {
			await mode( 'up' );
			await queue( [] );
		}
	} );

	test( 'a gallery that is available again clears the mark of its listed photos', async () => {
		await queue( [ { cursor: 'cursor-later', galleries: { available: [ 'g-e2e-autumn' ] } } ] );

		const result = sync( made );

		expect( ( await sinces() ).at( -1 ) ).toBe( 'cursor-next' );
		expect( result ).toEqual( { cursor: 'cursor-later', gone: { 'p-1': true, 'p-2': false, 'p-4': false } } );
	} );

	test( 'resync true reads the full list again and stores the fresh cursor', async () => {
		await queue( [ { resync: true, cursor: 'cursor-fresh' } ] );
		await mode( 'unlisted' );
		try {
			const result = sync( made );
			expect( result ).toEqual( { cursor: 'cursor-fresh', gone: { 'p-1': true, 'p-2': true, 'p-4': true } } );
		} finally {
			await mode( 'up' );
		}
	} );
} );
