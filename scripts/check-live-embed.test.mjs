import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { adoptionMarkers, missingMarkers } from './check-live-embed.mjs';

const MORE = '.pf > .gallery > .more';
const SLIDES = '.pf > .gallery > .slides';

test( 'adoption selectors are read out of minified code', () => {
	const script = `a=r.querySelector("${ MORE }");b=r.querySelector("${ SLIDES }");c=r.querySelector("${ MORE }")`;
	assert.deepEqual( [ ...adoptionMarkers( script ) ].sort(), [ MORE, SLIDES ] );
	assert.equal( adoptionMarkers( 'nothing here' ).size, 0 );
} );

test( 'a live script that lacks a selector of the pinned one is reported', () => {
	const pinned = `"${ MORE }","${ SLIDES }"`;
	assert.deepEqual( missingMarkers( pinned, `"${ MORE }"` ), [ SLIDES ] );
	assert.deepEqual( missingMarkers( pinned, 'x' ), [ MORE, SLIDES ] );
} );

test( 'a live script with every selector, or one that has more, passes', () => {
	const pinned = `"${ MORE }"`;
	assert.deepEqual( missingMarkers( pinned, `"${ MORE }","${ SLIDES }"` ), [] );
} );

test( 'the pinned package carries the selectors the check depends on', () => {
	const pinned = readFileSync( new URL( '../node_modules/@profotograaf/embed/dist/embed.js', import.meta.url ), 'utf8' );
	assert.ok( adoptionMarkers( pinned ).has( MORE ) );
	assert.ok( adoptionMarkers( pinned ).has( SLIDES ) );
} );
