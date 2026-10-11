import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { SectionsPreview, sectionStyle } from './preview';
import { editorialAnswer, previewPhoto, previewSection } from '../test-support/full-page';

vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );

afterEach( cleanup );

describe( 'SectionsPreview', () => {
	it( 'shows a spinner until the first answer', () => {
		render( <SectionsPreview state={ { answer: null, error: null } } /> );
		expect( screen.getByTestId( 'spinner' ) ).toBeTruthy();
	} );

	it( 'shows the error of the REST route', () => {
		render( <SectionsPreview state={ { answer: null, error: { code: 'profotograaf_not_connected' } } } /> );
		expect( screen.getByRole( 'alert' ).textContent ).toContain( 'Connect this site to Profotograaf' );
	} );

	it( 'says so when the gallery has nothing to show', () => {
		render( <SectionsPreview state={ { answer: { preset: 'editorial', sections: [] }, error: null } } /> );
		expect( screen.getByRole( 'alert' ).textContent ).toContain( 'no photos that can be shown' );
	} );

	it( 'draws the sections with the front-end classes', () => {
		const { container } = render( <SectionsPreview state={ { answer: editorialAnswer(), error: null } } /> );
		const root = container.querySelector( '.pf-fp' );
		expect( root?.getAttribute( 'data-preset' ) ).toBe( 'editorial' );
		const types = Array.from( container.querySelectorAll( 'section.pf-fp-s' ) ).map( ( s ) => s.getAttribute( 'data-type' ) );
		expect( types ).toEqual( [ 'hero', 'text', 'pair', 'grid' ] );
		expect( container.querySelector( '.pf-fp-hero .pf-fp-title' )?.textContent ).toBe( 'Our day' );
		expect( container.querySelector( '.pf-fp-pair' )?.getAttribute( 'data-side' ) ).toBe( 'right' );
		expect( container.querySelector( '.pf-fp-text h2' )?.textContent ).toBe( 'Ceremony' );
		expect( container.querySelectorAll( '.pf-fp-grid img' ) ).toHaveLength( 2 );
		expect( screen.getByAltText( 'Photo p-1' ).getAttribute( 'src' ) ).toBe( 'https://studio.example/img/p-1/thumb.jpg' );
	} );

	it( 'wraps a filmstrip in its track and crops thumbnails to the photo shape where the front end keeps it', () => {
		const strip = previewSection( { type: 'filmstrip', count: 2, rowHeight: 240, gap: 4, slots: [ '', '' ] } );
		const hero = { ...previewSection( { type: 'hero', slots: [ '0.0.0' ] } ), photos: [ previewPhoto( 'p-9', { width: 0, height: 0, thumb: '' } ) ] };
		const masonry = { ...previewSection( { type: 'masonry', slots: [ '' ] } ), photos: [ previewPhoto( 'p-8', { width: 1000, height: 2000 } ) ] };
		const { container } = render( <SectionsPreview state={ { answer: { preset: 'custom', sections: [ strip, hero, masonry ] }, error: null } } /> );
		expect( container.querySelectorAll( '.pf-fp-filmstrip > .pf-fp-track > figure' ) ).toHaveLength( 2 );
		expect( container.querySelector( '.pf-fp-hero img' ) ).toBeNull();
		expect( container.querySelector( '.pf-fp-hero figure' )?.getAttribute( 'style' ) ).toContain( '--pf-ar: 1.5' );
		expect( screen.getByAltText( 'Photo p-8' ).style.aspectRatio ).toBe( '0.5 / 1' );
	} );
} );

describe( 'sectionStyle', () => {
	it( 'writes the custom properties of Full_Page_Markup', () => {
		expect( sectionStyle( { type: 'grid', columns: 4, ratio: '1-1', gap: 12, slots: [], photos: [] } ) ).toEqual( {
			'--pf-gap': '12px',
			'--pf-cols': '4',
			'--pf-ratio': '1/1',
		} );
		expect( sectionStyle( { type: 'grid', ratio: 'original', slots: [], photos: [] } )[ '--pf-ratio' ] ).toBe( 'auto' );
		expect( sectionStyle( { type: 'masonry', columns: 2, gap: 0, slots: [], photos: [] } ) ).toEqual( { '--pf-gap': '0px', '--pf-cols': '2' } );
		expect( sectionStyle( { type: 'hero', height: 60, slots: [], photos: [] } ) ).toEqual( { '--pf-height': '60vh' } );
		expect( sectionStyle( { type: 'filmstrip', rowHeight: 300, gap: 8, slots: [], photos: [] } ) ).toEqual( { '--pf-gap': '8px', '--pf-row': '300px' } );
		expect( sectionStyle( { type: 'text', align: 'right', slots: [], photos: [] } ) ).toEqual( { '--pf-align': 'right' } );
		expect( sectionStyle( { type: 'feature', gap: 4, slots: [], photos: [] } ) ).toEqual( { '--pf-gap': '4px' } );
	} );
} );
