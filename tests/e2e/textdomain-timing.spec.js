// @ts-check
//
// Issue #228: since WordPress 6.7, translating a string of the profotograaf
// domain before `init` logs the notice "_load_textdomain_just_in_time was
// called incorrectly" on every request, and the early strings stay English.
// The stack runs with WP_DEBUG on, so the notice shows in wp-cli output and in
// wp-content/debug.log.
const { spawnSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { login } = require( './helpers' );

const COMPOSE = path.join( __dirname, 'docker-compose.yml' );
const PROJECT = process.env.E2E_COMPOSE_PROJECT ? [ '-p', process.env.E2E_COMPOSE_PROJECT ] : [];
const NOTICE = '_load_textdomain_just_in_time';

/**
 * Runs wp-cli in the WordPress stack started by up.sh and returns stdout and
 * stderr together, because PHP notices can land on either.
 *
 * @param {string[]} args
 */
function wp( ...args ) {
	const result = spawnSync( 'docker', [ 'compose', ...PROJECT, '-f', COMPOSE, 'run', '--rm', '-T', 'cli', 'wp', ...args ], {
		encoding: 'utf8',
		timeout: 120_000,
	} );
	if ( 0 !== result.status ) {
		throw new Error( `wp ${ args.join( ' ' ) } failed: ${ result.stderr }` );
	}
	return { stdout: result.stdout, all: `${ result.stdout }\n${ result.stderr }` };
}

test.describe( 'Translations load on init', () => {
	test( 'a wp-cli command prints no early translation notice', () => {
		const output = wp( 'eval', 'echo "ok";' ).all;
		expect( output ).toContain( 'ok' );
		expect( output ).not.toContain( NOTICE );
	} );

	test( 'the lead bridges still listen once WordPress has loaded', () => {
		// "Collect enquiries" is on by default. The bridges now register on init.
		const hooks = JSON.parse(
			wp( 'eval', 'echo wp_json_encode( array( has_action( "wpcf7_mail_sent" ), has_action( "wpforms_process_complete" ), has_action( "gform_after_submission" ) ) );' ).stdout.trim()
		);
		expect( hooks ).toEqual( [ true, true, true ] );
	} );

	test( 'site and admin requests log no early translation notice', async ( { page } ) => {
		const size = Number( wp( 'eval', 'echo (int) @filesize( WP_CONTENT_DIR . "/debug.log" );' ).stdout.trim() );

		await page.goto( '/' );
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf' );
		await page.goto( '/wp-admin/upload.php' );

		const log = wp( 'eval', `echo (string) @file_get_contents( WP_CONTENT_DIR . "/debug.log", false, null, ${ size } );` ).stdout;
		expect( log ).not.toContain( NOTICE );
	} );
} );
