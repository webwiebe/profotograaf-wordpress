// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

// Chromium runs every spec. Firefox, WebKit and a phone profile run only the
// specs that check how the real embed.js lays a gallery out, and only when
// E2E_BROWSER_MATRIX is set (the WordPress 7.1 CI job sets it). A plain local
// run stays on chromium.
const LAYOUT_SPECS = /embed-(layout|options)\.spec\.js$/;
const matrix = process.env.E2E_BROWSER_MATRIX
	? [
			{ name: 'firefox', testMatch: LAYOUT_SPECS, use: { ...devices[ 'Desktop Firefox' ] } },
			{ name: 'webkit', testMatch: LAYOUT_SPECS, use: { ...devices[ 'Desktop Safari' ] } },
			{ name: 'mobile-chrome', testMatch: LAYOUT_SPECS, use: { ...devices[ 'Pixel 7' ] } },
	  ]
	: [];

// The wordpress.org screenshot capture is no test. `make wporg-screenshots` sets
// WPORG_SCREENSHOTS and runs only that spec; a plain run skips it.
const SCREENSHOTS = /wporg-screenshots\.spec\.js$/;
const capture = Boolean( process.env.WPORG_SCREENSHOTS );

module.exports = defineConfig( {
	testDir: 'tests/e2e',
	testMatch: capture ? SCREENSHOTS : '**/*.spec.js',
	testIgnore: capture ? [] : [ SCREENSHOTS ],
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'list' ], [ 'html', { open: 'never' } ] ] : 'list',
	expect: {
		toHaveScreenshot: { animations: 'disabled', caret: 'hide', maxDiffPixelRatio: 0.02 },
	},
	use: {
		baseURL: `http://localhost:${ process.env.WP_PORT || 8080 }`,
		trace: 'retain-on-failure',
	},
	projects: [ { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } }, ...matrix ],
} );
