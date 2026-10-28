#!/usr/bin/env node
// Pins a released @profotograaf/embed tarball in the plugin.
//
//   node scripts/embed-bump.mjs path/to/profotograaf-embed-1.2.0.tgz
//
// Replaces embed-package/*.tgz, the file: line in package.json and the lockfile
// entries, and copies the package fixtures into tests/fixtures/embed. The
// workflow .github/workflows/embed-bump.yml runs it on a release dispatch. It
// does not install: run `pnpm install --frozen-lockfile` after it, which is
// also what CI does for the bump PR.
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// Fixtures only the plugin has. Keep in step with scripts/embed-package.test.mjs.
export const PLUGIN_ONLY = [ 'unserved-options.json' ];

const TARBALL = /^profotograaf-embed-(\d+\.\d+\.\d+)\.tgz$/;

/**
 * @param {string} name File name of the tarball.
 * @returns {string} The version in the name.
 */
export function versionOf( name ) {
	const match = TARBALL.exec( name );
	if ( ! match ) {
		throw new Error( `not an embed tarball name: ${ name }` );
	}
	return match[ 1 ];
}

/**
 * @param {string} json  package.json text.
 * @param {string} name  New tarball file name.
 * @returns {string} package.json text with the file: pin on the new tarball.
 */
export function repinPackageJson( json, name ) {
	const pin = /("@profotograaf\/embed":\s*")file:embed-package\/profotograaf-embed-\d+\.\d+\.\d+\.tgz(")/;
	if ( ! pin.test( json ) ) {
		throw new Error( 'package.json has no file:embed-package pin for @profotograaf/embed' );
	}
	return json.replace( pin, `$1file:embed-package/${ name }$2` );
}

/**
 * @param {string} lock       pnpm-lock.yaml text.
 * @param {string} name       New tarball file name.
 * @param {string} integrity  sha512 integrity of the new tarball.
 * @returns {string} The lockfile on the new tarball.
 */
export function repinLockfile( lock, name, integrity ) {
	const file = /embed-package\/profotograaf-embed-\d+\.\d+\.\d+\.tgz/g;
	if ( ! file.test( lock ) ) {
		throw new Error( 'pnpm-lock.yaml has no embed-package entry' );
	}
	return lock
		.replace( file, `embed-package/${ name }` )
		.replace( /(@profotograaf\/embed@file:[^\n]*\n\s+resolution: \{integrity: )sha512-[^,]+/, `$1${ integrity }` )
		.replace( /(@profotograaf\/embed@file:[^\n]*\n[^\n]*\n\s+version: )[^\n]+/, `$1${ versionOf( name ) }` );
}

/**
 * @param {Buffer} bytes Tarball content.
 * @returns {string} The integrity value pnpm records.
 */
export function integrityOf( bytes ) {
	return `sha512-${ createHash( 'sha512' ).update( bytes ).digest( 'base64' ) }`;
}

/**
 * Copies the package fixtures over the plugin's, dropping those the package no
 * longer ships, except the plugin-only ones.
 *
 * @param {string} from Package fixtures directory.
 * @param {string} to   tests/fixtures/embed.
 */
export function syncFixtures( from, to ) {
	const upstream = readdirSync( from ).filter( ( name ) => name !== 'README.md' );
	mkdirSync( to, { recursive: true } );
	for ( const name of readdirSync( to ) ) {
		if ( ! PLUGIN_ONLY.includes( name ) && ! upstream.includes( name ) ) {
			rmSync( join( to, name ) );
		}
	}
	for ( const name of upstream ) {
		copyFileSync( join( from, name ), join( to, name ) );
	}
}

/**
 * @param {string} tgz   Path to the released tarball.
 * @param {string} root  Plugin root.
 * @returns {string} The version pinned.
 */
export function bump( tgz, root ) {
	const name = basename( tgz );
	const version = versionOf( name );
	const bytes = readFileSync( tgz );

	const work = mkdtempSync( join( tmpdir(), 'embed-bump-' ) );
	try {
		execFileSync( 'tar', [ '-xzf', tgz, '-C', work, '--strip-components=1' ] );
		const inside = JSON.parse( readFileSync( join( work, 'package.json' ), 'utf8' ) );
		if ( inside.name !== '@profotograaf/embed' || inside.version !== version ) {
			throw new Error( `${ name } holds ${ inside.name }@${ inside.version }` );
		}
		syncFixtures( join( work, 'fixtures' ), join( root, 'tests/fixtures/embed' ) );
	} finally {
		rmSync( work, { recursive: true, force: true } );
	}

	const dir = join( root, 'embed-package' );
	for ( const old of readdirSync( dir ).filter( ( file ) => TARBALL.test( file ) && file !== name ) ) {
		rmSync( join( dir, old ) );
	}
	if ( resolve( tgz ) !== resolve( dir, name ) ) {
		copyFileSync( tgz, join( dir, name ) );
	}

	const pkgPath = join( root, 'package.json' );
	writeFileSync( pkgPath, repinPackageJson( readFileSync( pkgPath, 'utf8' ), name ) );
	const lockPath = join( root, 'pnpm-lock.yaml' );
	writeFileSync( lockPath, repinLockfile( readFileSync( lockPath, 'utf8' ), name, integrityOf( bytes ) ) );
	return version;
}

if ( process.argv[ 1 ] && resolve( process.argv[ 1 ] ) === fileURLToPath( import.meta.url ) ) {
	const tgz = process.argv[ 2 ];
	if ( ! tgz || ! existsSync( tgz ) ) {
		console.error( 'usage: node scripts/embed-bump.mjs <profotograaf-embed-X.Y.Z.tgz>' );
		process.exit( 2 );
	}
	const root = resolve( fileURLToPath( new URL( '..', import.meta.url ) ) );
	console.log( `pinned @profotograaf/embed ${ bump( tgz, root ) }; now run: pnpm install --frozen-lockfile` );
}
