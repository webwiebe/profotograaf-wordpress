import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const script = new URL( './install-hooks.sh', import.meta.url ).pathname;
// The script skips the file inside a container, so this test has nothing to check there.
const inContainer = existsSync( '/.dockerenv' );

function checkout( t, { git = true } = {} ) {
	const dir = mkdtempSync( join( tmpdir(), 'pf-install-hooks-' ) );
	t.after( () => rmSync( dir, { recursive: true, force: true } ) );
	mkdirSync( join( dir, 'tests/e2e' ), { recursive: true } );
	if ( git ) {
		spawnSync( 'git', [ 'init', '-q' ], { cwd: dir } );
	}
	return dir;
}

function install( cwd ) {
	return spawnSync( 'sh', [ script ], { cwd, encoding: 'utf8' } );
}

test( 'each checkout gets its own compose project name', { skip: inContainer }, ( t ) => {
	const [ first, second ] = [ checkout( t ), checkout( t ) ];
	assert.equal( install( first ).status, 0 );
	assert.equal( install( second ).status, 0 );

	const name = /^COMPOSE_PROJECT_NAME=profotograaf-e2e-\d+\n$/;
	const [ a, b ] = [ first, second ].map( ( dir ) => readFileSync( join( dir, 'tests/e2e/.env' ), 'utf8' ) );
	assert.match( a, name );
	assert.match( b, name );
	assert.notEqual( a, b );
} );

test( 'a second install keeps the file that is there', { skip: inContainer }, ( t ) => {
	const dir = checkout( t );
	writeFileSync( join( dir, 'tests/e2e/.env' ), 'COMPOSE_PROJECT_NAME=mine\n' );
	assert.equal( install( dir ).status, 0 );
	assert.equal( readFileSync( join( dir, 'tests/e2e/.env' ), 'utf8' ), 'COMPOSE_PROJECT_NAME=mine\n' );
} );

test( 'outside a git checkout it writes nothing', ( t ) => {
	const dir = checkout( t, { git: false } );
	assert.equal( install( dir ).status, 0 );
	assert.equal( existsSync( join( dir, 'tests/e2e/.env' ) ), false );
} );
