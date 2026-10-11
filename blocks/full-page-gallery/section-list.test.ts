import { describe, expect, it } from 'vitest';
import { moved, removed, replaced, seedSections, withField, withType } from './section-list';
import type { Section } from './sections';

const list: Section[] = [ { type: 'hero' }, { type: 'grid', columns: 4 }, { type: 'text', heading: 'Hi' } ];

describe( 'withField', () => {
	it( 'sets a field', () => {
		expect( withField( { type: 'grid' }, 'columns', 4 ) ).toEqual( { type: 'grid', columns: 4 } );
	} );

	it( 'removes a field set back to undefined, so the default applies', () => {
		expect( withField( { type: 'grid', columns: 4 }, 'columns', undefined ) ).toEqual( { type: 'grid' } );
	} );
} );

describe( 'withType', () => {
	it( 'keeps the fields the new type has and drops the rest', () => {
		expect( withType( { type: 'grid', columns: 4, rows: 3, gap: 12 }, 'masonry' ) ).toEqual( { type: 'masonry', columns: 4, gap: 12 } );
	} );

	it( 'cuts the picked photos to what the new type takes', () => {
		expect( withType( { type: 'pair', photoIds: [ 'a', 'b', 'c' ] }, 'hero' ) ).toEqual( { type: 'hero', photoIds: [ 'a' ] } );
		expect( withType( { type: 'hero', photoIds: [ 'a' ] }, 'text' ) ).toEqual( { type: 'text' } );
	} );
} );

describe( 'list edits', () => {
	it( 'moves a section up and down', () => {
		expect( moved( list, 1, -1 ).map( ( s ) => s.type ) ).toEqual( [ 'grid', 'hero', 'text' ] );
		expect( moved( list, 1, 1 ).map( ( s ) => s.type ) ).toEqual( [ 'hero', 'text', 'grid' ] );
	} );

	it( 'leaves the list alone at its ends', () => {
		expect( moved( list, 0, -1 ) ).toBe( list );
		expect( moved( list, 2, 1 ) ).toBe( list );
	} );

	it( 'removes and replaces a section', () => {
		expect( removed( list, 0 ).map( ( s ) => s.type ) ).toEqual( [ 'grid', 'text' ] );
		expect( replaced( list, 2, { type: 'pair' } )[ 2 ] ).toEqual( { type: 'pair' } );
	} );
} );

describe( 'seedSections', () => {
	it( 'copies one preset cycle without the values that equal the type default', () => {
		expect( seedSections( 'editorial' ) ).toEqual( [
			{ type: 'hero' },
			{ type: 'grid' },
			{ type: 'pair' },
			{ type: 'grid' },
			{ type: 'feature', count: 3 },
		] );
		expect( seedSections( 'playful' )[ 0 ] ).toEqual( { type: 'grid', columns: 4 } );
	} );
} );
