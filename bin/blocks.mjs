#!/usr/bin/env node
/**
 * Builds the blocks with @wordpress/scripts.
 *
 * Every folder blocks/<name>/ that holds a block.json is a block. wp-scripts
 * finds them by itself (its entry point scan reads block.json files below the
 * source directory) and writes build/<name>/. This wrapper only makes an empty
 * blocks/ folder a successful no-op, which wp-scripts is not, and pins the
 * source and output directories in one place.
 *
 * The interactive layer of the galleries is the readable script of the
 * @profotograaf/embed package. It is copied to build/embed/ after the blocks
 * are built, because wp-scripts empties build/ first.
 *
 * wp-scripts is only the bundler here. Type checking is `tsc` and linting is
 * oxlint, because wp-scripts' own lint (eslint + typescript-eslint) cannot load
 * TypeScript 7.
 */
import { copyFileSync, existsSync, mkdirSync, readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const blocksDir = join( root, 'blocks' );
const command = process.argv[ 2 ] || 'build';

const hasBlocks =
	existsSync( blocksDir ) &&
	readdirSync( blocksDir, { withFileTypes: true } ).some(
		( entry ) =>
			entry.isDirectory() &&
			existsSync( join( blocksDir, entry.name, 'block.json' ) )
	);

/** Copies the package's script and source map to build/embed/. */
function copyEmbed() {
	const from = join( root, 'node_modules', '@profotograaf', 'embed', 'dist' );
	const to = join( root, 'build', 'embed' );
	mkdirSync( to, { recursive: true } );
	for ( const file of [ 'embed.js', 'embed.js.map' ] ) {
		if ( ! existsSync( join( from, file ) ) ) {
			console.error( `@profotograaf/embed has no dist/${ file }: run pnpm install.` );
			process.exit( 1 );
		}
		copyFileSync( join( from, file ), join( to, file ) );
	}
}

if ( ! hasBlocks ) {
	copyEmbed();
	console.log( 'No blocks found in blocks/*/block.json, nothing to do.' );
	process.exit( 0 );
}

const args = [ command, '--webpack-src-dir=blocks', '--output-path=build' ];

const result = spawnSync(
	process.execPath,
	[ join( root, 'node_modules', '@wordpress', 'scripts', 'bin', 'wp-scripts.js' ), ...args ],
	{ cwd: root, stdio: 'inherit' }
);
if ( result.status === 0 ) {
	copyEmbed();
}
process.exit( result.status ?? 1 );
