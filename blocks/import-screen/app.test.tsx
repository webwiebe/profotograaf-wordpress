import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import type { ReactNode } from 'react';
import { App } from './app';
import { chunk, errorText, photosPath } from './api';
import type { PhotoItem, PhotosResponse } from './types';

vi.mock( '@wordpress/components', async () => {
	const mocks = await import( '../test-support/wp-mocks' );
	return {
		...mocks,
		Button: ( {
			children,
			onClick,
			disabled,
			type,
		}: {
			children?: ReactNode;
			onClick?: () => void;
			disabled?: boolean;
			type?: 'submit';
		} ) => (
			<button type={ type ?? 'button' } onClick={ onClick } disabled={ disabled }>
				{ children }
			</button>
		),
	};
} );
vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const fetchMock = vi.mocked( apiFetch );

function photo( id: string, extra: Partial< PhotoItem > = {} ): PhotoItem {
	return {
		sourceId: id,
		title: `Photo ${ id }`,
		alt: '',
		previewUrl: `https://profotograaf.nl/${ id }.jpg`,
		galleryId: 'g-1',
		galleryTitle: 'Spring',
		...extra,
	};
}

function listing( items: PhotoItem[], extra: Partial< PhotosResponse > = {} ): PhotosResponse {
	return {
		items,
		totalItems: items.length,
		totalPages: 1,
		galleries: [
			{ id: 'g-1', title: 'Spring', count: 3 },
			{ id: 'g-2', title: 'Autumn', count: 1 },
		],
		stale: false,
		...extra,
	};
}

/** Answers list requests from `pages` (by request path) and import requests with `onImport`. */
function platform( list: ( path: string ) => PhotosResponse, onImport: ( ids: string[] ) => unknown ) {
	fetchMock.mockImplementation( ( async ( options: { path: string; data?: { ids: string[] } } ) => {
		if ( options.path.startsWith( '/profotograaf/v1/photos/import' ) ) {
			return onImport( options.data?.ids ?? [] );
		}
		return list( options.path );
	} ) as unknown as typeof apiFetch );
}

beforeEach( () => {
	fetchMock.mockReset();
} );
afterEach( cleanup );

describe( 'helpers', () => {
	it( 'builds the list path with only the filters that are set', () => {
		expect( photosPath( { search: '', gallery: '', page: 1 } ) ).toBe(
			'/profotograaf/v1/photos?page=1&per_page=24'
		);
		expect( photosPath( { search: 'red door', gallery: 'g-2', page: 3 } ) ).toBe(
			'/profotograaf/v1/photos?page=3&per_page=24&search=red+door&gallery=g-2'
		);
	} );

	it( 'chunks and falls back to a generic error message', () => {
		expect( chunk( [ 1, 2, 3 ], 2 ) ).toEqual( [ [ 1, 2 ], [ 3 ] ] );
		expect( errorText( { message: 'Nope' } ) ).toBe( 'Nope' );
		expect( errorText( null ) ).toBe( 'Something went wrong. Try again.' );
	} );
} );

