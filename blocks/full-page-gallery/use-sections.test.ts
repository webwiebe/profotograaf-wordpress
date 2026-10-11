import { describe, expect, it } from 'vitest';
import { sectionsPath } from './use-sections';
import { blockAttributes } from '../test-support/full-page';

describe( 'sectionsPath', () => {
	it( 'asks for the gallery alone when the block sets nothing', () => {
		expect( sectionsPath( blockAttributes() ) ).toBe( '/profotograaf/v1/galleries/g-1/sections' );
	} );

	it( 'sends the lists as JSON and the excluded ids comma separated', () => {
		const path = sectionsPath(
			blockAttributes( {
				galleryId: 'g 1',
				preset: 'custom',
				sections: [ { type: 'hero', photoIds: [ 'p-1' ] } ],
				picks: [ { slot: '0.0.0', photoId: 'p-2' } ],
				excludedPhotoIds: [ 'p-3', 'p-4' ],
			} )
		);
		expect( path.startsWith( '/profotograaf/v1/galleries/g%201/sections?' ) ).toBe( true );
		const query = new URLSearchParams( path.split( '?' )[ 1 ] );
		expect( query.get( 'preset' ) ).toBe( 'custom' );
		expect( JSON.parse( query.get( 'sections' ) ?? '' ) ).toEqual( [ { type: 'hero', photoIds: [ 'p-1' ] } ] );
		expect( JSON.parse( query.get( 'picks' ) ?? '' ) ).toEqual( [ { slot: '0.0.0', photoId: 'p-2' } ] );
		expect( query.get( 'exclude' ) ).toBe( 'p-3,p-4' );
	} );
} );
