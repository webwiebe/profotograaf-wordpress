import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { bump, integrityOf, repinLockfile, repinPackageJson, versionOf } from './embed-bump.mjs';

const OLD = 'profotograaf-embed-0.3.0.tgz';
const NEW = 'profotograaf-embed-0.4.0.tgz';

test( 'only a released tarball name gives a version', () => {
	assert.equal( versionOf( NEW ), '0.4.0' );
	assert.throws( () => versionOf( 'profotograaf-embed-0.4.tgz' ) );
	assert.throws( () => versionOf( '../profotograaf-embed-0.4.0.tgz.evil' ) );
} );

test( 'package.json moves its file: pin and fails when there is none', () => {
	const before = `{ "dependencies": { "@profotograaf/embed": "file:embed-package/${ OLD }" } }`;
	assert.match( repinPackageJson( before, NEW ), /file:embed-package\/profotograaf-embed-0\.4\.0\.tgz/ );
	assert.throws( () => repinPackageJson( '{ "dependencies": {} }', NEW ) );
} );

test( 'the lockfile moves every tarball line and the integrity', () => {
	const lock = [
		`      specifier: file:embed-package/${ OLD }`,
		`  '@profotograaf/embed@file:embed-package/${ OLD }':`,
		'    resolution: {integrity: sha512-OLD==, tarball: file:embed-package/' + OLD + '}',
		'    version: 0.3.0',
	].join( '\n' );
	const after = repinLockfile( lock, NEW, 'sha512-NEW==' );
	assert.match( after, /tarball: [^\n]*\n\s+version: 0\.4\.0$/, 'the version line did not move to the new release' );
	assert.ok( ! after.includes( OLD ), 'an old tarball name is left' );
	assert.match( after, /integrity: sha512-NEW==, tarball: file:embed-package\/profotograaf-embed-0\.4\.0\.tgz/ );
	assert.throws( () => repinLockfile( 'nothing', NEW, 'sha512-x' ) );
} );

test( 'a bump replaces the tarball, the pins and the fixtures, and keeps plugin-only fixtures', () => {
	const tmp = mkdtempSync( join( tmpdir(), 'embed-bump-test-' ) );
	try {
		const src = join( tmp, 'src', 'package' );
		mkdirSync( join( src, 'fixtures' ), { recursive: true } );
		writeFileSync( join( src, 'package.json' ), JSON.stringify( { name: '@profotograaf/embed', version: '0.4.0' } ) );
		writeFileSync( join( src, 'fixtures', 'new.json' ), '{"n":1}' );
		writeFileSync( join( src, 'fixtures', 'README.md' ), 'not copied' );
		const tgz = join( tmp, NEW );
		execFileSync( 'tar', [ '-czf', tgz, '-C', join( tmp, 'src' ), 'package' ] );

		const root = join( tmp, 'plugin' );
		mkdirSync( join( root, 'embed-package' ), { recursive: true } );
		mkdirSync( join( root, 'tests/fixtures/embed' ), { recursive: true } );
		writeFileSync( join( root, 'embed-package', OLD ), 'old' );
		writeFileSync( join( root, 'tests/fixtures/embed', 'gone.json' ), '{}' );
		writeFileSync( join( root, 'tests/fixtures/embed', 'unserved-options.json' ), '{"options":{}}' );
		writeFileSync( join( root, 'package.json' ), `{"dependencies":{"@profotograaf/embed":"file:embed-package/${ OLD }"}}` );
		writeFileSync( join( root, 'pnpm-lock.yaml' ), `'@profotograaf/embed@file:embed-package/${ OLD }':\n    resolution: {integrity: sha512-OLD==, tarball: file:embed-package/${ OLD }}\n` );

		assert.equal( bump( tgz, root ), '0.4.0' );
		assert.deepEqual( readdirSync( join( root, 'embed-package' ) ), [ NEW ] );
		assert.deepEqual( readdirSync( join( root, 'tests/fixtures/embed' ) ).sort(), [ 'new.json', 'unserved-options.json' ] );
		assert.match( readFileSync( join( root, 'package.json' ), 'utf8' ), new RegExp( NEW ) );
		assert.ok( readFileSync( join( root, 'pnpm-lock.yaml' ), 'utf8' ).includes( integrityOf( readFileSync( tgz ) ) ) );
	} finally {
		rmSync( tmp, { recursive: true, force: true } );
	}
} );

test( 'a tarball whose package.json disagrees with its name is refused', () => {
	const tmp = mkdtempSync( join( tmpdir(), 'embed-bump-test-' ) );
	try {
		const src = join( tmp, 'src', 'package' );
		mkdirSync( join( src, 'fixtures' ), { recursive: true } );
		writeFileSync( join( src, 'package.json' ), JSON.stringify( { name: '@profotograaf/embed', version: '0.5.0' } ) );
		const tgz = join( tmp, NEW );
		execFileSync( 'tar', [ '-czf', tgz, '-C', join( tmp, 'src' ), 'package' ] );
		assert.throws( () => bump( tgz, join( tmp, 'plugin' ) ), /0\.5\.0/ );
		assert.ok( ! existsSync( join( tmp, 'plugin' ) ) );
	} finally {
		rmSync( tmp, { recursive: true, force: true } );
	}
} );
