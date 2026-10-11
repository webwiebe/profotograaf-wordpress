import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { SectionFields } from './section-fields';
import type { Section } from './sections';

vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );

afterEach( cleanup );

function fields( section: Section ) {
	const onChange = vi.fn();
	render( <SectionFields section={ section } onChange={ onChange } /> );
	return onChange;
}

function options( label: string ): string[][] {
	return Array.from( screen.getByLabelText< HTMLSelectElement >( label ).options ).map( ( o ) => [ o.value, o.text ] );
}

describe( 'Spacing and shape of a section', () => {
	it( 'offers the gallery block gap presets with the section default first', () => {
		fields( { type: 'grid' } );
		expect( screen.getByText( 'Spacing and shape' ) ).toBeTruthy();
		expect( options( 'Gap between photos' ) ).toEqual( [
			[ '', 'Default (8 pixels)' ],
			[ '0', 'None (0 pixels)' ],
			[ '4', 'Small (4 pixels)' ],
			[ '12', 'Medium (12 pixels)' ],
			[ '24', 'Large (24 pixels)' ],
			[ 'custom', 'Custom' ],
		] );
		expect( options( 'Space between rows (pixels)' )[ 0 ] ).toEqual( [ '', 'Same as the gap between photos' ] );
	} );

	it( 'stores a preset gap and a custom gap', () => {
		const onChange = fields( { type: 'grid' } );
		fireEvent.change( screen.getByLabelText( 'Space between rows (pixels)' ), { target: { value: '12' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { type: 'grid', gapRow: 12 } );

		fireEvent.change( screen.getByLabelText( 'Gap between photos' ), { target: { value: 'custom' } } );
		fireEvent.change( screen.getByLabelText( 'Custom gap (pixels)' ), { target: { value: '200' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { type: 'grid', gap: 96 } );
	} );

	it( 'shows a stored custom gap in its number field and clears a preset', () => {
		const onChange = fields( { type: 'pair', gap: 7, gapColumn: 4 } );
		expect( screen.getByLabelText( 'Custom gap (pixels)' ) ).toHaveProperty( 'value', '7' );
		fireEvent.change( screen.getByLabelText( 'Custom gap (pixels)' ), { target: { value: '' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { type: 'pair', gapColumn: 4 } );
		fireEvent.change( screen.getByLabelText( 'Space between columns (pixels)' ), { target: { value: '' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { type: 'pair', gap: 7 } );
	} );

	it( 'offers a circle only for a square grid', () => {
		fields( { type: 'grid', ratio: '1-1' } );
		expect( options( 'Corners' ).map( ( [ value ] ) => value ) ).toEqual( [ '', 'none', 'subtle', 'rounded', 'full' ] );
		expect( options( 'Corners' )[ 0 ] ).toEqual( [ '', 'Default (Square corners)' ] );
		cleanup();
		fields( { type: 'grid' } );
		expect( options( 'Corners' ).map( ( [ value ] ) => value ) ).not.toContain( 'full' );
	} );

	it( 'leaves grow and shadow out where the type has no room for them', () => {
		fields( { type: 'filmstrip' } );
		expect( options( 'Hover effect' ) ).toEqual( [
			[ '', 'Default (Zoom inside the frame)' ],
			[ 'zoom', 'Zoom inside the frame' ],
			[ 'none', 'None' ],
		] );
		expect( screen.queryByLabelText( 'Shadow' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Space between rows (pixels)' ) ).toBeNull();
	} );

	it( 'hides the strength when the hover effect is off and stores choices', () => {
		const onChange = fields( { type: 'masonry', hover: 'none' } );
		expect( screen.queryByLabelText( 'Hover strength' ) ).toBeNull();
		fireEvent.change( screen.getByLabelText( 'Shadow' ), { target: { value: 'soft' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { type: 'masonry', hover: 'none', shadow: 'soft' } );
		fireEvent.change( screen.getByLabelText( 'Hover effect' ), { target: { value: '' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { type: 'masonry' } );
	} );

	it( 'stores a frame width and a clean frame colour', () => {
		const onChange = fields( { type: 'hero', frameWidth: 3 } );
		expect( screen.getByLabelText( 'Hover strength' ) ).toBeTruthy();
		fireEvent.change( screen.getByLabelText( 'Frame width (pixels)' ), { target: { value: '' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { type: 'hero' } );
		fireEvent.change( screen.getByLabelText( 'Frame width (pixels)' ), { target: { value: '30' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { type: 'hero', frameWidth: 24 } );
		fireEvent.change( screen.getByLabelText( 'Colour' ), { target: { value: '#ABC' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { type: 'hero', frameWidth: 3, frameColor: '#abc' } );
		fireEvent.change( screen.getByLabelText( 'Colour' ), { target: { value: 'red' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { type: 'hero', frameWidth: 3 } );
	} );

	it( 'has no spacing and shape heading for a text section', () => {
		fields( { type: 'text' } );
		expect( screen.queryByText( 'Spacing and shape' ) ).toBeNull();
	} );
} );
