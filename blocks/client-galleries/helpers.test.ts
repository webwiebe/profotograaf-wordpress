import { describe, expect, it } from 'vitest';
import { headingTag, previewCopy } from './helpers';
import type { ClientGalleriesAttributes } from './helpers';

const blank: ClientGalleriesAttributes = {
	portal: '',
	portalPath: '',
	heading: '',
	headingLevel: 3,
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
			portalPath: '',
			heading: 'Your photos',
			headingLevel: 3,
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

describe( 'headingTag', () => {
	it( 'maps levels 2 to 6 and 0 to a paragraph, and defaults to h3', () => {
		expect( [ 0, 2, 3, 4, 5, 6 ].map( headingTag ) ).toEqual( [ 'p', 'h2', 'h3', 'h4', 'h5', 'h6' ] );
		expect( headingTag( 1 ) ).toBe( 'h3' );
		expect( headingTag( 9 ) ).toBe( 'h3' );
	} );
} );
