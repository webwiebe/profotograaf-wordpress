import { describe, expect, it, vi } from 'vitest';
import { registerBlockType } from '@wordpress/blocks';

vi.mock( '@wordpress/blocks', () => ( { registerBlockType: vi.fn() } ) );
vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/block-editor', async () => import( '../test-support/wp-mocks' ) );

describe( 'gallery block registration', () => {
	it( 'registers under its block.json name with an edit component and no saved markup', async () => {
		await import( './index' );
		expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
		const [ name, settings ] = vi.mocked( registerBlockType ).mock.calls[ 0 ] as unknown as [
			string,
			{ edit: unknown; save: () => null },
		];
		expect( name ).toBe( 'profotograaf/gallery' );
		expect( typeof settings.edit ).toBe( 'function' );
		expect( settings.save() ).toBeNull();
	} );
} );
