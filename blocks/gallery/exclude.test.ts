import { describe, expect, it } from 'vitest';
import {
	excludedCount,
	excludedLabel,
	isExcluded,
	photosPath,
	toggleExcluded,
	visiblePhotos,
} from './exclude';
import { photoRow } from '../test-support/fixtures';

describe( 'toggleExcluded', () => {
	it( 'adds a photo that is shown and removes one that is left out', () => {
		expect( toggleExcluded( [ 'a' ], 'b' ) ).toEqual( [ 'a', 'b' ] );
		expect( toggleExcluded( [ 'a', 'b' ], 'a' ) ).toEqual( [ 'b' ] );
	} );

	it( 'does not change the list it was given', () => {
		const list = [ 'a' ];
		toggleExcluded( list, 'b' );
		expect( list ).toEqual( [ 'a' ] );
	} );
} );

describe( 'visiblePhotos', () => {
	const photos = [ photoRow( { id: 'a' } ), photoRow( { id: 'b' } ), photoRow( { id: 'c' } ) ];

	it( 'leaves out the excluded photos and keeps the order', () => {
		expect( visiblePhotos( photos, [ 'b' ] ).map( ( p ) => p.id ) ).toEqual( [ 'a', 'c' ] );
	} );

	it( 'keeps every photo when nothing is excluded', () => {
		expect( visiblePhotos( photos, [] ) ).toHaveLength( 3 );
	} );

	it( 'counts only the left-out ids that still match a photo', () => {
		expect( excludedCount( photos, [ 'b', 'gone' ] ) ).toBe( 1 );
		expect( isExcluded( [ 'gone' ], 'a' ) ).toBe( false );
	} );
} );

describe( 'paths and labels', () => {
	it( 'builds the photo list path with the id encoded', () => {
		expect( photosPath( 'g 1' ) ).toBe( '/profotograaf/v1/galleries/g%201/photos' );
	} );

	it( 'words the count in singular and plural', () => {
		expect( excludedLabel( 1 ) ).toBe( '1 photo left out' );
		expect( excludedLabel( 4 ) ).toBe( '4 photos left out' );
	} );
} );
