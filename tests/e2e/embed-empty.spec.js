// @ts-check
// A gallery with nothing the embed can show (tests/e2e/mock-platform.mjs):
// g-e2e-empty has no photos, g-e2e-png holds one PNG photo that the public
// payload leaves out (wiebe-xyz/professionals#2330). Visitors get a collapsed
// block, never the failed look. A script that does not load keeps the failed
// state with a visible fallback link.
const { test, expect } = require( '@playwright/test' );
const { wp, publishPost, shortcode, openEmbed } = require( './embed-helpers' );

const INDEX_OPTION = 'profotograaf_gallery_index';
const host = ( /** @type {import('@playwright/test').Page} */ page, /** @type {string} */ id ) =>
	page.locator( `div[data-profotograaf-gallery="${ id }"]` ).first();

/**
 * Height of the gallery block in pixels.
 *
 * @param {import('@playwright/test').Locator} locator
 */
const heightOf = async ( locator ) => ( await locator.boundingBox() )?.height ?? 0;

/**
 * Whether the inner box lies within the outer one, to a pixel.
 *
 * @param {import('@playwright/test').Locator} outer
 * @param {import('@playwright/test').Locator} inner
 */
async function insideOf( outer, inner ) {
	const [ box, small ] = await Promise.all( [ outer.boundingBox(), inner.boundingBox() ] );
	if ( ! box || ! small ) {
		return false;
	}
	return small.x >= box.x - 1 && small.x + small.width <= box.x + box.width + 1;
}

test.describe( 'a gallery with nothing to show', () => {
	/** @type {Record<string,string>} */
	const urls = {};

	test.beforeAll( () => {
		const url = ( /** @type {string} */ id ) => `http://mock-platform:8090/share/g/${ id }`;
		// Merged into the index, so the entries other specs rely on stay. The PNG
		// gallery is counted by the list, so only the script can tell.
		wp(
			'eval',
			`$i = get_option( '${ INDEX_OPTION }', array() ); $i = is_array( $i ) ? $i : array(); ` +
				`$i['g-e2e-empty'] = array( 'title' => 'Empty', 'url' => '${ url( 'g-e2e-empty' ) }', 'count' => 0 ); ` +
				`$i['g-e2e-png'] = array( 'title' => 'PNG only', 'url' => '${ url( 'g-e2e-png' ) }', 'count' => 1 ); ` +
				`update_option( '${ INDEX_OPTION }', $i, false );`
		);
		for ( const id of [ 'g-e2e-empty', 'g-e2e-png' ] ) {
			urls[ id ] = publishPost( `Nothing to show ${ id }`, shortcode( { id, url: url( id ), title: id } ) );
		}
		urls.failed = publishPost( 'Script blocked', shortcode() );
	} );

	test.afterAll( () => {
		wp(
			'eval',
			`$i = get_option( '${ INDEX_OPTION }', array() ); unset( $i['g-e2e-empty'], $i['g-e2e-png'] ); update_option( '${ INDEX_OPTION }', $i, false );`
		);
	} );

	test( 'a gallery listed with no photos is collapsed from the first byte, without a link or the failed look', async ( { browser } ) => {
		const { context, page, problems } = await openEmbed( browser, urls[ 'g-e2e-empty' ] );
		const empty = host( page, 'g-e2e-empty' );

		await expect( empty ).toHaveAttribute( 'data-pf-empty', '' );
		await expect( empty ).not.toHaveAttribute( 'data-profotograaf-failed' );
		await expect( empty.locator( 'a' ) ).toHaveCount( 0 );
		await expect( empty.locator( 'p[data-pf-hint]' ) ).toHaveCount( 0 );
		expect( await heightOf( empty ) ).toBeLessThan( 4 );
		expect( problems ).toEqual( [] );
		await context.close();
	} );

	test( 'a gallery whose only photo the embed drops is collapsed once embed.js has answered', async ( { browser } ) => {
		const { context, page, problems } = await openEmbed( browser, urls[ 'g-e2e-png' ] );
		const png = host( page, 'g-e2e-png' );

		await expect( png ).toHaveAttribute( 'data-pf-empty', '' );
		await expect( png ).not.toHaveAttribute( 'data-profotograaf-failed' );
		await expect( png.locator( 'a' ) ).toBeHidden();
		expect( await png.evaluate( ( el ) => el.shadowRoot ) ).toBeNull();
		expect( await heightOf( png ) ).toBeLessThan( 4 );
		expect( problems ).toEqual( [] );
		await context.close();
	} );

	test( 'a script that does not load keeps the failed state with the fallback link inside the block', async ( { browser } ) => {
		const { context, page } = await openEmbed( browser, urls.failed, { width: 390, height: 800 }, ( ctx ) =>
			ctx.route( /\/share\/embed\/embed(\.[a-f0-9]{12})?\.js$/, ( route ) => route.abort() )
		);
		const failed = host( page, 'g-e2e' );

		await expect( failed ).toHaveAttribute( 'data-profotograaf-failed', '' );
		await expect( failed ).not.toHaveAttribute( 'data-pf-empty' );
		const link = failed.locator( 'a' );
		await expect( link ).toBeVisible();
		expect( await insideOf( failed, link ) ).toBe( true );
		await context.close();
	} );
} );
