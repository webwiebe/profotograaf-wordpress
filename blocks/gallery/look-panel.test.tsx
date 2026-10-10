import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { LookPanel } from './look-panel';
import { siteDefaults } from './display-options';
import { LOOK_DEFAULTS } from './look-options';

vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/block-editor', async () => import( '../test-support/wp-mocks' ) );

afterEach( cleanup );

const base = { ...LOOK_DEFAULTS, gap: '', ratio: '', layout: '' };

function panel( attributes: Partial< typeof base > = {}, setAttributes = vi.fn() ) {
	render( <LookPanel attributes={ { ...base, ...attributes } } setAttributes={ setAttributes } defaults={ siteDefaults() } /> );
	return setAttributes;
}

describe( 'LookPanel', () => {
	it( 'stores a preset gap and opens a number field for a custom one', () => {
		const set = panel();
		const preset = screen.getByLabelText( 'Gap between photos' );
		fireEvent.change( preset, { target: { value: '12' } } );
		expect( set ).toHaveBeenLastCalledWith( { gap: '12' } );
		expect( screen.queryByLabelText( 'Custom gap (pixels)' ) ).toBeNull();

		fireEvent.change( preset, { target: { value: 'custom' } } );
		fireEvent.change( screen.getByLabelText( 'Custom gap (pixels)' ), { target: { value: '200' } } );
		expect( set ).toHaveBeenLastCalledWith( { gap: '96' } );
	} );

	it( 'shows a stored custom gap in its number field', () => {
		panel( { gap: '7' } );
		expect( screen.getByLabelText( 'Custom gap (pixels)' ) ).toHaveProperty( 'value', '7' );
	} );

	it( 'offers every option for a grid, the circle included', () => {
		const set = panel();
		for ( const label of [ 'Space between rows (pixels)', 'Space between columns (pixels)', 'Frame width (pixels)', 'Shadow', 'Hover strength' ] ) {
			expect( screen.getByLabelText( label ), label ).toBeTruthy();
		}
		fireEvent.change( screen.getByLabelText( 'Corners' ), { target: { value: 'full' } } );
		expect( set ).toHaveBeenLastCalledWith( { radius: 'full' } );
	} );

	it( 'hides what a slideshow ignores', () => {
		panel( { layout: 'slideshow' } );
		for ( const label of [ 'Space between rows (pixels)', 'Space between columns (pixels)', 'Frame width (pixels)', 'Shadow' ] ) {
			expect( screen.queryByLabelText( label ), label ).toBeNull();
		}
		expect( screen.getByLabelText( 'Hover effect' ).textContent ).not.toMatch( /Grow/ );
	} );

	it( 'hides the strength when the hover effect is off', () => {
		panel( { hover: 'none' } );
		expect( screen.queryByLabelText( 'Hover strength' ) ).toBeNull();
	} );

	it( 'stores a frame width and a clean frame colour', () => {
		const set = panel();
		fireEvent.change( screen.getByLabelText( 'Frame width (pixels)' ), { target: { value: '30' } } );
		expect( set ).toHaveBeenLastCalledWith( { frameWidth: '24' } );
		fireEvent.change( screen.getByLabelText( 'Colour' ), { target: { value: '#ABC' } } );
		expect( set ).toHaveBeenLastCalledWith( { frameColor: '#abc' } );
		fireEvent.change( screen.getByLabelText( 'Colour' ), { target: { value: 'red' } } );
		expect( set ).toHaveBeenLastCalledWith( { frameColor: '' } );
	} );
} );
