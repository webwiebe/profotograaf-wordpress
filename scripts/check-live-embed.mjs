#!/usr/bin/env node
/**
 * Fails when the embed.js the platform serves cannot drive the markup the
 * plugin draws on the server.
 *
 * The plugin draws galleries as declarative shadow DOM and the script adopts
 * that markup. Each server-drawn feature is adopted through selectors of the
 * form `.pf > .gallery > .more` (Show more) or `.pf > .gallery > .slides` (the
 * slideshow). The pinned package (embed-package/*.tgz, installed in
 * node_modules) holds every selector the plugin relies on; a live script that
 * lacks one is older than the plugin and leaves that feature inert.
 *
 * Usage: node scripts/check-live-embed.mjs [url]
 *        url defaults to EMBED_SCRIPT_URL, then the platform's embed.js.
 * Exit code: 0 the live script is current, 1 it is older than the pin, 2 it
 *        could not be read (network error, bad status, or no pinned selectors).
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

const DEFAULT_URL = 'https://profotograaf.nl/share/embed/embed.js';
const ADOPTION = /\.pf > \.gallery > [.\w-]+/g;

/**
 * @param {string} script Source of an embed.js.
 * @returns {Set<string>} The adoption selectors it contains.
 */
export function adoptionMarkers( script ) {
	return new Set( script.match( ADOPTION ) ?? [] );
}

/**
 * @param {string} pinned Source of the pinned embed.js.
 * @param {string} live   Source of the live embed.js.
 * @returns {string[]} Selectors the pinned script has and the live one lacks, sorted.
 */
export function missingMarkers( pinned, live ) {
	const have = adoptionMarkers( live );
	return [ ...adoptionMarkers( pinned ) ].filter( ( marker ) => ! have.has( marker ) ).sort();
}

if ( process.argv[ 1 ] && resolve( process.argv[ 1 ] ) === fileURLToPath( import.meta.url ) ) {
	const url = process.argv[ 2 ] || process.env.EMBED_SCRIPT_URL || DEFAULT_URL;
	const root = resolve( fileURLToPath( new URL( '..', import.meta.url ) ) );
	const pinned = readFileSync( resolve( root, 'node_modules/@profotograaf/embed/dist/embed.js' ), 'utf8' );
	if ( adoptionMarkers( pinned ).size === 0 ) {
		console.error( 'The pinned embed.js has no adoption selectors: the check would pass for any script.' );
		process.exit( 2 );
	}
	let live;
	try {
		const response = await fetch( url );
		if ( ! response.ok ) {
			console.error( `${ url } answered ${ response.status }` );
			process.exit( 2 );
		}
		live = await response.text();
	} catch ( error ) {
		console.error( `${ url } could not be read: ${ error.message }` );
		process.exit( 2 );
	}
	const missing = missingMarkers( pinned, live );
	if ( missing.length ) {
		console.error( `${ url } is older than the pinned embed: it lacks ${ missing.join( ', ' ) }.` );
		console.error( 'Deploy the embed package to the platform before a plugin release draws this markup on the server.' );
		process.exit( 1 );
	}
	console.log( `${ url } carries every adoption selector of the pinned embed.` );
}
