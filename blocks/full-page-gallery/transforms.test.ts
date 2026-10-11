import { describe, expect, it, vi } from 'vitest';
import { createBlock } from '@wordpress/blocks';
import { carried, transforms } from './transforms';

vi.mock( '@wordpress/blocks', () => ( {
	createBlock: vi.fn( ( name: string, attributes: Record< string, unknown > ) => ( { name, attributes } ) ),
} ) );

const gallery = {
	galleryId: 'g-1',
	galleryTitle: 'Spring wedding',
	galleryUrl: 'https://studio.example/share/g/spring-wedding',
	layout: 'masonry',
	columns: '4',
	excludedPhotoIds: [ 'p-2' ],
	captions: 'below',
	lightbox: 'off',
	linkTo: 'page',
	linkNewTab: '',
	imageText: [],
	align: 'wide',
};

describe( 'carried', () => {
	it( 'keeps the shared attributes that hold a value', () => {
		expect( carried( gallery ) ).toEqual( {
			galleryId: 'g-1',
			galleryTitle: 'Spring wedding',
			galleryUrl: 'https://studio.example/share/g/spring-wedding',
			excludedPhotoIds: [ 'p-2' ],
			captions: 'below',
			lightbox: 'off',
			linkTo: 'page',
		} );
	} );

	it( 'reads the first block of a selection', () => {
		expect( carried( [ { galleryId: 'g-2' }, { galleryId: 'g-3' } ] ) ).toEqual( { galleryId: 'g-2' } );
		expect( carried( [] ) ).toEqual( {} );
	} );
} );

describe( 'transforms', () => {
	it( 'turns a gallery block into a full-page gallery', () => {
		const from = transforms.from[ 0 ];
		expect( from?.blocks ).toEqual( [ 'profotograaf/gallery' ] );
		from?.transform?.( gallery, [] );
		expect( createBlock ).toHaveBeenLastCalledWith( 'profotograaf/full-page-gallery', carried( gallery ) );
	} );

	it( 'turns a full-page gallery into a gallery block, without its sections and picks', () => {
		const to = transforms.to[ 0 ];
		expect( to?.blocks ).toEqual( [ 'profotograaf/gallery' ] );
		to?.transform?.( { galleryId: 'g-1', preset: 'custom', sections: [ { type: 'hero' } ], imageText: [ { id: 'p-1', alt: 'Rings' } ] }, [] );
		expect( createBlock ).toHaveBeenLastCalledWith( 'profotograaf/gallery', { galleryId: 'g-1', imageText: [ { id: 'p-1', alt: 'Rings' } ] } );
	} );
} );