describe( 'App', () => {
	it( 'shows a spinner, then the photos, with the galleries to filter on', async () => {
		platform( () => listing( [ photo( 'p-1' ), photo( 'p-2' ) ] ), () => ( { results: [] } ) );
		render( <App adminUrl="https://site.test/wp-admin/" /> );
		expect( screen.getByTestId( 'spinner' ) ).toBeTruthy();

		await screen.findByText( 'Photo p-1' );
		expect( screen.getByText( 'Photo p-2' ) ).toBeTruthy();
		expect( screen.getByRole( 'option', { name: 'Spring (3)' } ) ).toBeTruthy();
		expect( fetchMock ).toHaveBeenCalledWith( { path: '/profotograaf/v1/photos?page=1&per_page=24' } );
	} );

	it( 'searches, filters by gallery and pages', async () => {
		platform(
			() => listing( [ photo( 'p-1' ) ], { totalPages: 2 } ),
			() => ( { results: [] } )
		);
		render( <App adminUrl="" /> );
		await screen.findByText( 'Photo p-1' );

		fireEvent.change( screen.getByLabelText( 'Search photos' ), { target: { value: 'door' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Search' } ) );
		await waitFor( () =>
			expect( fetchMock ).toHaveBeenCalledWith( { path: '/profotograaf/v1/photos?page=1&per_page=24&search=door' } )
		);

		fireEvent.change( screen.getByLabelText( 'Gallery' ), { target: { value: 'g-2' } } );
		await waitFor( () =>
			expect( fetchMock ).toHaveBeenCalledWith( {
				path: '/profotograaf/v1/photos?page=1&per_page=24&search=door&gallery=g-2',
			} )
		);

		await screen.findByText( 'Page 1 of 2' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next page' } ) );
		await screen.findByText( 'Page 2 of 2' );
		expect( fetchMock ).toHaveBeenLastCalledWith( {
			path: '/profotograaf/v1/photos?page=2&per_page=24&search=door&gallery=g-2',
		} );
		expect( screen.getByRole( 'button', { name: 'Next page' } ).hasAttribute( 'disabled' ) ).toBe( true );
	} );

	it( 'keeps the selection across pages and imports it in batches with progress', async () => {
		const batches: string[][] = [];
		platform(
			( path ) =>
				path.includes( '?page=2&' )
					? listing( [ photo( 'p-7' ) ], { totalPages: 2 } )
					: listing( [ photo( 'p-1' ), photo( 'p-2' ), photo( 'p-3' ), photo( 'p-4' ), photo( 'p-5' ), photo( 'p-6' ) ], {
							totalPages: 2,
					  } ),
			( ids ) => {
				batches.push( ids );
				return { results: ids.map( ( id, index ) => ( { id, attachment_id: 100 + batches.length * 10 + index } ) ) };
			}
		);
		render( <App adminUrl="https://site.test/wp-admin/" /> );
		await screen.findByText( 'Photo p-1' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Select all on this page' } ) );
		expect( screen.getByRole( 'button', { name: 'Import 6 photos' } ) ).toBeTruthy();

		fireEvent.click( screen.getByRole( 'button', { name: 'Next page' } ) );
		await screen.findByText( 'Photo p-7' );
		fireEvent.click( screen.getByRole( 'button', { name: /Photo p-7/ } ) );
		expect( screen.getByRole( 'button', { name: /Photo p-7/ } ).getAttribute( 'aria-pressed' ) ).toBe( 'true' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Import 7 photos' } ) );
		await screen.findByText( '7 photos imported.' );

		expect( batches ).toEqual( [ [ 'p-1', 'p-2', 'p-3', 'p-4', 'p-5' ], [ 'p-6', 'p-7' ] ] );
		const link = screen.getByRole( 'link', { name: 'Open in Media Library' } );
		expect( link.getAttribute( 'href' ) ).toBe( 'https://site.test/wp-admin/post.php?post=121&action=edit' );
		expect( screen.getByText( 'Imported' ) ).toBeTruthy();
		expect( screen.getByRole( 'button', { name: 'Import 0 photos' } ).hasAttribute( 'disabled' ) ).toBe( true );
	} );

	it( 'shows the progress while a batch runs', async () => {
		let release: ( value: unknown ) => void = () => undefined;
		platform(
			() => listing( [ photo( 'p-1' ), photo( 'p-2' ) ] ),
			() =>
				new Promise( ( resolve ) => {
					release = resolve;
				} )
		);
		render( <App adminUrl="" /> );
		await screen.findByText( 'Photo p-1' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Select all on this page' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Import 2 photos' } ) );

		await screen.findByText( 'Importing 0 of 2' );
		expect( screen.getByRole( 'button', { name: 'Import 2 photos' } ).hasAttribute( 'disabled' ) ).toBe( true );

		release( { results: [ { id: 'p-1', attachment_id: 5 }, { id: 'p-2', attachment_id: 6 } ] } );
		await screen.findByText( '2 photos imported.' );
	} );

	it( 'lists per-photo errors and keeps those photos selected for another try', async () => {
		platform(
			() => listing( [ photo( 'p-1' ), photo( 'p-2' ) ] ),
			() => ( {
				results: [
					{ id: 'p-1', attachment_id: 9 },
					{ id: 'p-2', error: 'The photo could not be downloaded from Profotograaf. Try again later.' },
				],
			} )
		);
		render( <App adminUrl="" /> );
		await screen.findByText( 'Photo p-1' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Select all on this page' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Import 2 photos' } ) );

		await screen.findByText( '1 photo imported.' );
		expect( screen.getByText( /Photo p-2: The photo could not be downloaded/ ) ).toBeTruthy();
		expect( screen.getByRole( 'button', { name: 'Import 1 photo' } ) ).toBeTruthy();
	} );

	it( 'reports a failed request against every photo of the batch', async () => {
		platform(
			() => listing( [ photo( 'p-1' ) ] ),
			() => {
				throw { message: 'Using Profotograaf photos in the editor is switched off in the plugin settings.' };
			}
		);
		render( <App adminUrl="" /> );
		await screen.findByText( 'Photo p-1' );
		fireEvent.click( screen.getByRole( 'button', { name: /Photo p-1/ } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Import 1 photo' } ) );

		await screen.findByText( /Photo p-1: Using Profotograaf photos/ );
		expect( screen.getByText( '0 photos imported.' ) ).toBeTruthy();
	} );

	it( 'marks photos that were imported before and cannot select them', async () => {
		platform( () => listing( [ photo( 'p-1', { id: 44 } ), photo( 'p-2' ) ] ), () => ( { results: [] } ) );
		render( <App adminUrl="https://site.test/wp-admin/" /> );
		await screen.findByText( 'Photo p-1' );

		expect( screen.getByRole( 'link', { name: 'Open in Media Library' } ).getAttribute( 'href' ) ).toBe(
			'https://site.test/wp-admin/post.php?post=44&action=edit'
		);
		expect( screen.getByRole( 'button', { name: /Photo p-1/ } ).hasAttribute( 'disabled' ) ).toBe( true );
		fireEvent.click( screen.getByRole( 'button', { name: 'Select all on this page' } ) );
		expect( screen.getByRole( 'button', { name: 'Import 1 photo' } ) ).toBeTruthy();
	} );

	it( 'clears the selection', async () => {
		platform( () => listing( [ photo( 'p-1' ) ] ), () => ( { results: [] } ) );
		render( <App adminUrl="" /> );
		await screen.findByText( 'Photo p-1' );
		fireEvent.click( screen.getByRole( 'button', { name: /Photo p-1/ } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Clear selection' } ) );
		expect( screen.getByRole( 'button', { name: 'Import 0 photos' } ) ).toBeTruthy();
	} );

	it( 'says so when nothing matches and warns about a stale list', async () => {
		platform( () => listing( [], { stale: true } ), () => ( { results: [] } ) );
		render( <App adminUrl="" /> );
		await screen.findByText( 'No photos found.' );
		expect( screen.getByText( /may be out of date/ ) ).toBeTruthy();
	} );

	it( 'shows the load error and tries again', async () => {
		fetchMock.mockRejectedValueOnce( { code: 'profotograaf_not_connected', message: 'Connect this site first.' } );
		fetchMock.mockResolvedValueOnce( listing( [ photo( 'p-1' ) ] ) );
		render( <App adminUrl="" /> );

		await screen.findByText( 'Connect this site first.' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		await screen.findByText( 'Photo p-1' );
		expect( screen.queryByText( 'Connect this site first.' ) ).toBeNull();
	} );
} );
