import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { Picker } from './picker';
import { galleryRow } from '../test-support/fixtures';

vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const fetchMock = vi.mocked( apiFetch );

beforeEach( () => {
	fetchMock.mockReset();
} );
afterEach( cleanup );

describe( 'Picker', () => {
	it( 'shows a spinner while the galleries load', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		render( <Picker onPick={ vi.fn() } /> );
		expect( screen.getByTestId( 'spinner' ) ).toBeTruthy();
		expect( fetchMock ).toHaveBeenCalledWith( { path: '/profotograaf/v1/galleries' } );
	} );

	it( 'lists the galleries with their photo counts and picks one', async () => {
		fetchMock.mockResolvedValue( [
			galleryRow(),
			galleryRow( { id: 'g-2', title: 'Autumn', photo_count: 1, cover_url: '' } ),
		] );
		const onPick = vi.fn();
		render( <Picker onPick={ onPick } /> );

		await screen.findByText( 'Spring wedding' );
		expect( screen.getByText( '3 photos' ) ).toBeTruthy();
		expect( screen.getByText( '1 photo' ) ).toBeTruthy();

		fireEvent.click( screen.getByText( 'Autumn' ) );
		expect( onPick ).toHaveBeenCalledWith(
			expect.objectContaining( { id: 'g-2', title: 'Autumn' } )
		);
	} );

	it( 'renders a thumbnail only for galleries with a cover', async () => {
		fetchMock.mockResolvedValue( [
			galleryRow(),
			galleryRow( { id: 'g-2', cover_url: '' } ),
		] );
		const { container } = render( <Picker onPick={ vi.fn() } /> );
		await screen.findAllByRole( 'button' );
		expect( container.querySelectorAll( 'img' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( 'span.profotograaf-gallery-picker__thumb' ) ).toHaveLength( 1 );
	} );

	it( 'says so when the account has no galleries', async () => {
		fetchMock.mockResolvedValue( [] );
		render( <Picker onPick={ vi.fn() } /> );
		await screen.findByText( /no galleries in your Profotograaf account/ );
	} );

	it( 'explains how to connect when the site is not connected', async () => {
		fetchMock.mockRejectedValue( { code: 'profotograaf_not_connected' } );
		render( <Picker onPick={ vi.fn() } /> );
		const alert = await screen.findByRole( 'alert' );
		expect( alert.textContent ).toMatch( /Connect this site to Profotograaf/ );
	} );

	it( 'shows the server message for any other failure', async () => {
		fetchMock.mockRejectedValue( { message: 'Rate limited' } );
		render( <Picker onPick={ vi.fn() } /> );
		expect( ( await screen.findByRole( 'alert' ) ).textContent ).toBe( 'Rate limited' );
	} );

	it( 'ignores a response that arrives after unmount', async () => {
		let resolve: ( rows: unknown ) => void = () => undefined;
		fetchMock.mockReturnValue( new Promise( ( r ) => ( resolve = r ) ) );
		const { unmount } = render( <Picker onPick={ vi.fn() } /> );
		unmount();
		resolve( [ galleryRow() ] );
		await waitFor( () => expect( fetchMock ).toHaveBeenCalledTimes( 1 ) );
	} );
} );
