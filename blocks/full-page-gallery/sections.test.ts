import { describe, expect, it } from 'vitest';
import {
	DEFAULT_PRESET,
	PHONE_BREAKPOINT,
	PRESETS,
	SECTION_TYPES,
	fieldRule,
	isSectionType,
	presetPattern,
	sectionDefaults,
	sectionFields,
	sectionTake,
	withDefaults,
} from './sections';

describe( 'sections vocabulary', () => {
	it( 'lists the types and presets from includes/sections.json', () => {
		expect( SECTION_TYPES ).toEqual( [ 'grid', 'masonry', 'feature', 'hero', 'pair', 'filmstrip', 'text' ] );
		expect( PRESETS ).toEqual( [ 'calm', 'editorial', 'playful' ] );
		expect( DEFAULT_PRESET ).toBe( 'editorial' );
		expect( PHONE_BREAKPOINT ).toBe( 600 );
	} );

	it.each( [
		[ 'calm', 8 ],
		[ 'editorial', 19 ],
		[ 'playful', 32 ],
	] as const )( 'a %s cycle takes %i photos', ( preset, photos ) => {
		expect( presetPattern( preset ).reduce( ( sum, s ) => sum + sectionTake( s ), 0 ) ).toBe( photos );
	} );

	it( 'has no pattern for custom', () => {
		expect( presetPattern( 'custom' ) ).toEqual( [] );
	} );

	it( 'fills defaults and keeps set values', () => {
		expect( withDefaults( { type: 'grid', columns: 4 } ) ).toEqual( {
			type: 'grid',
			columns: 4,
			rows: 2,
			ratio: '3-2',
			gap: 8,
			captions: 'off',
			photoIds: [],
		} );
		expect( sectionDefaults( 'hero' ) ).toEqual( { height: 85, captions: 'off', title: '' } );
		expect( sectionFields( 'text' ) ).toEqual( [ 'heading', 'text', 'align' ] );
	} );

	it( 'counts photos per type', () => {
		expect( sectionTake( { type: 'grid', columns: 4, rows: 3 } ) ).toBe( 12 );
		expect( sectionTake( { type: 'feature' } ) ).toBe( 2 );
		expect( sectionTake( { type: 'pair' } ) ).toBe( 3 );
		expect( sectionTake( { type: 'text' } ) ).toBe( 0 );
	} );

	it( 'merges type limits over field rules', () => {
		expect( fieldRule( 'feature', 'count' ) ).toEqual( { default: 2, min: 2, max: 3 } );
		expect( fieldRule( 'filmstrip', 'count' ) ).toEqual( { default: 2, min: 1, max: 24 } );
		expect( fieldRule( 'grid', 'ratio' ).enum ).toContain( 'original' );
	} );

	it( 'recognises section types', () => {
		expect( isSectionType( 'pair' ) ).toBe( true );
		expect( isSectionType( 'carousel' ) ).toBe( false );
		expect( isSectionType( 3 ) ).toBe( false );
	} );
} );
