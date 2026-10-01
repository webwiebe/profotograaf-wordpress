import { describe, expect, it, vi } from 'vitest';
import {
	clearedAttributes,
	countLabel,
	eligibility,
	embeddablePath,
	canRetry,
	errorKind,
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

	it( 'asks for a reconnect when the token lacks a permission', () => {
		expect( errorMessage( { code: 'profotograaf_reconnect' } ) ).toMatch(
			/Connect this site to Profotograaf again/
		);
	} );

	it( 'names the wait for a rate limit', () => {
		const limited = { code: 'profotograaf_http', data: { status: 429, retry_after: 42 } };
		expect( errorMessage( limited ) ).toBe(
			'Profotograaf is receiving too many requests. Try again in 42 seconds.'
		);
		expect( errorMessage( { ...limited, data: { status: 429, retry_after: 1 } } ) ).toMatch(
			/in 1 second\./
		);
		expect( errorMessage( { data: { status: 429 } } ) ).toMatch( /in a moment/ );
	} );

	it( 'explains a network failure', () => {
		const text = /could not be reached/;
		expect( errorMessage( { code: 'profotograaf_network' } ) ).toMatch( text );
		expect( errorMessage( { code: 'fetch_error' } ) ).toMatch( text );
		expect( errorMessage( { code: 'invalid_json' } ) ).toMatch( text );
		expect( errorMessage( { data: { status: 504 } } ) ).toMatch( text );
	} );

	it( 'never shows the server text for a platform error', () => {
		expect( errorMessage( { code: 'profotograaf_http', message: 'Raw platform text', data: { status: 502 } } ) ).toMatch(
			/reported a problem/
		);
	} );

	it( 'falls back to a generic message', () => {
		expect( errorMessage( null ) ).toMatch( /reported a problem/ );
		expect( errorMessage( {} ) ).toMatch( /reported a problem/ );
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
		const post = vi.fn().mockRejectedValue( { code: 'profotograaf_network' } );
		expect( await pickNotice( galleryRow( { embeddable: false } ), post ) ).toMatch( /could not be reached/ );
	} );
} );

describe( 'errorKind and canRetry', () => {
	it( 'classifies each failure', () => {
		expect( errorKind( { code: 'profotograaf_not_connected' } ) ).toBe( 'not-connected' );
		expect( errorKind( { code: 'profotograaf_reconnect' } ) ).toBe( 'reconnect' );
		expect( errorKind( { data: { status: 429 } } ) ).toBe( 'rate-limited' );
		expect( errorKind( { code: 'profotograaf_network' } ) ).toBe( 'network' );
		expect( errorKind( { data: { status: 502 } } ) ).toBe( 'platform' );
		expect( errorKind( null ) ).toBe( 'platform' );
	} );

	it( 'offers a retry only where trying again can help', () => {
		expect( canRetry( { code: 'profotograaf_not_connected' } ) ).toBe( false );
		expect( canRetry( { code: 'profotograaf_reconnect' } ) ).toBe( false );
		expect( canRetry( { data: { status: 429 } } ) ).toBe( true );
		expect( canRetry( { code: 'fetch_error' } ) ).toBe( true );
		expect( canRetry( { data: { status: 502 } } ) ).toBe( true );
	} );
} );
