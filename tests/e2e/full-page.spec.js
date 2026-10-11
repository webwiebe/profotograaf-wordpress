// @ts-check
// The full-page gallery (#194) on a published page, with the mock platform. The
// page is the "Sample: Full page" definition of tests/e2e/sample-pages.js on the
// 50-photo mock gallery g-e2e-fifty: one block per preset, a Custom block with a
// text section and a picked hero, and the gallery block on the same photos.
// It is published under its own slug, so sample-pages.spec.js (which looks for
// /sample-full-page/ on a site connected to the real platform) keeps skipping
// on the docker WordPress.
//
// E2E_SCREENSHOT_DIR collects the review screenshots, for example
// E2E_SCREENSHOT_DIR=docs/review/full-page.
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { wp, openEmbed } = require( './embed-helpers' );
const { login, mock } = require( './helpers' );
const { loadLazyImages, expectPhotos } = require( './gallery-checks' );
const { PAGES, FULL_PAGE, HERO_PICK, pageContent } = require( './sample-pages' );

test.skip( !! process.env.E2E_BASE_URL, 'needs the docker WordPress and mock platform of tests/e2e/up.sh' );

const TOTAL = 50;
const PLATFORM = 'http://mock-platform:8090';
const SAMPLE = PAGES.find( ( candidate ) => candidate.slug === 'sample-full-page' );
if ( ! SAMPLE ) {
	throw new Error( 'tests/e2e/sample-pages.js has no sample-full-page page' );
}
const PRESETS = SAMPLE.blocks.filter( ( block ) => block.block === FULL_PAGE );
const GALLERIES = {
	fifty: {
		id: 'g-e2e-fifty',
		title: 'Sample fifty',
		url: `${ PLATFORM }/share/g/sample-fifty`,
		photoIds: Array.from( { length: TOTAL }, ( _, index ) => `p-${ index + 1 }` ),
	},
};
const PHONE = { width: 390, height: 844 };
const DESKTOP = { width: 1280, height: 900 };

/**
 * Publishes a page and returns its ID and URL.
 *
 * @param {string} title
 * @param {string} content
 */
function publishPage( title, content ) {
	const id = wp( 'post', 'create', '--post_type=page', '--porcelain', '--post_status=publish', `--post_title=${ title }`, `--post_content=${ content }` );
	return { id, url: wp( 'post', 'list', `--post__in=${ id }`, '--field=url', '--post_type=page' ) };
}

/**
 * The rendered root of one sample block.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} anchor
 */
const rootOf = ( page, anchor ) => page.locator( `#${ anchor } div[data-pf-full-page]` );

/**
 * Every section of a gallery with its type, figure count, figures per row and
 * figures without a box.
 *
 * @param {import('@playwright/test').Locator} root
 */
const sectionsOf = ( root ) =>
	root.evaluate( ( el ) =>
		[ ...el.querySelectorAll( ':scope > section.pf-fp-s' ) ].map( ( section ) => {
			const figures = [ ...section.querySelectorAll( 'figure.pf-fp-photo' ) ];
			/** @type {Map<number, number>} */
			const rows = new Map();
			for ( const figure of figures ) {
				const top = Math.round( figure.getBoundingClientRect().top );
				rows.set( top, ( rows.get( top ) ?? 0 ) + 1 );
			}
			return {
				type: /** @type {HTMLElement} */ ( section ).dataset.type,
				count: figures.length,
				rows: [ ...rows.values() ],
				empty: figures.filter( ( figure ) => figure.getBoundingClientRect().width < 1 || figure.getBoundingClientRect().height < 1 ).length,
			};
		} )
	);

/**
 * Checks that every section is full for its type and that the sections hold
 * all photos once.
 *
 * @param {import('@playwright/test').Locator} root
 * @param {string} label
 */
async function expectFullSections( root, label ) {
	const sections = await sectionsOf( root );
	expect( sections.length, label ).toBeGreaterThan( 1 );
	for ( const [ at, section ] of sections.entries() ) {
		const where = `${ label } section ${ at } (${ section.type })`;
		expect( section.empty, `${ where }: figures without a box` ).toBe( 0 );
		if ( section.type === 'grid' ) {
			// Full rows only: every row as long as the first.
			expect( section.rows.every( ( length ) => length === section.rows[ 0 ] ), `${ where }: rows ${ section.rows.join( ',' ) }` ).toBe( true );
		} else if ( section.type === 'hero' ) {
			expect( section.count, where ).toBe( 1 );
		} else if ( section.type === 'pair' ) {
			expect( section.count, where ).toBe( 3 );
		} else if ( section.type === 'feature' ) {
			expect( [ 2, 3 ], where ).toContain( section.count );
		} else if ( section.type === 'text' ) {
			expect( section.count, where ).toBe( 0 );
		} else {
			expect( section.count, where ).toBeGreaterThan( 0 );
		}
	}
	expect( sections.reduce( ( sum, section ) => sum + section.count, 0 ), `${ label }: photos` ).toBe( TOTAL );
	const indexes = await root.locator( 'a.pf-fp-open[data-pf-index]' ).evaluateAll( ( links ) => links.map( ( link ) => Number( /** @type {HTMLElement} */ ( link ).dataset.pfIndex ) ) );
	expect( indexes, `${ label }: one flat list in render order` ).toEqual( Array.from( { length: TOTAL }, ( _, index ) => index ) );
}

