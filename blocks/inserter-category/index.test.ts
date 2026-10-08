import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { dispatch } from '@wordpress/data';

vi.mock( '@wordpress/data', () => ( { dispatch: vi.fn() } ) );
vi.mock( '@wordpress/i18n', () => ( { __: ( text: string ) => text } ) );
vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

describe( 'inserter category registration', () => {
	beforeEach( () => {
		vi.resetModules();
		vi.spyOn( console, 'warn' ).mockImplementation( () => undefined );
	} );
	afterEach( () => {
		vi.restoreAllMocks();
	} );

	it( 'registers an image category named profotograaf with translated labels and the fetch', async () => {
		const register = vi.fn();
		vi.mocked( dispatch ).mockReturnValue( { registerInserterMediaCategory: register } );

		await import( './index' );

		expect( dispatch ).toHaveBeenCalledWith( 'core/block-editor' );
		expect( register ).toHaveBeenCalledTimes( 1 );
		const category = register.mock.calls[ 0 ]?.[ 0 ];
		expect( category ).toMatchObject( {
			name: 'profotograaf',
			mediaType: 'image',
			labels: { name: 'Profotograaf', search_items: 'Search Profotograaf photos' },
		} );
		expect( typeof category.fetch ).toBe( 'function' );
		const { fetchPhotos: sameFetch } = await import( './fetch' );
		expect( category.fetch ).toBe( sameFetch );
	} );

	it( 'stays quiet when the editor has no media category action', async () => {
		vi.mocked( dispatch ).mockReturnValue( {} );

		await expect( import( './index' ) ).resolves.toBeDefined();
	} );

	it( 'does not break the editor when registering throws', async () => {
		vi.mocked( dispatch ).mockImplementation( () => {
			throw new Error( 'no store' );
		} );

		await expect( import( './index' ) ).resolves.toBeDefined();
		expect( console.warn ).toHaveBeenCalledTimes( 1 );
	} );
} );
