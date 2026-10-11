import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select, subscribe } from '@wordpress/data';

vi.mock( '@wordpress/data', () => ( { dispatch: vi.fn(), select: vi.fn(), subscribe: vi.fn() } ) );
vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const OLD = 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg';
const LOCAL = 'https://site.test/wp-content/uploads/2026/10/profotograaf-p-1.jpg';

type Global = { profotograafVirtualUrls?: unknown };

describe( 'virtual URL refresh script', () => {
	beforeEach( () => {
		vi.resetModules();
		vi.mocked( subscribe ).mockReset();
	} );
	afterEach( () => {
		delete ( window as unknown as Global ).profotograafVirtualUrls;
		vi.restoreAllMocks();
	} );

	it( 'does nothing without the platform URL from the server', async () => {
		await import( './index' );

		expect( subscribe ).not.toHaveBeenCalled();
	} );

	it( 'subscribes to the block editor and updates blocks through it without an undo step', async () => {
		( window as unknown as Global ).profotograafVirtualUrls = { platformUrl: 'https://profotograaf.nl' };
		const blocks = [ { clientId: 'c-1', name: 'core/image', attributes: { id: 7, url: OLD } } ];
		const getBlocks = vi.fn( () => blocks );
		const updateBlockAttributes = vi.fn();
		const markNotPersistent = vi.fn();
		vi.mocked( select ).mockReturnValue( { getBlocks } as unknown as ReturnType< typeof select > );
		vi.mocked( dispatch ).mockReturnValue( {
			updateBlockAttributes,
			__unstableMarkNextChangeAsNotPersistent: markNotPersistent,
		} );
		vi.mocked( apiFetch ).mockResolvedValue( [ { id: 7, profotograaf_urls: { full: LOCAL, sizes: {} } } ] );

		await import( './index' );

		expect( subscribe ).toHaveBeenCalledTimes( 1 );
		expect( vi.mocked( subscribe ).mock.calls[ 0 ]?.[ 1 ] ).toBe( 'core/block-editor' );
		const listener = vi.mocked( subscribe ).mock.calls[ 0 ]?.[ 0 ];
		listener?.();
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( select ).toHaveBeenCalledWith( 'core/block-editor' );
		expect( apiFetch ).toHaveBeenCalledWith( { path: '/wp/v2/media?include=7&per_page=100&_fields=id,profotograaf_urls' } );
		expect( markNotPersistent ).toHaveBeenCalledTimes( 1 );
		expect( updateBlockAttributes ).toHaveBeenCalledWith( 'c-1', { url: LOCAL } );
	} );

	it( 'works with an editor that cannot skip the undo step', async () => {
		( window as unknown as Global ).profotograafVirtualUrls = { platformUrl: 'https://profotograaf.nl' };
		const blocks = [ { clientId: 'c-1', name: 'core/image', attributes: { id: 7, url: OLD } } ];
		const updateBlockAttributes = vi.fn();
		vi.mocked( select ).mockReturnValue( { getBlocks: () => blocks } as unknown as ReturnType< typeof select > );
		vi.mocked( dispatch ).mockReturnValue( { updateBlockAttributes } );
		vi.mocked( apiFetch ).mockResolvedValue( [ { id: 7, profotograaf_urls: { full: LOCAL, sizes: {} } } ] );

		await import( './index' );
		vi.mocked( subscribe ).mock.calls[ 0 ]?.[ 0 ]?.();
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( updateBlockAttributes ).toHaveBeenCalledWith( 'c-1', { url: LOCAL } );
	} );
} );
