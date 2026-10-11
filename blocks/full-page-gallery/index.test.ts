import { describe, expect, it, vi } from 'vitest';
import { registerBlockType } from '@wordpress/blocks';

vi.mock( '@wordpress/blocks', () => ( { registerBlockType: vi.fn(), createBlock: vi.fn() } ) );
vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/block-editor', async () => import( '../test-support/wp-mocks' ) );

describe( 'full-page gallery block registration', () => {
	it( 'registers under its block.json name with an edit component, transforms and no saved markup', async () => {
		await import( './index' );
		expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
		const [ name, settings ] = vi.mocked( registerBlockType ).mock.calls[ 0 ] as unknown as [
			string,
			{ edit: unknown; save: () => null; transforms: { from: unknown[]; to: unknown[] } },
		];
		expect( name ).toBe( 'profotograaf/full-page-gallery' );
		expect( typeof settings.edit ).toBe( 'function' );
		expect( settings.save() ).toBeNull();
		expect( settings.transforms.from ).toHaveLength( 1 );
		expect( settings.transforms.to ).toHaveLength( 1 );
	} );
} );
