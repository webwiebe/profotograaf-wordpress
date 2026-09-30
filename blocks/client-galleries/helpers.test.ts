import { describe, expect, it } from 'vitest';
import { previewCopy } from './helpers';
import type { ClientGalleriesAttributes } from './helpers';

const blank: ClientGalleriesAttributes = {
	portal: '',
	heading: '',
	description: '',
	buttonLabel: '',
	openInNewTab: false,
};

describe( 'previewCopy', () => {
	it( 'falls back to the default copy when the author typed nothing', () => {
		expect( previewCopy( blank ) ).toEqual( {
			heading: 'Find your gallery',
			description: 'Sign in to the client portal to see your photos.',
			buttonLabel: 'Open my gallery',
			showNotice: true,
		} );
	} );

	it( 'uses what the author typed', () => {
		const copy = previewCopy( {
			portal: 'studio',
			heading: 'Your photos',
			description: 'Log in below.',
			buttonLabel: 'Go',
			openInNewTab: true,
		} );
		expect( copy ).toEqual( {
			heading: 'Your photos',
			description: 'Log in below.',
			buttonLabel: 'Go',
			showNotice: false,
		} );
	} );

	it( 'falls back per field, not all or nothing', () => {
		const copy = previewCopy( { ...blank, portal: 'studio', heading: 'Hi' } );
		expect( copy.heading ).toBe( 'Hi' );
		expect( copy.buttonLabel ).toBe( 'Open my gallery' );
	} );

	it( 'shows the address note only while the portal is empty', () => {
		expect( previewCopy( blank ).showNotice ).toBe( true );
		expect( previewCopy( { ...blank, portal: 'studio' } ).showNotice ).toBe( false );
	} );
} );
