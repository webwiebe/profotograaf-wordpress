import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import { fetchPhotos } from './fetch';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const row = ( overrides: Record< string, unknown > = {} ) => ( {
	url: 'https://site.test/wp-json/profotograaf/v1/photos/p-1/file/profotograaf-p-1.jpg?exp=1&sig=x',
	previewUrl: 'https://profotograaf.nl/share/img/a/thumb-aaaaaaaaaaaa.jpg',
	alt: 'A bride',
	caption: 'Spring',
	title: 'Bride',
	sourceId: 'p-1',
	type: 'image',
	galleryId: 'g-1',
	galleryTitle: 'Spring wedding',
	...overrides,
} );

describe( 'fetchPhotos', () => {
	beforeEach( () => {
		vi.mocked( apiFetch ).mockReset();
		vi.spyOn( console, 'warn' ).mockImplementation( () => undefined );
	} );
	afterEach( () => {
		vi.restoreAllMocks();
	} );

	it( 'asks the photos route with search, per_page and page', async () => {
		vi.mocked( apiFetch ).mockResolvedValue( { items: [], totalItems: 0, totalPages: 0 } );

		await fetchPhotos( { search: 'church door', per_page: 20, page: 2 } );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/profotograaf/v1/photos?search=church+door&per_page=20&page=2',
		} );
	} );

	it( 'leaves out what core leaves out (page 1 has no page)', async () => {
		vi.mocked( apiFetch ).mockResolvedValue( { items: [] } );

		await fetchPhotos( { per_page: 20 } );
		await fetchPhotos( {} );

		expect( vi.mocked( apiFetch ).mock.calls[ 0 ]?.[ 0 ] ).toEqual( { path: '/profotograaf/v1/photos?per_page=20' } );
		expect( vi.mocked( apiFetch ).mock.calls[ 1 ]?.[ 0 ] ).toEqual( { path: '/profotograaf/v1/photos' } );
	} );

	it( 'returns the plain list of items core builds image blocks from', async () => {
		vi.mocked( apiFetch ).mockResolvedValue( { items: [ row() ], totalItems: 45, totalPages: 3 } );

		const result = await fetchPhotos( { per_page: 20 } );

		expect( result ).toEqual( [
			{
				url: row().url,
				previewUrl: row().previewUrl,
				alt: 'A bride',
				caption: 'Spring',
				title: 'Bride',
				sourceId: 'p-1',
				type: 'image',
			},
		] );
	} );

	it( 'passes the attachment id of an imported photo so core inserts without an upload', async () => {
		vi.mocked( apiFetch ).mockResolvedValue( { items: [ row( { id: 71 } ), row( { sourceId: 'p-2' } ) ] } );

		const result = await fetchPhotos( {} );

		expect( result ).toMatchObject( [ { id: 71 }, { sourceId: 'p-2' } ] );
		expect( result[ 1 ] ).not.toHaveProperty( 'id' );
	} );

	it( 'ignores an id that is not a positive number', async () => {
		vi.mocked( apiFetch ).mockResolvedValue( { items: [ row( { id: 0 } ), row( { id: '5' } ) ] } );

		for ( const item of await fetchPhotos( {} ) ) {
			expect( item ).not.toHaveProperty( 'id' );
		}
	} );

	it( 'drops rows without a URL and falls back for missing fields', async () => {
		vi.mocked( apiFetch ).mockResolvedValue( { items: [ null, 'x', { sourceId: 'p-9' }, { url: 'https://site.test/a.jpg' } ] } );

		expect( await fetchPhotos( {} ) ).toEqual( [
			{
				url: 'https://site.test/a.jpg',
				previewUrl: 'https://site.test/a.jpg',
				alt: '',
				caption: '',
				title: '',
				sourceId: '',
				type: 'image',
			},
		] );
	} );

	it( 'treats a malformed response as an empty page', async () => {
		vi.mocked( apiFetch ).mockResolvedValue( 'nope' );

		expect( await fetchPhotos( {} ) ).toEqual( [] );
	} );

	it( 'never rejects: a platform error shows an empty list and logs', async () => {
		vi.mocked( apiFetch ).mockRejectedValue( { code: 'profotograaf_http', data: { status: 502 } } );

		await expect( fetchPhotos( { per_page: 20 } ) ).resolves.toEqual( [] );
		expect( console.warn ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'never rejects when apiFetch throws synchronously or resolves null', async () => {
		vi.mocked( apiFetch ).mockImplementation( () => {
			throw new Error( 'boom' );
		} );
		await expect( fetchPhotos( {} ) ).resolves.toEqual( [] );

		vi.mocked( apiFetch ).mockResolvedValue( null );
		await expect( fetchPhotos( {} ) ).resolves.toEqual( [] );
	} );
} );
