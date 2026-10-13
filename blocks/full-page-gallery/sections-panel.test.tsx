import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { SectionsPanel } from './sections-panel';
import { blockAttributes } from '../test-support/full-page';
import type { FullPageAttributes, SectionsAnswer } from './types';

vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/block-editor', async () => import( '../test-support/wp-mocks' ) );

afterEach( cleanup );

function panel( attributes: FullPageAttributes, answer: SectionsAnswer | null = null ) {
	const setAttributes = vi.fn();
	render( <SectionsPanel attributes={ attributes } setAttributes={ setAttributes } answer={ answer } photos={ null } /> );
	return setAttributes;
}

const presetValues = () => Array.from( screen.getByLabelText< HTMLSelectElement >( 'Preset' ).options ).map( ( o ) => o.value );

describe( 'SectionsPanel preset', () => {
	it( 'offers the default, the presets of sections.json and Custom', () => {
		panel( blockAttributes() );
		const options = Array.from( screen.getByLabelText< HTMLSelectElement >( 'Preset' ).options ).map( ( o ) => [ o.value, o.text ] );
		expect( options ).toEqual( [
			[ '', 'Default (Editorial)' ],
			[ 'calm', 'Calm' ],
			[ 'editorial', 'Editorial' ],
			[ 'playful', 'Playful' ],
			[ 'custom', 'Custom' ],
		] );
		expect( screen.queryByText( 'Add a section' ) ).toBeNull();
	} );

	it( "offers the gallery's own layout only when the platform sends a preset", () => {
		panel( blockAttributes(), { preset: 'editorial', galleryPreset: '', sections: [] } );
		expect( presetValues() ).not.toContain( 'gallery' );
		cleanup();

		panel( blockAttributes(), { preset: 'editorial', galleryPreset: 'calm', sections: [] } );
		const own = Array.from( screen.getByLabelText< HTMLSelectElement >( 'Preset' ).options ).find( ( o ) => o.value === 'gallery' );
		expect( own?.text ).toBe( "Use the gallery's own layout (Calm)" );
	} );

	it( "stores the gallery's own layout", () => {
		const setAttributes = panel( blockAttributes(), { preset: 'editorial', galleryPreset: 'custom', sections: [] } );
		fireEvent.change( screen.getByLabelText( 'Preset' ), { target: { value: 'gallery' } } );
		expect( setAttributes ).toHaveBeenCalledWith( { preset: 'gallery' } );
	} );

	it( 'stores a preset', () => {
		const setAttributes = panel( blockAttributes() );
		fireEvent.change( screen.getByLabelText( 'Preset' ), { target: { value: 'playful' } } );
		expect( setAttributes ).toHaveBeenCalledWith( { preset: 'playful' } );
	} );

	it( 'starts Custom from the sections of the preset the block used', () => {
		const setAttributes = panel( blockAttributes( { preset: 'calm' } ) );
		fireEvent.change( screen.getByLabelText( 'Preset' ), { target: { value: 'custom' } } );
		expect( setAttributes ).toHaveBeenCalledWith( { preset: 'custom', sections: [ { type: 'grid' }, { type: 'feature' } ] } );
	} );

	it( 'keeps a Custom list the block already has', () => {
		const setAttributes = panel( blockAttributes( { sections: [ { type: 'hero' } ] } ) );
		fireEvent.change( screen.getByLabelText( 'Preset' ), { target: { value: 'custom' } } );
		expect( setAttributes ).toHaveBeenCalledWith( { preset: 'custom' } );
	} );

	it( 'starts Custom from the default preset when the block had none', () => {
		const setAttributes = panel( blockAttributes() );
		fireEvent.change( screen.getByLabelText( 'Preset' ), { target: { value: 'custom' } } );
		expect( setAttributes.mock.calls[ 0 ]?.[ 0 ].sections ).toHaveLength( 5 );
	} );
} );

