import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/block-editor', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/data', () => ( { useSelect: vi.fn() } ) );

describe( 'virtual image entry', () => {
	afterEach( () => {
		vi.resetModules();
		delete ( window as unknown as { wp?: unknown } ).wp;
		document.getElementById( 'profotograaf-virtual-image-style' )?.remove();
	} );

	it( 'adds the BlockEdit filter and the Crop style', async () => {
		const addFilter = vi.fn();
		( window as unknown as { wp: unknown } ).wp = { hooks: { addFilter } };
		await import( './index' );
		expect( addFilter ).toHaveBeenCalledWith( 'editor.BlockEdit', 'profotograaf/virtual-image', expect.any( Function ) );
		expect( document.getElementById( 'profotograaf-virtual-image-style' )?.textContent ).toContain( 'aria-label="Crop"' );
	} );

	it( 'does nothing without wp.hooks', async () => {
		await import( './index' );
		expect( document.getElementById( 'profotograaf-virtual-image-style' ) ).toBeNull();
	} );
} );
