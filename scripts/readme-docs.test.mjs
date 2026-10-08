import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';

const readmeTxt = readFileSync( new URL( '../readme.txt', import.meta.url ), 'utf8' );
const readmeMd = readFileSync( new URL( '../README.md', import.meta.url ), 'utf8' );

const faq = readmeTxt.split( '== Frequently Asked Questions ==' )[ 1 ].split( '\n== ' )[ 0 ];

test( 'the FAQ answers the media source questions', () => {
	for ( const question of [
		'How do I switch on the Profotograaf photo library?',
		'Where do I find my Profotograaf photos in the editor?',
		'Which photos are offered?',
		'What does the plugin store on my site?',
		'What happens when I remove a photo or gallery on Profotograaf?',
		'Does deleting a photo in WordPress delete it on Profotograaf?',
		'Can I import a photo again?',
	] ) {
		assert.ok( faq.includes( `= ${ question } =` ), `missing FAQ: ${ question }` );
	}
} );

test( 'the description lists the photo library', () => {
	const description = readmeTxt.split( '== Description ==' )[ 1 ].split( '\n= Privacy =' )[ 0 ];
	assert.match( description, /\*\*Photos in the editor\.\*\*/ );
} );

test( 'the media source docs state the limits', () => {
	for ( const text of [ faq, readmeMd ] ) {
		assert.match( text, /1600 px/ );
		assert.match( text, /upload files/ );
		assert.match( text, /embedding/ );
	}
} );

test( 'README has a media source section', () => {
	assert.match( readmeMd, /^## Profotograaf photos in the editor$/m );
	for ( const word of [ 'Import from Profotograaf', 'Re-import', 'featured image', 'inserter' ] ) {
		assert.ok( readmeMd.toLowerCase().includes( word.toLowerCase() ), `README lacks ${ word }` );
	}
} );

test( 'screenshot captions are numbered without gaps', () => {
	const block = readmeTxt.split( '== Screenshots ==' )[ 1 ].split( '\n== ' )[ 0 ];
	const numbers = [ ...block.matchAll( /^(\d+)\. /gm ) ].map( ( m ) => Number( m[ 1 ] ) );
	assert.ok( numbers.length >= 7 );
	assert.deepEqual(
		numbers,
		numbers.map( ( _, i ) => i + 1 )
	);
	assert.ok( existsSync( new URL( '../assets/wporg/screenshot-1.png', import.meta.url ) ) );
} );

test( 'external services do not claim per-gallery photo calls for browsing', () => {
	const section = readmeTxt
		.split( '= Photo library in the editor (only if you opt in) =' )[ 1 ]
		.split( '\n= Anonymous' )[ 0 ];
	assert.doesNotMatch( section, /embed\/galleries/ );
	assert.match( section, /embed\/photos/ );
	assert.match( section, /share\/img/ );
} );

test( 'the privacy section covers the photo library', () => {
	const privacy = readmeTxt.split( '= Privacy =' )[ 1 ].split( '== External services ==' )[ 0 ];
	assert.match( privacy, /Photo library in the editor/ );
	assert.match( privacy, /1600 px/ );
} );
