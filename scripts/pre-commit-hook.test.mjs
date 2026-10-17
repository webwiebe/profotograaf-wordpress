import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { statSync } from 'node:fs';

const hook = new URL( '../.githooks/pre-commit', import.meta.url ).pathname;

function run( files ) {
	return spawnSync( 'sh', [ hook ], {
		encoding: 'utf8',
		env: { ...process.env, PF_HOOK_FILES: files.join( '\n' ), PF_HOOK_DRY: '1' },
	} );
}

test( 'the hook file is executable', () => {
	assert.ok( statSync( hook ).mode & 0o100 );
} );

test( 'a commit that touches no guarded file runs nothing', () => {
	const result = run( [ 'readme.txt', 'includes/class-settings.php', 'tests/unit/Settings_Test.php' ] );
	assert.equal( result.status, 0 );
	assert.equal( result.stdout, '' );
} );

for ( const file of [
	'includes/class-declarative-renderer.php',
	'includes/class-gallery-renderer.php',
	'tests/fixtures/embed/one-photo.json',
	'package.json',
	'pnpm-lock.yaml',
	'embed-package/profotograaf-embed-0.1.0.tgz',
] ) {
	test( `${ file } runs the contract lint and the parity tests`, () => {
		const result = run( [ file ] );
		assert.equal( result.status, 0 );
		assert.match( result.stdout, /node --test scripts\/embed-package\.test\.mjs/ );
		assert.match( result.stdout, /vendor\/bin\/phpunit --filter/ );
	} );
}
