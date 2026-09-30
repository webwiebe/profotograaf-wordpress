// @ts-check
const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );

const compose = [
	'compose',
	'-f',
	path.join( __dirname, 'docker-compose.yml' ),
	'--profile',
	'tools',
];

/**
 * Creates a published page with the given content through WP-CLI and returns its URL.
 *
 * @param {string} title
 * @param {string} content
 */
function createPage( title, content ) {
	const output = execFileSync(
		'docker',
		[ ...compose, 'run', '--rm', '-T', 'cli', 'wp', 'post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${ title }`, `--post_content=${ content }`, '--porcelain' ],
		{ encoding: 'utf8' }
	);
	return `/?page_id=${ output.trim() }`;
}

test.describe( 'Client galleries block, logged out', () => {
	test( 'links to the client portal without being connected', async ( { page } ) => {
		const url = createPage( 'Client galleries E2E', '<!-- wp:profotograaf/client-galleries {"portal":"studio","heading":"Find your gallery"} /-->' );

		await page.goto( url );

		const block = page.locator( '.wp-block-profotograaf-client-galleries' );
		await expect( block ).toBeVisible();
		await expect( block.getByRole( 'heading', { name: 'Find your gallery' } ) ).toBeVisible();
		await expect( block.getByRole( 'link', { name: 'Open my gallery' } ) ).toHaveAttribute( 'href', /\/studio\/client$/ );
	} );

	test( 'a block without an address shows nothing to visitors', async ( { page } ) => {
		const url = createPage( 'Client galleries empty E2E', '<!-- wp:profotograaf/client-galleries /-->' );

		await page.goto( url );

		await expect( page.locator( '.wp-block-profotograaf-client-galleries' ) ).toHaveCount( 0 );
		await expect( page.getByText( 'Only editors see this note' ) ).toHaveCount( 0 );
	} );
} );
