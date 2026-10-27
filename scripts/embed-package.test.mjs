import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { lintMarkup, missingFixtures } from './embed-drift.mjs';

// The plugin's fixtures and server markup follow the @profotograaf/embed package.
// Fixtures that only the plugin has are listed here.
const PLUGIN_ONLY = [ 'unserved-options.json' ];

const pkg = new URL( '../node_modules/@profotograaf/embed/', import.meta.url );
const fixtures = new URL( '../tests/fixtures/embed/', import.meta.url );
const contract = JSON.parse( readFileSync( new URL( 'contract/markup.json', pkg ), 'utf8' ) );

test( 'the package is installed with its script', () => {
	assert.ok( existsSync( new URL( 'dist/embed.js', pkg ) ), 'run pnpm install' );
} );

test( 'every plugin fixture equals the package fixture of the same name', () => {
	for ( const name of readdirSync( fixtures ) ) {
		if ( PLUGIN_ONLY.includes( name ) ) {
			continue;
		}
		const upstream = new URL( `fixtures/${ name }`, pkg );
		assert.ok( existsSync( upstream ), `${ name } is not in the package: list it in PLUGIN_ONLY or remove it` );
		assert.equal( readFileSync( new URL( name, fixtures ), 'utf8' ), readFileSync( upstream, 'utf8' ), `${ name } differs from the package` );
	}
} );

test( 'every package fixture is in the plugin, so a new case cannot go unchecked', () => {
	const held = new Set( readdirSync( fixtures ) );
	const missing = readdirSync( new URL( 'fixtures/', pkg ) ).filter( ( name ) => name !== 'README.md' && ! held.has( name ) );
	assert.deepEqual( missing, [], 'copy these from node_modules/@profotograaf/embed/fixtures to tests/fixtures/embed' );
} );

test( 'the renderer writes only data attributes the contract lists', () => {
	const source = readFileSync( new URL( '../includes/class-declarative-renderer.php', import.meta.url ), 'utf8' );
	const listed = new Set( Object.keys( contract.host.attributes ) );
	const used = new Set( source.match( /data-[a-z][a-z0-9-]*/g ) ?? [] );
	// Attributes the plugin owns and the embed does not read.
	const own = new Set( [ 'data-pf-ready', 'data-pf-empty', 'data-pf-state', 'data-profotograaf-failed' ] );
	const unknown = [ ...used ].filter( ( name ) => ! listed.has( name ) && ! own.has( name ) && ! name.endsWith( '-' ) );
	assert.deepEqual( unknown, [], 'data attributes missing from contract/markup.json' );
} );

test( 'the script tag writes only data attributes the contract lists, and sets data-platform', () => {
	const source = readFileSync( new URL( '../includes/class-embed-script.php', import.meta.url ), 'utf8' );
	const listed = new Set( Object.keys( contract.script.attributes ) );
	const used = new Set( source.split( '\n' ).filter( ( line ) => line.includes( '<script' ) ).flatMap( ( line ) => line.match( /data-[a-z][a-z0-9-]*/g ) ?? [] ) );
	assert.ok( used.has( 'data-platform' ), 'the script tag sets data-platform' );
	assert.deepEqual( [ ...used ].filter( ( name ) => ! listed.has( name ) ), [], 'script attributes missing from contract/markup.json' );
} );

// The drift checks. Each runs on the real sources and on a broken copy that must fail.
const renderers = ( dir = new URL( '../includes/', import.meta.url ) ) =>
	Object.fromEntries( readdirSync( dir ).filter( ( name ) => /^class-(declarative-.*|gallery-renderer)\.php$/.test( name ) ).map( ( name ) => [ name, readFileSync( new URL( name, dir ), 'utf8' ) ] ) );

test( 'every fixture the package serves from the server is in the plugin', () => {
	const upstream = readdirSync( new URL( 'fixtures/', pkg ) );
	assert.deepEqual( missingFixtures( upstream, readdirSync( fixtures ) ), [], 'copy the package fixtures into tests/fixtures/embed (scripts/refresh-embed-fixture.sh)' );
} );

test( 'a package fixture the plugin lacks is reported, and so is a server golden without its input', () => {
	assert.deepEqual( missingFixtures( [ 'a.json', 'a.server.html', 'a.expected.html', 'b.json', 'README.md' ], [ 'a.json', 'a.server.html' ] ), [ 'b.json' ] );
	assert.deepEqual( missingFixtures( [ 'a.json', 'a.server.html' ], [ 'a.json' ] ), [ 'a.server.html' ] );
} );

test( 'the renderers emit only classes, ARIA attributes and roles the contract lists, and the host marker it requires', () => {
	assert.deepEqual( lintMarkup( renderers(), contract ), [] );
} );

test( 'the lint fails on a class outside the contract', () => {
	const files = renderers();
	files[ 'class-declarative-renderer.php' ] = files[ 'class-declarative-renderer.php' ].replace( '<div class="photos">', '<div class="photos sneaky">' );
	assert.deepEqual( lintMarkup( files, contract ), [ 'class-declarative-renderer.php: class "sneaky" is not in contract/markup.json' ] );
} );

test( 'the lint fails on a class built in a variable that the contract does not list', () => {
	const files = renderers();
	files[ 'class-declarative-renderer.php' ] = files[ 'class-declarative-renderer.php' ].replace( "'badge prominent'", "'badge loud'" );
	assert.deepEqual( lintMarkup( files, contract ), [ 'class-declarative-renderer.php: class "loud" is not in contract/markup.json' ] );
} );

test( 'the lint fails on an ARIA attribute or role outside the contract', () => {
	const files = renderers();
	files[ 'class-declarative-renderer.php' ] = files[ 'class-declarative-renderer.php' ].replace( '<div class="photos">', '<div class="photos" aria-pressed="false" role="menu">' );
	assert.deepEqual( lintMarkup( files, contract ), [ 'class-declarative-renderer.php: ARIA attribute "aria-pressed" is not in contract/markup.json', 'class-declarative-renderer.php: role "menu" is not in contract/markup.json' ] );
} );

test( 'the lint fails when the host element loses a required attribute', () => {
	const files = renderers();
	files[ 'class-gallery-renderer.php' ] = files[ 'class-gallery-renderer.php' ].replaceAll( 'data-profotograaf-gallery', 'data-gallery-id' );
	assert.deepEqual( lintMarkup( files, contract ), [ 'class-gallery-renderer.php: the host element does not write data-profotograaf-gallery, which the contract requires' ] );
} );
