import { describe, expect, it, vi } from 'vitest';
import {
	clearedAttributes,
	countLabel,
	eligibility,
	embeddablePath,
	errorMessage,
	layoutLabel,
	layoutOptions,
	noticeForEmbeddable,
	notEligible,
	pickedAttributes,
	pickNotice,
} from './helpers';
import { galleryRow } from '../test-support/fixtures';

describe( 'countLabel', () => {
	it( 'uses the singular for one photo', () => {
		expect( countLabel( 1 ) ).toBe( '1 photo' );
	} );

	it( 'uses the plural for zero and many', () => {
		expect( countLabel( 0 ) ).toBe( '0 photos' );
		expect( countLabel( 12 ) ).toBe( '12 photos' );
	} );
} );

describe( 'layouts', () => {
	it( 'lists the site default first, then the three layouts', () => {
		expect( layoutOptions().map( ( o ) => o.value ) ).toEqual( [
			'',
			'grid',
			'masonry',
			'slideshow',
		] );
	} );

	it( 'labels a layout by its value', () => {
		expect( layoutLabel( 'masonry' ) ).toBe( 'Masonry' );
		expect( layoutLabel( '' ) ).toBe( 'Site default' );
	} );
} );

describe( 'errorMessage', () => {
	it( 'explains how to connect when the site is not connected', () => {
		expect( errorMessage( { code: 'profotograaf_not_connected' } ) ).toMatch(
			/Connect this site to Profotograaf/
		);
	} );

	it( 'passes a server message through', () => {
		expect( errorMessage( { message: 'Rate limited' } ) ).toBe( 'Rate limited' );
	} );

	it( 'falls back to a generic message', () => {
		expect( errorMessage( null ) ).toBe( 'The galleries could not be loaded.' );
		expect( errorMessage( {} ) ).toBe( 'The galleries could not be loaded.' );
	} );
} );

describe( 'picked attributes', () => {
	it( 'copies the id, title and url of the row', () => {
		expect( pickedAttributes( galleryRow() ) ).toEqual( {
			galleryId: 'g-1',
			galleryTitle: 'Spring wedding',
			galleryUrl: 'https://studio.example/share/g/spring-wedding',
		} );
	} );

	it( 'clears all three', () => {
		expect( clearedAttributes() ).toEqual( {
			galleryId: '',
			galleryTitle: '',
			galleryUrl: '',
		} );
	} );
} );

describe( 'eligibility', () => {
	it.each( [
		[ true, true, 'ok' ],
		[ true, false, 'not-eligible' ],
		[ false, true, 'ask-server' ],
		[ false, false, 'ask-server' ],
	] as const )( 'embeddable=%s available=%s is %s', ( embeddable, available, want ) => {
		expect( eligibility( galleryRow( { embeddable, available } ) ) ).toBe( want );
	} );
} );

describe( 'embeddablePath', () => {
	it( 'encodes the id', () => {
		expect( embeddablePath( 'a/b c' ) ).toBe(
			'/profotograaf/v1/galleries/a%2Fb%20c/embeddable'
		);
	} );
} );

describe( 'noticeForEmbeddable', () => {
	it( 'warns only when the server says the gallery is not available', () => {
		expect( noticeForEmbeddable( { available: false } ) ).toBe( notEligible() );
		expect( noticeForEmbeddable( { available: true } ) ).toBe( '' );
		expect( noticeForEmbeddable( {} ) ).toBe( '' );
		expect( noticeForEmbeddable( null ) ).toBe( '' );
	} );
} );

describe( 'pickNotice', () => {
	it( 'shows nothing and asks nothing for a ready gallery', async () => {
		const post = vi.fn();
		expect( await pickNotice( galleryRow(), post ) ).toBe( '' );
		expect( post ).not.toHaveBeenCalled();
	} );

	it( 'warns without asking when the gallery is embeddable but unavailable', async () => {
		const post = vi.fn();
		const message = await pickNotice( galleryRow( { available: false } ), post );
		expect( message ).toBe( notEligible() );
		expect( post ).not.toHaveBeenCalled();
	} );

	it( 'asks the server to enable embedding, and shows nothing when it works', async () => {
		const post = vi.fn().mockResolvedValue( { available: true } );
		const message = await pickNotice( galleryRow( { id: 'g-9', embeddable: false } ), post );
		expect( post ).toHaveBeenCalledWith( '/profotograaf/v1/galleries/g-9/embeddable' );
		expect( message ).toBe( '' );
	} );

	it( 'warns when the server still reports it unavailable', async () => {
		const post = vi.fn().mockResolvedValue( { available: false } );
		expect( await pickNotice( galleryRow( { embeddable: false } ), post ) ).toBe( notEligible() );
	} );

	it( 'turns a failed request into its message', async () => {
		const post = vi.fn().mockRejectedValue( { message: 'Boom' } );
		expect( await pickNotice( galleryRow( { embeddable: false } ), post ) ).toBe( 'Boom' );
	} );
} );
