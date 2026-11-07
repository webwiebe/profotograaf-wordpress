import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { chmodSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const script = new URL( '../tests/e2e/sweep-orphans.sh', import.meta.url ).pathname;

// A docker stand-in: `ps -a --filter label=...` prints the given project/state
// lines, every other call is logged so the test can read what was removed.
function sweep( t, states, { own = '', psFails = false } = {} ) {
	const dir = mkdtempSync( join( tmpdir(), 'pf-sweep-' ) );
	t.after( () => rmSync( dir, { recursive: true, force: true } ) );
	const log = join( dir, 'calls' );
	writeFileSync( log, '' );
	writeFileSync( join( dir, 'states' ), states.map( ( s ) => s + '\n' ).join( '' ) );
	writeFileSync(
		join( dir, 'docker' ),
		`#!/usr/bin/env bash
echo "$*" >> "${ log }"
case "$*" in
  "ps -a --filter label=com.docker.compose.project --format "*) ${ psFails ? 'exit 1' : `cat "${ dir }/states"` } ;;
  "ps -aq --filter label=com.docker.compose.project="*) echo "id-\${4#label=com.docker.compose.project=}" ;;
  "volume ls -q --filter label=com.docker.compose.project="*) echo "vol-\${5#label=com.docker.compose.project=}" ;;
esac
`
	);
	chmodSync( join( dir, 'docker' ), 0o755 );
	const res = spawnSync( 'bash', [ script ], {
		encoding: 'utf8',
		env: { ...process.env, PATH: `${ dir }:${ process.env.PATH }`, COMPOSE_PROJECT_NAME: own },
	} );
	const removed = readFileSync( log, 'utf8' )
		.split( '\n' )
		.filter( ( l ) => l.startsWith( 'rm ' ) || l.startsWith( 'volume rm ' ) );
	return { status: res.status, removed };
}

const dead = 'profotograaf-e2e-42195468399-1-e2e-13189';

test( 'a CI stack with every container stopped loses its containers and volumes', ( t ) => {
	const { status, removed } = sweep( t, [ `${ dead } exited`, `${ dead } exited`, `${ dead } dead` ] );
	assert.equal( status, 0 );
	assert.deepEqual( removed, [ `rm -f -v id-${ dead }`, `volume rm vol-${ dead }` ] );
} );

test( 'a CI stack with a running container is left alone', ( t ) => {
	const live = 'profotograaf-e2e-42200000000-1-e2e-777';
	const { removed } = sweep( t, [ `${ live } exited`, `${ live } running` ] );
	assert.deepEqual( removed, [] );
} );

test( 'a stack still being created is left alone', ( t ) => {
	const fresh = 'profotograaf-e2e-42200000001-1-e2e-778';
	assert.deepEqual( sweep( t, [ `${ fresh } created` ] ).removed, [] );
} );

test( "the job's own project is left alone", ( t ) => {
	assert.deepEqual( sweep( t, [ `${ dead } exited` ], { own: dead } ).removed, [] );
} );

test( 'local stacks and other projects are never touched', ( t ) => {
	const { removed } = sweep( t, [
		'profotograaf-e2e-3141592653 exited',
		'profotograaf-dev exited',
		'garage-e2e-1-1-desktop-0 exited',
	] );
	assert.deepEqual( removed, [] );
} );

test( 'an unreachable daemon does not fail the job', ( t ) => {
	assert.equal( sweep( t, [], { psFails: true } ).status, 0 );
} );