/**
 * Where a review screenshot goes: E2E_SCREENSHOT_DIR when set, else the test output.
 *
 * @param {string} name
 */
function screenshotPath( name ) {
	const dir = process.env.E2E_SCREENSHOT_DIR;
	if ( ! dir ) {
		return test.info().outputPath( `${ name }.jpg` );
	}
	fs.mkdirSync( dir, { recursive: true } );
	return path.join( dir, `${ name }.jpg` );
}

/** @type {{id:string,url:string}} */
let sample;
/** @type {{id:string,url:string}} */
let empty;

test.beforeAll( async () => {
	await mock( '/__reset' );
	sample = publishPage( SAMPLE.title, pageContent( SAMPLE, GALLERIES ) );
	const fallback = { galleryId: 'g-e2e-empty', galleryTitle: 'Empty', galleryUrl: `${ PLATFORM }/share/g/g-e2e-empty` };
	empty = publishPage( 'Full page empty', `<!-- wp:${ FULL_PAGE } ${ JSON.stringify( fallback ) } /-->` );
} );

test.afterAll( () => {
	wp( 'post', 'delete', sample.id, empty.id, '--force' );
} );

test.describe( 'full-page gallery on the sample page', () => {
	for ( const [ name, viewport ] of Object.entries( { desktop: DESKTOP, phone: PHONE } ) ) {
		test( `every preset shows 50 photos in full sections on ${ name }`, async ( { browser } ) => {
			const { context, page, problems } = await openEmbed( browser, sample.url, viewport );
			for ( const block of PRESETS ) {
				const root = rootOf( page, block.anchor );
				await expect( root, block.anchor ).toHaveCount( 1 );
				await expectFullSections( root, block.anchor );
			}
			expect( await page.evaluate( () => document.documentElement.scrollWidth ), 'no horizontal page scroll' ).toBeLessThanOrEqual( viewport.width );
			expect( problems ).toEqual( [] );
			await context.close();
		} );
	}

	test( 'the lightbox steps from the first to the last photo across sections', async ( { browser } ) => {
		const { context, page } = await openEmbed( browser, sample.url, DESKTOP );
		const root = rootOf( page, 'full-editorial' );
		const sources = await root.locator( 'script.pf-fp-data' ).evaluate( ( script ) => JSON.parse( script.textContent || '{}' ).photos.map( ( /** @type {{src:string}} */ photo ) => photo.src ) );
		expect( sources ).toHaveLength( TOTAL );
		const first = root.locator( 'a.pf-fp-open[data-pf-index="0"]' );
		await first.click();
		const viewer = page.locator( '.pf-fp-lightbox' );
		await expect( viewer ).toBeVisible();
		await expect( viewer ).toHaveAttribute( 'role', 'dialog' );
		const image = viewer.locator( 'img.pf-fp-lb-img' );
		await expect( image ).toHaveAttribute( 'src', sources[ 0 ] );
		await expect( viewer.locator( '.pf-fp-lb-prev' ) ).toBeDisabled();
		for ( let index = 1; index < TOTAL; index++ ) {
			await page.keyboard.press( 'ArrowRight' );
			await expect( image ).toHaveAttribute( 'src', sources[ index ] );
		}
		await expect( viewer.locator( '.pf-fp-lb-next' ) ).toBeDisabled();
		// The last photo stays put.
		await page.keyboard.press( 'ArrowRight' );
		await expect( image ).toHaveAttribute( 'src', sources[ TOTAL - 1 ] );
		await expect.poll( () => image.evaluate( ( img ) => /** @type {HTMLImageElement} */ ( img ).naturalWidth ) ).toBeGreaterThan( 0 );
		await page.keyboard.press( 'Escape' );
		await expect( viewer ).toHaveCount( 0 );
		expect( await first.evaluate( ( link ) => link === document.activeElement ), 'focus is back on the photo that opened it' ).toBe( true );
		expect( await page.evaluate( () => document.body.style.overflow ) ).toBe( '' );
		await context.close();
	} );

	test( 'a picked hero photo shows once, in the hero', async ( { browser } ) => {
		const { context, page } = await openEmbed( browser, sample.url, DESKTOP );
		const root = rootOf( page, 'full-custom' );
		const picked = GALLERIES.fifty.photoIds[ HERO_PICK ];
		const sources = root.locator( `img[src*="/img/${ picked }/"]` );
		await expect( sources ).toHaveCount( 1 );
		await expect( root.locator( ':scope > section.pf-fp-hero' ).first().locator( 'img' ) ).toHaveAttribute( 'src', new RegExp( `/img/${ picked }/` ) );
		await expect( root.locator( ':scope > section.pf-fp-text h2' ).first() ).toHaveText( 'The day in pictures' );
		await expectFullSections( root, 'full-custom' );
		await context.close();
	} );

	test( 'the filmstrip buttons scroll the strip', async ( { browser } ) => {
		const { context, page } = await openEmbed( browser, sample.url, DESKTOP );
		const strip = rootOf( page, 'full-playful' ).locator( 'section.pf-fp-filmstrip' ).first();
		await strip.scrollIntoViewIfNeeded();
		const track = strip.locator( '.pf-fp-track' );
		const back = strip.locator( '.pf-fp-strip-prev' );
		const forward = strip.locator( '.pf-fp-strip-next' );
		await expect( back ).toBeHidden();
		await expect( forward ).toBeVisible();
		await forward.click();
		await expect.poll( () => track.evaluate( ( el ) => el.scrollLeft ) ).toBeGreaterThan( 0 );
		await expect( back ).toBeVisible();
		await back.click();
		await expect.poll( () => track.evaluate( ( el ) => el.scrollLeft ) ).toBe( 0 );
		await expect( back ).toBeHidden();
		await context.close();
	} );

	test( 'a gallery without photos shows the fallback link', async ( { browser } ) => {
		const { context, page } = await openEmbed( browser, empty.url, DESKTOP );
		const root = page.locator( 'div[data-pf-full-page="g-e2e-empty"]' );
		await expect( root ).toHaveCount( 1 );
		await expect( root.locator( 'figure' ) ).toHaveCount( 0 );
		await expect( root.locator( 'a[href*="/share/g/g-e2e-empty"]' ) ).toBeVisible();
		await context.close();
	} );

	for ( const viewport of [ DESKTOP, PHONE ] ) {
		test( `review screenshots at ${ viewport.width } px`, async ( { browser } ) => {
			test.slow();
			const { context, page } = await openEmbed( browser, sample.url, viewport );
			await loadLazyImages( page );
			for ( const block of PRESETS ) {
				const group = page.locator( `#${ block.anchor }` );
				const unloaded = await group.locator( 'img' ).evaluateAll( ( images ) => images.filter( ( img ) => ! /** @type {HTMLImageElement} */ ( img ).naturalWidth ).length );
				expect( unloaded, `${ block.anchor }: photos that did not load` ).toBe( 0 );
				const preset = block.anchor.replace( /^full-/, '' );
				await group.screenshot( { path: screenshotPath( `after-${ preset }-${ viewport.width }` ), type: 'jpeg', quality: 70, animations: 'disabled' } );
			}
			const before = page.locator( '#full-before[data-profotograaf-gallery]' );
			await expectPhotos( before, 2 );
			await loadLazyImages( page );
			await before.screenshot( { path: screenshotPath( `before-gallery-${ viewport.width }` ), type: 'jpeg', quality: 70, animations: 'disabled' } );
			await context.close();
		} );
	}
} );

