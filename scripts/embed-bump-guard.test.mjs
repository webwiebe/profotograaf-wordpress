import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { outsideBump, pathsOf } from './embed-bump-guard.mjs';

const SCRIPT = fileURLToPath( new URL( './embed-bump-guard.mjs', import.meta.url ) );
const BUMP = [
	'embed-package/profotograaf-embed-0.7.0.tgz',
	'package.json',
	'pnpm-lock.yaml',
	'tests/fixtures/embed/masonry.expected.html',
];

const run = ( input ) => spawnSync( process.execPath, [ SCRIPT ], { input, encoding: 'utf8' } );

test( 'the files a bump writes pass', () => {
	assert.deepEqual( outsideBump( BUMP ), [] );
} );

test( 'any other file is reported, with a path trick or a second tarball name', () => {
	const extra = [ 'includes/class-embed-script.php', 'embed-package/evil.tgz', 'tests/fixtures/embedx/a.html', '.github/workflows/ci.yml' ];
	assert.deepEqual( outsideBump( [ ...BUMP, ...extra ] ), extra );
} );

test( 'paths are read one per line without blanks', () => {
	assert.deepEqual( pathsOf( 'a\n\n b \n' ), [ 'a', 'b' ] );
} );

test( 'the command exits 0 for a bump and 1 for anything else', () => {
	assert.equal( run( BUMP.join( '\n' ) ).status, 0 );
	const mixed = run( [ ...BUMP, 'readme.txt' ].join( '\n' ) );
	assert.equal( mixed.status, 1 );
	assert.match( mixed.stderr, /readme\.txt/ );
	assert.equal( run( '' ).status, 1 );
} );
