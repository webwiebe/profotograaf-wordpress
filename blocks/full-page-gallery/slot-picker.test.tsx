import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { SlotPicker } from './slot-picker';
import { photoRow } from '../test-support/fixtures';
import { blockAttributes, editorialAnswer } from '../test-support/full-page';
import type { FullPageAttributes } from './types';

vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );

afterEach( cleanup );

const photos = [
	photoRow( { id: 'p-1', title: 'Bride' } ),
	photoRow( { id: 'p-2', title: 'Groom' } ),
	photoRow( { id: 'p-3', title: 'Cake', thumb_url: '' } ),
];

function picker( attributes: FullPageAttributes, answer = editorialAnswer() ) {
	const setAttributes = vi.fn();
	const view = render( <SlotPicker attributes={ attributes } setAttributes={ setAttributes } answer={ answer } photos={ photos } /> );
	return { setAttributes, view };
}

describe( 'SlotPicker', () => {
	it( 'draws nothing without pickable slots', () => {
		const { view } = picker( blockAttributes(), { preset: 'calm', galleryPreset: '', sections: [] } );
		expect( view.container.innerHTML ).toBe( '' );
	} );

	it( 'draws nothing before the preview answered', () => {
		const setAttributes = vi.fn();
		const { container } = render( <SlotPicker attributes={ blockAttributes() } setAttributes={ setAttributes } answer={ null } photos={ null } /> );
		expect( container.innerHTML ).toBe( '' );
	} );

	it( 'names every hero, feature and pair slot', () => {
		picker( blockAttributes( { picks: [ { slot: '0.0.0', photoId: 'p-1' } ] } ) );
		expect( screen.getByText( 'Hero photo 1 (picked)' ) ).toBeTruthy();
		expect( screen.getByText( 'One large and two small photos 1, photo 3' ) ).toBeTruthy();
		expect( screen.getAllByText( 'Clear' ) ).toHaveLength( 1 );
	} );

	it( 'lists the photos that are not excluded and stores the chosen one', () => {
		const { setAttributes } = picker( blockAttributes( { excludedPhotoIds: [ 'p-2' ] } ) );
		fireEvent.click( screen.getAllByText( 'Choose photo' )[ 1 ] as HTMLElement );
		expect( screen.queryByLabelText( 'Groom' ) ).toBeNull();
		expect( screen.getByLabelText( 'Cake' ) ).toBeTruthy();
		fireEvent.click( screen.getByLabelText( 'Bride' ) );
		expect( setAttributes ).toHaveBeenCalledWith( { picks: [ { slot: '0.2.0', photoId: 'p-1' } ] } );
		expect( screen.queryByLabelText( 'Bride' ) ).toBeNull();
	} );

	it( 'closes the photo list again', () => {
		picker( blockAttributes() );
		fireEvent.click( screen.getAllByText( 'Choose photo' )[ 0 ] as HTMLElement );
		expect( screen.getByLabelText( 'Bride' ).getAttribute( 'aria-pressed' ) ).toBe( 'false' );
		fireEvent.click( screen.getByText( 'Close' ) );
		expect( screen.queryByLabelText( 'Bride' ) ).toBeNull();
	} );

	it( 'clears a pick', () => {
		const { setAttributes } = picker( blockAttributes( { picks: [ { slot: '0.0.0', photoId: 'p-1' } ] } ) );
		fireEvent.click( screen.getByText( 'Clear' ) );
		expect( setAttributes ).toHaveBeenCalledWith( { picks: [] } );
	} );

	it( 'stores a pick on the section in Custom', () => {
		const attributes = blockAttributes( { preset: 'custom', sections: [ { type: 'hero' } ] } );
		const { setAttributes } = picker( attributes );
		fireEvent.click( screen.getByText( 'Choose photo' ) );
		fireEvent.click( screen.getByLabelText( 'Groom' ) );
		expect( setAttributes ).toHaveBeenCalledWith( { sections: [ { type: 'hero', photoIds: [ 'p-2' ] } ] } );
	} );
} );
