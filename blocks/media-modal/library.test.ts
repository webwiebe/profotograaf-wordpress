import { describe, expect, it, vi } from 'vitest';
import { PhotoLibrary } from './library';
import { photo, response } from '../test-support/media-modal';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

describe( 'PhotoLibrary', () => {
	it( 'loads the first page and appends the next one', async () => {
		const loader = vi
			.fn()
			.mockResolvedValueOnce( response( [ photo( 'a' ) ], { totalPages: 2 } ) )
			.mockResolvedValueOnce( response( [ photo( 'b' ) ], { totalPages: 2 } ) );
		const library = new PhotoLibrary( loader );

		await library.first();
		expect( library.hasMore() ).toBe( true );
		await library.more();

		expect( library.state.items.map( ( item ) => item.sourceId ) ).toEqual( [ 'a', 'b' ] );
		expect( library.hasMore() ).toBe( false );
		expect( loader ).toHaveBeenLastCalledWith( { search: '', gallery: '', page: 2 } );
	} );

	it( 'does nothing on more() without a next page or while loading', async () => {
		const loader = vi.fn().mockResolvedValue( response( [ photo( 'a' ) ] ) );
		const library = new PhotoLibrary( loader );
		await library.first();

		await library.more();

		expect( loader ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'restarts at page 1 for a new search or gallery', async () => {
		const loader = vi.fn().mockResolvedValue( response( [ photo( 'a' ) ] ) );
		const library = new PhotoLibrary( loader );
		await library.first();

		await library.setSearch( 'rings' );
		await library.setGallery( 'g-2' );

		expect( loader ).toHaveBeenNthCalledWith( 2, { search: 'rings', gallery: '', page: 1 } );
		expect( loader ).toHaveBeenNthCalledWith( 3, { search: 'rings', gallery: 'g-2', page: 1 } );
	} );

	it( 'drops a response that a newer query overtook', async () => {
		let release: ( value: ReturnType< typeof response > ) => void = () => undefined;
		const loader = vi
			.fn()
			.mockReturnValueOnce( new Promise( ( resolve ) => ( release = resolve ) ) )
			.mockResolvedValueOnce( response( [ photo( 'new' ) ] ) );
		const library = new PhotoLibrary( loader );

		const slow = library.first();
		await library.setSearch( 'new' );
		release( response( [ photo( 'old' ) ] ) );
		await slow;

		expect( library.state.items.map( ( item ) => item.sourceId ) ).toEqual( [ 'new' ] );
	} );

	it( 'turns a failure into a message and keeps working', async () => {
		const loader = vi
			.fn()
			.mockRejectedValueOnce( { message: 'The platform is down.' } )
			.mockResolvedValueOnce( response( [ photo( 'a' ) ] ) );
		const library = new PhotoLibrary( loader );

		await expect( library.first() ).resolves.toBeUndefined();
		expect( library.state.error ).toBe( 'The platform is down.' );
		expect( library.state.loading ).toBe( false );

		await library.first();
		expect( library.state.error ).toBe( '' );
		expect( library.state.items ).toHaveLength( 1 );
	} );

	it( 'falls back to a generic message and keeps earlier pages when a later page fails', async () => {
		const loader = vi
			.fn()
			.mockResolvedValueOnce( response( [ photo( 'a' ) ], { totalPages: 2 } ) )
			.mockRejectedValueOnce( new Error( '' ) );
		const library = new PhotoLibrary( loader );
		await library.first();

		await library.more();

		expect( library.state.error ).toBe( 'Something went wrong. Try again.' );
		expect( library.state.items ).toHaveLength( 1 );
	} );

	it( 'tells subscribers about every change and stops after unsubscribe', async () => {
		const library = new PhotoLibrary( vi.fn().mockResolvedValue( response( [] ) ) );
		const listener = vi.fn();
		const off = library.subscribe( listener );

		await library.first();
		expect( listener ).toHaveBeenCalledTimes( 2 );
		off();
		await library.first();

		expect( listener ).toHaveBeenCalledTimes( 2 );
		expect( library.started() ).toBe( true );
	} );
} );
