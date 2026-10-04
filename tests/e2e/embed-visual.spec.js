// @ts-check
// Pixel snapshots of the gallery element the real embed.js draws, with the
// deterministic SVG photos of the mock platform. Fonts and anti-aliasing differ
// per operating system, so the baselines in embed-visual.spec.js-snapshots/ are
// the Linux ones from CI, and the spec runs only where E2E_VISUAL is set (the
// WordPress 7.1 job and the snapshot update workflow). The Linux check also
// keeps a macOS run from writing baselines CI would reject.
//
// Update the baselines with the "Update E2E snapshots" workflow, see
// CONTRIBUTING.md.
const { test, expect } = require( '@playwright/test' );
const { publishPost, shortcode, openEmbed, expectTiles } = require( './embed-helpers' );

const PER_PAGE = 16;
const SIZES = { mobile: 390, desktop: 1440 };

test.describe( 'gallery snapshots with the real script', () => {
	test.skip( ! process.env.E2E_VISUAL, 'E2E_VISUAL is not set' );
	test.skip( process.platform !== 'linux', 'baselines are Linux only' );
	test.skip( ( { browserName } ) => browserName !== 'chromium', 'baselines are chromium only' );

	/** @type {Record<string,string>} */
	const urls = {};

	test.beforeAll( () => {
		for ( const layout of [ 'masonry', 'grid' ] ) {
			urls[ layout ] = publishPost( `Visual ${ layout }`, shortcode( { layout, per_page: String( PER_PAGE ) } ) );
		}
	} );

	for ( const layout of [ 'masonry', 'grid' ] ) {
		for ( const [ size, width ] of Object.entries( SIZES ) ) {
			test( `${ layout } at the ${ size } width`, async ( { browser } ) => {
				const { context, host } = await openEmbed( browser, urls[ layout ], { width, height: 900 } );
				await expectTiles( host, PER_PAGE );
				// Every photo loaded and decoded, so a late image cannot change the shot.
				// Lazy images below the fold never load by themselves, and decode()
				// would wait for them for ever.
				await host.evaluate( ( el ) =>
					Promise.all(
						[ ...el.shadowRoot.querySelectorAll( 'img' ) ].map( ( img ) => {
							img.loading = 'eager';
							return img.decode();
						} )
					)
				);
				await expect( host ).toHaveScreenshot( `${ layout }-${ size }.png` );
				await context.close();
			} );
		}
	}
} );
