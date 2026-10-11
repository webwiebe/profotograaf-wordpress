import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { layoutOptions } from './gallery/helpers';
import { DISPLAY_DEFAULTS } from './gallery/display-options';
import { LOOK_DEFAULTS } from './gallery/look-options';
import { EXCLUDE_DEFAULTS } from './gallery/exclude';
import { previewCopy } from './client-galleries/helpers';
import type { ClientGalleriesAttributes } from './client-galleries/helpers';

// block.json is the contract with the PHP side. These tests keep the editor's
// TypeScript in step with it.

interface Attribute {
	type: string;
	default: unknown;
	enum?: unknown[];
}
interface BlockJson {
	name: string;
	textdomain: string;
	attributes: Record< string, Attribute >;
	editorScript: string;
}

function load( block: string ): BlockJson {
	return JSON.parse( readFileSync( join( __dirname, block, 'block.json' ), 'utf8' ) ) as BlockJson;
}

describe.each( [ 'gallery', 'client-galleries', 'full-page-gallery' ] )( 'blocks/%s/block.json', ( block ) => {
	const json = load( block );

	it( 'is namespaced and uses the plugin text domain', () => {
		expect( json.name.startsWith( 'profotograaf/' ) ).toBe( true );
		expect( json.textdomain ).toBe( 'profotograaf' );
	} );

	it( 'points its editor script at a source entry', () => {
		expect( json.editorScript ).toBe( 'file:./index.js' );
		const entries = [ 'index.ts', 'index.tsx' ].map( ( f ) => join( __dirname, block, f ) );
		expect( entries.some( existsSync ) ).toBe( true );
	} );

	it( 'gives every attribute a default of its declared type', () => {
		for ( const [ key, attribute ] of Object.entries( json.attributes ) ) {
			const actual = Array.isArray( attribute.default ) ? 'array' : typeof attribute.default;
			expect( actual, key ).toBe( attribute.type );
		}
	} );
} );

describe( 'gallery layout attribute', () => {
	it( 'allows exactly the layouts the editor offers', () => {
		const json = load( 'gallery' );
		expect( json.attributes.layout?.enum ).toEqual( layoutOptions().map( ( o ) => o.value ) );
	} );
} );

describe( 'gallery variations', () => {
	it( 'offer one variation per layout, each setting only the layout', () => {
		const json = load( 'gallery' ) as BlockJson & {
			variations: { name: string; title: string; attributes: Record< string, string > }[];
		};
		const layouts = layoutOptions().map( ( o ) => o.value ).filter( ( v ) => v !== '' && v !== 'auto' );
		expect( json.variations.map( ( v ) => v.attributes.layout ) ).toEqual( layouts );
		for ( const variation of json.variations ) {
			expect( Object.keys( variation.attributes ) ).toEqual( [ 'layout' ] );
			expect( variation.title.length ).toBeGreaterThan( 0 );
		}
	} );
} );

describe( 'gallery display attributes', () => {
	it( 'match the editor defaults and are empty, meaning the site default', () => {
		const json = load( 'gallery' );
		for ( const key of Object.keys( { ...DISPLAY_DEFAULTS, ...LOOK_DEFAULTS } ) ) {
			expect( json.attributes[ key ], key ).toEqual( { type: 'string', default: '' } );
		}
	} );
} );

describe( 'gallery excluded photos attribute', () => {
	it( 'is a list of strings that starts empty, like the editor default', () => {
		const json = load( 'gallery' );
		expect( json.attributes.excludedPhotoIds ).toEqual( {
			type: 'array',
			items: { type: 'string' },
			default: EXCLUDE_DEFAULTS.excludedPhotoIds,
		} );
	} );
} );

describe( 'client galleries attributes', () => {
	it( 'produce the default preview from the block.json defaults', () => {
		const json = load( 'client-galleries' );
		const defaults = Object.fromEntries(
			Object.entries( json.attributes ).map( ( [ k, v ] ) => [ k, v.default ] )
		) as ClientGalleriesAttributes;
		expect( previewCopy( defaults ).notice ).toContain( 'Enter your Profotograaf address' );
		expect( previewCopy( defaults ).buttonLabel ).toBe( 'Open my gallery' );
		expect( Object.keys( defaults ).sort() ).toEqual( [
			'buttonLabel',
			'description',
			'gallery',
			'heading',
			'headingLevel',
			'linkTo',
			'openInNewTab',
			'portal',
			'portalPath',
		] );
	} );

	it( 'keep the gallery link optional and offer the delivered gallery variation', () => {
		const json = load( 'client-galleries' );
		expect( json.attributes.gallery ).toEqual( { type: 'string', default: '' } );
		expect( json.attributes.linkTo ).toEqual( { type: 'string', enum: [ 'portal', 'gallery' ], default: 'portal' } );
		const variations = ( json as { variations?: Array< Record< string, unknown > > } ).variations ?? [];
		expect( variations ).toHaveLength( 1 );
		expect( variations[ 0 ] ).toMatchObject( {
			name: 'delivered-gallery',
			title: 'Open a delivered gallery',
			attributes: { linkTo: 'gallery' },
			isActive: [ 'linkTo' ],
		} );
		expect( JSON.stringify( variations[ 0 ] ).toLowerCase() ).not.toContain( 'install' );
	} );
} );