describe( 'SectionsPanel in Custom', () => {
	const custom = blockAttributes( {
		preset: 'custom',
		sections: [ { type: 'hero', title: 'Day' }, { type: 'grid', columns: 4 }, { type: 'pair', mirror: false } ],
	} );

	it( 'says what happens without sections', () => {
		panel( blockAttributes( { preset: 'custom' } ) );
		expect( screen.getByText( /Add a section\. Without a section/ ) ).toBeTruthy();
	} );

	it( 'adds a grid section', () => {
		const setAttributes = panel( custom );
		fireEvent.click( screen.getByText( 'Add a section' ) );
		expect( setAttributes.mock.calls[ 0 ]?.[ 0 ].sections.at( -1 ) ).toEqual( { type: 'grid' } );
	} );

	it( 'moves and removes sections, without moving past the ends', () => {
		const setAttributes = panel( custom );
		expect( screen.getAllByText( 'Move up' ) ).toHaveLength( 2 );
		expect( screen.getAllByText( 'Move down' ) ).toHaveLength( 2 );
		fireEvent.click( screen.getAllByText( 'Move up' )[ 0 ] as HTMLElement );
		expect( setAttributes.mock.calls[ 0 ]?.[ 0 ].sections.map( ( s: { type: string } ) => s.type ) ).toEqual( [ 'grid', 'hero', 'pair' ] );
		fireEvent.click( screen.getAllByText( 'Move down' )[ 0 ] as HTMLElement );
		expect( setAttributes.mock.calls[ 1 ]?.[ 0 ].sections.map( ( s: { type: string } ) => s.type ) ).toEqual( [ 'grid', 'hero', 'pair' ] );
		fireEvent.click( screen.getAllByText( 'Remove' )[ 2 ] as HTMLElement );
		expect( setAttributes.mock.calls[ 2 ]?.[ 0 ].sections ).toHaveLength( 2 );
	} );

	it( 'changes the type of a section', () => {
		const setAttributes = panel( custom );
		fireEvent.change( screen.getAllByLabelText( 'Type' )[ 1 ] as HTMLElement, { target: { value: 'masonry' } } );
		expect( setAttributes.mock.calls[ 0 ]?.[ 0 ].sections[ 1 ] ).toEqual( { type: 'masonry', columns: 4 } );
	} );

	it( 'shows the fields of each type with their defaults and ranges', () => {
		panel( custom );
		const columns = screen.getByLabelText( 'Columns' ) as HTMLInputElement;
		expect( columns.value ).toBe( '4' );
		expect( screen.getByLabelText( 'Title over the photo' ) ).toBeTruthy();
		const shape = screen.getByLabelText( 'Photo shape' ) as HTMLSelectElement;
		expect( Array.from( shape.options ).map( ( o ) => o.text ) ).toContain( 'Default (3:2)' );
		expect( Array.from( shape.options ).map( ( o ) => o.text ) ).toContain( 'Square (1:1)' );
		expect( screen.getByLabelText< HTMLSelectElement >( 'Swap sides on every second pair' ).value ).toBe( 'off' );
	} );

	it( 'stores a number field and removes it when emptied', () => {
		const setAttributes = panel( custom );
		fireEvent.change( screen.getByLabelText( 'Rows' ), { target: { value: '9' } } );
		expect( setAttributes.mock.calls[ 0 ]?.[ 0 ].sections[ 1 ] ).toEqual( { type: 'grid', columns: 4, rows: 6 } );
		fireEvent.change( screen.getByLabelText( 'Columns' ), { target: { value: '' } } );
		expect( setAttributes.mock.calls[ 1 ]?.[ 0 ].sections[ 1 ] ).toEqual( { type: 'grid' } );
	} );

	it( 'stores a choice, an on or off field and a text field', () => {
		const setAttributes = panel( custom );
		const section = within( screen.getAllByText( /^Section \d/ )[ 2 ]?.parentElement as HTMLElement );
		fireEvent.change( section.getByLabelText( 'Side of the large photo' ), { target: { value: 'right' } } );
		expect( setAttributes.mock.calls[ 0 ]?.[ 0 ].sections[ 2 ] ).toEqual( { type: 'pair', mirror: false, side: 'right' } );
		fireEvent.change( section.getByLabelText( 'Swap sides on every second pair' ), { target: { value: '' } } );
		expect( setAttributes.mock.calls[ 1 ]?.[ 0 ].sections[ 2 ] ).toEqual( { type: 'pair' } );
		fireEvent.change( screen.getByLabelText( 'Title over the photo' ), { target: { value: '' } } );
		expect( setAttributes.mock.calls[ 2 ]?.[ 0 ].sections[ 0 ] ).toEqual( { type: 'hero' } );
	} );

	it( 'edits the heading and text of a text section', () => {
		const setAttributes = panel( blockAttributes( { preset: 'custom', sections: [ { type: 'text' } ] } ) );
		fireEvent.change( screen.getByLabelText( 'Text' ), { target: { value: 'In the garden' } } );
		expect( setAttributes ).toHaveBeenCalledWith( { sections: [ { type: 'text', text: 'In the garden' } ] } );
		fireEvent.change( screen.getByLabelText( 'Alignment' ), { target: { value: 'left' } } );
		expect( setAttributes ).toHaveBeenLastCalledWith( { sections: [ { type: 'text', align: 'left' } ] } );
	} );
} );
