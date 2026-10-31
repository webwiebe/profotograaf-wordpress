// @ts-check
const { mkdirSync } = require( 'node:fs' );
const { tmpdir } = require( 'node:os' );
const { join } = require( 'node:path' );
const { defineConfig, devices } = require( '@playwright/test' );

// Chromium runs every spec. Firefox, WebKit and a phone profile run only the
// specs that check how the real embed.js lays a gallery out, and only when
// E2E_BROWSER_MATRIX is set (the WordPress 7.1 CI job sets it). A plain local
// run stays on chromium.
// The mock platform runs on loopback next to WordPress, and recent Chromium
// blocks a page's images from another loopback origin ("Permission was denied
// ... loopback address space"). The real platform is public, so production
// never meets Local Network Access. Both Chromium projects need the flag.
const CHROMIUM_LAUNCH = {
	args: [ '--disable-features=LocalNetworkAccessChecks,LocalNetworkAccessChecksWebSockets,PrivateNetworkAccessSendPreflights' ],
};
const LAYOUT_SPECS = /embed-(layout|options)\.spec\.js$/;
// macOS can deny access to ~/Library/Application Support/Firefox (EPERM), and
// Playwright's Firefox build then exits with "Could not find profile folder" for
// any profile path (#338). On macOS it gets a home of its own through
// CFFIXED_USER_HOME.
const firefoxLaunch = () => {
	if ( process.platform !== 'darwin' ) {
		return {};
	}
	const home = join( tmpdir(), 'profotograaf-e2e-firefox-home' );
	mkdirSync( home, { recursive: true } );
	return { env: { ...process.env, CFFIXED_USER_HOME: home } };
};
const matrix = process.env.E2E_BROWSER_MATRIX
	? [
			{ name: 'firefox', testMatch: LAYOUT_SPECS, use: { ...devices[ 'Desktop Firefox' ], launchOptions: firefoxLaunch() } },
			{ name: 'webkit', testMatch: LAYOUT_SPECS, use: { ...devices[ 'Desktop Safari' ] } },
			{ name: 'mobile-chrome', testMatch: LAYOUT_SPECS, use: { ...devices[ 'Pixel 7' ], launchOptions: CHROMIUM_LAUNCH } },
	  ]
	: [];

// The wordpress.org screenshot capture is no test. `make wporg-screenshots` sets
// WPORG_SCREENSHOTS and runs only that spec; a plain run skips it.
const SCREENSHOTS = /wporg-screenshots\.spec\.js$/;
const capture = Boolean( process.env.WPORG_SCREENSHOTS );

// E2E_BASE_URL points the run at another WordPress, such as the local dev site
// on https://localhost:8443 with its self-signed certificate. The default is
// the docker WordPress that tests/e2e/up.sh starts, which CI uses.
const baseURL = process.env.E2E_BASE_URL || `http://localhost:${ process.env.WP_PORT || 8080 }`;

module.exports = defineConfig( {
	testDir: 'tests/e2e',
	testMatch: capture ? SCREENSHOTS : '**/*.spec.js',
	testIgnore: capture ? [] : [ SCREENSHOTS ],
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	// The JSON result names the failed spec files for the BugBarn report.
	reporter: process.env.CI
		? [ [ 'list' ], [ 'html', { open: 'never' } ], [ 'json', { outputFile: 'test-results/results.json' } ] ]
		: 'list',
	expect: {
		toHaveScreenshot: { animations: 'disabled', caret: 'hide', maxDiffPixelRatio: 0.02 },
	},
	use: {
		baseURL,
		ignoreHTTPSErrors: baseURL.startsWith( 'https:' ),
		trace: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				launchOptions: CHROMIUM_LAUNCH,
			},
		},
		...matrix,
	],
} );
