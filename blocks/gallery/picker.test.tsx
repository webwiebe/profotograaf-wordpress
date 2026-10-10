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

	it( 'says 0 visible for a gallery listed with no photos', async () => {
		fetchMock.mockResolvedValue( [ galleryRow( { photo_count: 0 } ) ] );
		render( <Picker onPick={ vi.fn() } /> );
		await screen.findByText( 'Spring wedding' );
		expect( screen.getByText( '0 visible' ) ).toBeTruthy();
	} );

	it( 'uses the alt text the platform sends for the cover and keeps it empty otherwise', async () => {
		fetchMock.mockResolvedValue( [
			galleryRow( { cover_alt: 'The couple at the altar' } ),
			galleryRow( { id: 'g-2', title: 'Autumn' } ),
		] );
		const { container } = render( <Picker onPick={ vi.fn() } /> );
		await screen.findAllByRole( 'button' );
		const images = container.querySelectorAll( 'img' );
		expect( images[ 0 ]?.getAttribute( 'alt' ) ).toBe( 'The couple at the altar' );
		expect( images[ 1 ]?.getAttribute( 'alt' ) ).toBe( '' );
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

	it( 'shows a rate limit message and retries on request', async () => {
		fetchMock.mockRejectedValueOnce( {
			code: 'profotograaf_http',
			message: 'Rate limited',
			data: { status: 429, retry_after: 30 },
		} );
		fetchMock.mockResolvedValueOnce( [ galleryRow() ] );
		render( <Picker onPick={ vi.fn() } /> );
		expect( ( await screen.findByRole( 'alert' ) ).textContent ).toMatch( /Try again in 30 seconds/ );

		fireEvent.click( screen.getByText( 'Try again' ) );
		await screen.findByText( 'Spring wedding' );
		expect( fetchMock ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'shows a network message with a retry action', async () => {
		fetchMock.mockRejectedValue( { code: 'fetch_error', message: 'You are probably offline.' } );
		render( <Picker onPick={ vi.fn() } /> );
		expect( ( await screen.findByRole( 'alert' ) ).textContent ).toMatch( /could not be reached/ );
		expect( screen.getByText( 'Try again' ) ).toBeTruthy();
	} );

	it( 'shows a platform error without the raw text', async () => {
		fetchMock.mockRejectedValue( { code: 'profotograaf_http', message: 'Raw platform text', data: { status: 502 } } );
		render( <Picker onPick={ vi.fn() } /> );
		const alert = await screen.findByRole( 'alert' );
		expect( alert.textContent ).toMatch( /reported a problem/ );
		expect( alert.textContent ).not.toMatch( /Raw platform text/ );
	} );

	it( 'asks to reconnect without a retry action', async () => {
		fetchMock.mockRejectedValue( { code: 'profotograaf_reconnect' } );
		render( <Picker onPick={ vi.fn() } /> );
		expect( ( await screen.findByRole( 'alert' ) ).textContent ).toMatch( /Connect this site to Profotograaf again/ );
		expect( screen.queryByText( 'Try again' ) ).toBeNull();
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
