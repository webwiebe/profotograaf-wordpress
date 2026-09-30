// @ts-check
const MOCK = `http://localhost:${ process.env.MOCK_PORT || 8090 }`;

/**
 * Logs in to wp-admin as the E2E administrator.
 *
 * @param {import('@playwright/test').Page} page
 */
async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

/**
 * Talks to the mock platform's control endpoints.
 *
 * @param {string} path
 * @param {'GET'|'POST'} method
 */
async function mock( path, method = 'POST' ) {
	const response = await fetch( `${ MOCK }${ path }`, { method } );
	return response.json();
}

module.exports = { login, mock, MOCK };