test.describe( 'full-page gallery in the editor', () => {
	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Connect to Profotograaf' } ).click();
		await expect( page.locator( '.profotograaf-code' ) ).toBeVisible();
		await mock( '/__approve' );
		await expect( page.getByText( 'Connected to Profotograaf.' ) ).toBeVisible( { timeout: 20_000 } );
		await page.close();
	} );

	test.afterAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await page.close();
	} );

	test( 'the block picks a gallery and previews its sections, and a page offers the full-page template', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/post-new.php?post_type=page' );
		await page.waitForFunction( () => window.wp && window.wp.data && window.wp.blocks && window.wp.data.select( 'core/block-editor' ) );
		await page.evaluate( ( name ) => {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
			// A new page on a block theme opens the starter pattern modal.
			window.wp.data.dispatch( 'core/preferences' ).set( 'core', 'enableChoosePatternModal', false );
			window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( name ) );
		}, FULL_PAGE );
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const card = canvas.getByRole( 'button', { name: /Spring wedding/ } );
		await expect( card ).toBeVisible( { timeout: 20_000 } );
		// The modal can still open after the preference is set; close it when it
		// does. Escape misses it while the focus is in the editor canvas, so the
		// test clicks its Close button and waits until the overlay is gone.
		const modal = page.locator( '.components-modal__screen-overlay' );
		await expect( async () => {
			if ( await modal.isVisible() ) {
				await modal.getByRole( 'button', { name: 'Close' } ).click( { timeout: 2_000 } );
				await expect( modal ).toBeHidden( { timeout: 2_000 } );
			}
			await card.click( { timeout: 2_000 } );
		} ).toPass( { timeout: 15_000 } );
		const preview = canvas.locator( '.profotograaf-fp-preview' );
		await expect( preview ).toHaveAttribute( 'data-preset', 'editorial' );
		await expect( preview.locator( 'section.pf-fp-hero img' ).first() ).toBeVisible();
		await expect.poll( () => preview.locator( 'figure.pf-fp-photo' ).count() ).toBe( 19 );

		const templates = await page.evaluate( () =>
			window.wp.data
				.resolveSelect( 'core' )
				.getEntityRecords( 'postType', 'wp_template', { post_type: 'page', per_page: -1 } )
				.then( ( /** @type {{title:{rendered:string}}[]|null} */ list ) => ( list ?? [] ).map( ( template ) => template.title.rendered ) )
		);
		expect( templates ).toContain( 'Profotograaf full page' );
	} );
} );
