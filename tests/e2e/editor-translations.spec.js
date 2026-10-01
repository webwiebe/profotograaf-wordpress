// @ts-check
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login } = require( './helpers' );

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
 * Creates a draft page with the given content and returns its id.
 *
 * @param {string} title
 * @param {string} content
 */
function createPage( title, content ) {
	return wp( 'post', 'create', '--porcelain', '--post_type=page', '--post_status=draft', `--post_title=${ title }`, `--post_content=${ content }` );
}

/**
 * Opens a page in the block editor and closes the welcome guide when it shows.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} id
 */
async function openEditor( page, id ) {
	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
	const close = page.getByRole( 'button', { name: /^(Close|Sluiten|Schließen)$/ } );
	if ( await close.first().isVisible().catch( () => false ) ) {
		await close.first().click();
	}
}

const LOCALES = [
	{
		locale: 'nl_NL',
		galleryHint: 'Kies een van je galerijen.',
		galleryTitle: 'Profotograaf-galerij',
		portalButton: 'Klantportaal',
		portalLabel: 'Portaaladres',
	},
	{
		locale: 'de_DE',
		galleryHint: 'Wähle eine deiner Galerien.',
		galleryTitle: 'Profotograaf-Galerie',
		portalButton: 'Kundenportal',
		portalLabel: 'Portaladresse',
	},
];

for ( const { locale, galleryHint, galleryTitle, portalButton, portalLabel } of LOCALES ) {
	test.describe( `Block editor in ${ locale }`, () => {
		test.beforeAll( () => {
			wp( 'user', 'update', 'admin', `--locale=${ locale }` );
		} );

		test.afterAll( () => {
			wp( 'user', 'update', 'admin', '--locale=' );
		} );

		test.beforeEach( async ( { page } ) => {
			await login( page );
		} );

		test( `the gallery block shows ${ locale } strings from the JSON translations`, async ( { page } ) => {
			const id = createPage( `Gallery editor ${ locale } E2E`, '<!-- wp:profotograaf/gallery /-->' );
			await openEditor( page, id );

			const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
			await expect( canvas.getByText( galleryHint ) ).toBeVisible();
			await expect( canvas.getByText( galleryTitle ).first() ).toBeVisible();
			await expect( canvas.getByText( 'Choose one of your galleries.' ) ).toHaveCount( 0 );
		} );

		test( `the client galleries block shows ${ locale } strings from the JSON translations`, async ( { page } ) => {
			const id = createPage( `Client galleries editor ${ locale } E2E`, '<!-- wp:profotograaf/client-galleries {"portal":"studio"} /-->' );
			await openEditor( page, id );

			const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
			await canvas.locator( '.wp-block-profotograaf-client-galleries' ).click();

			await expect( page.getByRole( 'button', { name: portalButton } ) ).toBeVisible();
			await expect( page.getByLabel( portalLabel ) ).toBeVisible();
			await expect( page.getByText( 'Portal address' ) ).toHaveCount( 0 );
		} );
	} );
}
