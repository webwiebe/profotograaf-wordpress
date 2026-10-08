import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

describe( 'media modal entry', () => {
	afterEach( () => {
		vi.resetModules();
		delete ( window as unknown as { wp?: unknown } ).wp;
	} );

	it( 'patches the media frames when wp.media is present', async () => {
		const original = vi.fn();
		const prototype = { bindHandlers: original };
		( window as unknown as { wp: unknown } ).wp = {
			media: { view: { MediaFrame: { Select: { prototype } } } },
		};

		await import( './index' );

		expect( prototype.bindHandlers ).not.toBe( original );
	} );

	it( 'does nothing on a screen without the media views', async () => {
		await expect( import( './index' ) ).resolves.toBeDefined();
	} );
} );
