#!/usr/bin/env node
// Guards the automatic merge of an embed bump PR.
//
//   gh pr diff <n> --name-only | node scripts/embed-bump-guard.mjs
//
// scripts/embed-bump.mjs touches only the files in ALLOWED. A bump PR that
// changes anything else (a hand edit on the bump branch, for example) is not
// merged by .github/workflows/embed-bump-merge.yml: a person reviews it. Exit
// code 1 lists the files outside the bump.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// Paths the bump writes: the tarball, the pin, the lockfile and the fixtures.
export const ALLOWED = [ /^embed-package\/profotograaf-embed-\d+\.\d+\.\d+\.tgz$/, /^package\.json$/, /^pnpm-lock\.yaml$/, /^tests\/fixtures\/embed\// ];

/**
 * @param {string[]} files Paths changed by the pull request.
 * @returns {string[]} The paths a bump does not write.
 */
export function outsideBump( files ) {
	return files.filter( ( file ) => ! ALLOWED.some( ( pattern ) => pattern.test( file ) ) );
}

/**
 * @param {string} text One path per line.
 * @returns {string[]} The non-empty lines.
 */
export function pathsOf( text ) {
	return text.split( '\n' ).map( ( line ) => line.trim() ).filter( Boolean );
}

if ( process.argv[ 1 ] === fileURLToPath( import.meta.url ) ) {
	const files = pathsOf( readFileSync( 0, 'utf8' ) );
	const outside = outsideBump( files );
	if ( files.length === 0 || outside.length > 0 ) {
		console.error( files.length === 0 ? 'The pull request changes no file.' : `Files outside an embed bump:\n${ outside.join( '\n' ) }` );
		process.exit( 1 );
	}
	console.log( `${ files.length } files, all part of an embed bump.` );
}
