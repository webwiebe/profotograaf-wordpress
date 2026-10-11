import { describe, expect, it } from 'vitest';
import { defaultCopy, galleryMode, headingTag, isGalleryUrl, previewCopy } from './helpers';
import type { ClientGalleriesAttributes } from './helpers';

const blank: ClientGalleriesAttributes = {
	portal: '',
	portalPath: '',
	heading: '',
	headingLevel: 3,
	description: '',
	buttonLabel: '',
	openInNewTab: false,
	gallery: '',
	linkTo: 'portal',
};

const SHARE = 'https://profotograaf.nl/share/g/spring-wedding';

describe( 'previewCopy', () => {
	it( 'falls back to the default copy when the author typed nothing', () => {
		expect( previewCopy( blank ) ).toEqual( {
			heading: 'Find your gallery',
			description: 'Sign in to the client portal to see your photos.',
			buttonLabel: 'Open my gallery',
			notice: 'Only editors see this note. Enter your Profotograaf address in the block settings. Until then visitors do not see this block.',
		} );
	} );

	it( 'uses what the author typed', () => {
		const copy = previewCopy( {
			...blank,
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
			notice: '',
		} );
	} );

	it( 'falls back per field, not all or nothing', () => {
		const copy = previewCopy( { ...blank, portal: 'studio', heading: 'Hi' } );
		expect( copy.heading ).toBe( 'Hi' );
		expect( copy.buttonLabel ).toBe( 'Open my gallery' );
	} );

	it( 'shows the address note only while the portal is empty', () => {
		expect( previewCopy( blank ).notice ).toContain( 'Enter your Profotograaf address' );
		expect( previewCopy( { ...blank, portal: 'studio' } ).notice ).toBe( '' );
	} );

	it( 'uses the gallery copy and no note once a gallery is chosen', () => {
		expect( previewCopy( { ...blank, gallery: SHARE } ) ).toEqual( {
			heading: 'Your photos',
			description: 'See the photos in your gallery.',
			buttonLabel: 'Open your gallery',
			notice: '',
		} );
	} );

	it( 'asks for a gallery in the variation until one is chosen', () => {
		const copy = previewCopy( { ...blank, linkTo: 'gallery', portal: 'studio' } );
		expect( copy.buttonLabel ).toBe( 'Open your gallery' );
		expect( copy.notice ).toContain( 'Choose a gallery in the block settings.' );
	} );

	it( 'says nothing about installing', () => {
		const copy = previewCopy( { ...blank, gallery: SHARE } );
		expect( Object.values( copy ).join( ' ' ).toLowerCase() ).not.toMatch( /install|app/ );
	} );
} );

describe( 'isGalleryUrl and galleryMode', () => {
	it( 'accepts absolute http(s) links with a host only', () => {
		expect( isGalleryUrl( SHARE ) ).toBe( true );
		expect( isGalleryUrl( ` ${ SHARE } ` ) ).toBe( true );
		expect( isGalleryUrl( 'http://studio.profotograaf.nl/share/g/a' ) ).toBe( true );
		expect( isGalleryUrl( '' ) ).toBe( false );
		expect( isGalleryUrl( 'javascript:alert(1)' ) ).toBe( false );
		expect( isGalleryUrl( 'https:///share' ) ).toBe( false );
		expect( isGalleryUrl( '/share/g/a' ) ).toBe( false );
		expect( isGalleryUrl( 'https://profotograaf.nl/share g' ) ).toBe( false );
	} );

	it( 'is in gallery mode with a gallery or in the variation', () => {
		expect( galleryMode( blank ) ).toBe( false );
		expect( galleryMode( { ...blank, gallery: 'not a link' } ) ).toBe( false );
		expect( galleryMode( { ...blank, gallery: SHARE } ) ).toBe( true );
		expect( galleryMode( { ...blank, linkTo: 'gallery' } ) ).toBe( true );
	} );

	it( 'has default copy per link target', () => {
		expect( defaultCopy( false ).buttonLabel ).toBe( 'Open my gallery' );
		expect( defaultCopy( true ).buttonLabel ).toBe( 'Open your gallery' );
	} );
} );

describe( 'headingTag', () => {
	it( 'maps levels 2 to 6 and 0 to a paragraph, and defaults to h3', () => {
		expect( [ 0, 2, 3, 4, 5, 6 ].map( headingTag ) ).toEqual( [ 'p', 'h2', 'h3', 'h4', 'h5', 'h6' ] );
		expect( headingTag( 1 ) ).toBe( 'h3' );
		expect( headingTag( 9 ) ).toBe( 'h3' );
	} );
} );
