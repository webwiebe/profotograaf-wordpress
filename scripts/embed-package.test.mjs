import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';

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
