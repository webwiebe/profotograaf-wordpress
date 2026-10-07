// @ts-check
const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { login, mock } = require( './helpers' );

const root = path.resolve( __dirname, '../..' );
const compose = path.join( root, 'tests/e2e/docker-compose.yml' );
const fixtures = path.join( root, 'tests/e2e/fixtures' );

/**
 * Runs WP-CLI in the E2E WordPress container.
 *
 * @param {...string} args
 * @return {string} Standard output.
 */
function wp( ...args ) {
	return execFileSync(
		'docker',
		[ 'compose', '-f', compose, 'run', '--rm', '-T', '-v', `${ fixtures }:/fixtures:ro`, 'cli', 'wp', ...args ],
		{ encoding: 'utf8', timeout: 180_000 }
	);
}

/**
 * Connects the site through the pairing flow against the mock platform.
 *
 * @param {import('@playwright/test').Page} page
 */
async function connect( page ) {
	await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
	if ( await page.getByRole( 'button', { name: 'Disconnect' } ).isVisible() ) {
		return;
	}
	await page.getByRole( 'button', { name: 'Connect to Profotograaf' } ).click();
	await expect( page.locator( '.profotograaf-code' ) ).toBeVisible();
	await mock( '/__approve' );
	await expect( page.getByText( 'Connected to Profotograaf.' ) ).toBeVisible( { timeout: 20_000 } );
}

/**
 * Fills and sends the Contact Form 7 form.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} url
 */
async function submitForm( page, url ) {
	await page.goto( url );
	await page.getByLabel( 'Your name' ).fill( 'Anna de Vries' );
	await page.getByLabel( 'Your email' ).fill( 'anna@example.com' );
	await page.getByLabel( 'Your phone' ).fill( '0612345678' );
	await page.getByLabel( 'Wedding date' ).fill( '2027-06-12' );
	await page.getByLabel( 'Location' ).fill( 'Utrecht' );
	await page.getByLabel( 'Your message' ).fill( 'We would love photos of our wedding.' );
	await page.getByRole( 'button', { name: 'Send' } ).click();
	await expect( page.locator( 'form.wpcf7-form' ) ).toHaveClass( /\bsent\b/, { timeout: 30_000 } );
}

test.describe.serial( 'Lead bridge: Contact Form 7', () => {
	/** @type {{form_id:number,page:string}} */
	let fixture;

	test.beforeAll( async () => {
		// Installing Contact Form 7 downloads it. 6.2 requires WordPress 7.1, so the
		// version is pinned to the last release that installs on 6.9 and 7.0.
		test.setTimeout( 300_000 );
		wp( 'plugin', 'install', 'contact-form-7', '--version=6.1.7', '--activate' );
		const out = wp( 'eval-file', '/fixtures/cf7-setup.php' );
		fixture = JSON.parse( out.slice( out.indexOf( '{' ) ) );
	} );

	test.afterAll( async () => {
		wp( 'option', 'delete', 'profotograaf_connection' );
		wp( 'option', 'delete', 'profotograaf_pairing' );
		wp( 'option', 'delete', 'profotograaf_leads' );
		wp( 'plugin', 'deactivate', 'contact-form-7' );
	} );

	test.beforeEach( async () => {
		await mock( '/__reset' );
	} );

	test( 'a form that is off sends nothing', async ( { page } ) => {
		await login( page );
		await connect( page );

		await submitForm( page, fixture.page );
		wp( 'cron', 'event', 'run', '--due-now' );

		const state = await mock( '/__state', 'GET' );
		expect( state.leads ).toHaveLength( 0 );
	} );

	test( 'a switched on form posts the lead to the platform after the submission', async ( { page } ) => {
		await login( page );
		await connect( page );

		await page.goto( '/wp-admin/options-general.php?page=profotograaf-leads' );
		const form = page.getByRole( 'group', { name: /Wedding inquiry/ } );
		await form.getByLabel( 'Send submissions of this form to Profotograaf' ).check();
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.getByText( 'Changes saved.' ) ).toBeVisible();
		await expect( form.getByLabel( 'Send submissions of this form to Profotograaf' ) ).toBeChecked();

		await submitForm( page, fixture.page );

		// The form answered without waiting: nothing has reached the platform yet.
		expect( ( await mock( '/__state', 'GET' ) ).leads ).toHaveLength( 0 );

		// WP-Cron delivers it.
		wp( 'cron', 'event', 'run', '--due-now' );
		await expect.poll( async () => ( await mock( '/__state', 'GET' ) ).leads.length ).toBe( 1 );

		const [ lead ] = ( await mock( '/__state', 'GET' ) ).leads;
		expect( lead ).toMatchObject( {
			name: 'Anna de Vries',
			email: 'anna@example.com',
			phone: '0612345678',
			event_date: '2027-06-12',
			message: 'We would love photos of our wedding.',
			source: 'wordpress',
			source_form: 'Contact Form 7: Wedding inquiry',
			extra_fields: [ { label: 'Location', value: 'Utrecht' } ],
		} );
		expect( lead.page_url ).toBe( fixture.page );

		// Delivered once: a second cron run has nothing left to send.
		wp( 'cron', 'event', 'run', '--due-now' );
		expect( ( await mock( '/__state', 'GET' ) ).leads ).toHaveLength( 1 );
	} );

	test( 'the enquiries page needs an administrator', async ( { page } ) => {
		const response = await page.request.post( '/wp-admin/admin-post.php', {
			form: { action: 'profotograaf_leads_save' },
		} );

		expect( response.status() ).toBeGreaterThanOrEqual( 400 );
	} );
} );
