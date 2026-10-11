import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import Edit from './edit';
import { galleryRow, photoRow } from '../test-support/fixtures';
import { blockAttributes, editorialAnswer } from '../test-support/full-page';

vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/block-editor', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const fetchMock = vi.mocked( apiFetch );

/** Answers each REST path the editor asks for. */
function routes( answers: Record< string, unknown > ) {
	fetchMock.mockImplementation( ( options ) => {
		const path = String( options.path ?? '' );
		const key = Object.keys( answers ).find( ( prefix ) => path.startsWith( prefix ) );
		return key === undefined ? new Promise( () => undefined ) : Promise.resolve( answers[ key ] );
	} );
}

beforeEach( () => {
	fetchMock.mockReset();
} );
afterEach( cleanup );

describe( 'Edit without a gallery', () => {
	const empty = blockAttributes( { galleryId: '', galleryTitle: '', galleryUrl: '' } );

	it( 'shows the placeholder with the gallery picker', async () => {
		routes( { '/profotograaf/v1/galleries': [ galleryRow() ] } );
		render( <Edit attributes={ empty } setAttributes={ vi.fn() } /> );
		expect( screen.getByText( 'Choose one of your galleries.' ) ).toBeTruthy();
		await screen.findByText( 'Spring wedding' );
		expect( screen.queryByLabelText( 'Layout' ) ).toBeNull();
		expect( screen.queryByText( 'Choose another gallery' ) ).toBeNull();
	} );

	it( 'stores the picked gallery', async () => {
		routes( { '/profotograaf/v1/galleries': [ galleryRow() ] } );
		const setAttributes = vi.fn();
		render( <Edit attributes={ empty } setAttributes={ setAttributes } /> );
		fireEvent.click( await screen.findByText( 'Spring wedding' ) );
		expect( setAttributes ).toHaveBeenCalledWith( {
			galleryId: 'g-1',
			galleryTitle: 'Spring wedding',
			galleryUrl: 'https://studio.example/share/g/spring-wedding',
		} );
	} );

	it( 'shows the notice for a gallery that cannot be embedded', async () => {
		fetchMock.mockResolvedValueOnce( [ galleryRow( { embeddable: false } ) ] );
		fetchMock.mockResolvedValueOnce( { available: false } );
		const { rerender } = render( <Edit attributes={ empty } setAttributes={ vi.fn() } /> );
		fireEvent.click( await screen.findByText( 'Spring wedding' ) );
		await waitFor( () =>
			expect( fetchMock ).toHaveBeenCalledWith( { path: '/profotograaf/v1/galleries/g-1/embeddable', method: 'POST' } )
		);
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		rerender( <Edit attributes={ blockAttributes() } setAttributes={ vi.fn() } /> );
		expect( await screen.findByText( /cannot be shown on other sites/ ) ).toBeTruthy();
	} );
} );

describe( 'Edit with a gallery', () => {
	it( 'puts the panels in the order of the issue', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		render( <Edit attributes={ blockAttributes() } setAttributes={ vi.fn() } /> );
		const titles = screen.getAllByRole( 'region' ).map( ( panel ) => panel.getAttribute( 'aria-label' ) );
		expect( titles ).toEqual( [ 'Gallery', 'Layout', 'Photos', 'Display', 'Photo captions and alt text' ] );
	} );

	it( 'draws the sections the REST route answers, with the block settings in the query', async () => {
		routes( {
			'/profotograaf/v1/galleries/g-1/sections': editorialAnswer(),
			'/profotograaf/v1/galleries/g-1/photos': [ photoRow() ],
		} );
		const attributes = blockAttributes( { preset: 'calm', picks: [ { slot: '0.0.0', photoId: 'p-1' } ], excludedPhotoIds: [ 'p-8', 'p-9' ] } );
		const { container } = render( <Edit attributes={ attributes } setAttributes={ vi.fn() } /> );
		await waitFor( () => expect( container.querySelector( '.pf-fp-hero' ) ).not.toBeNull() );
		const path = fetchMock.mock.calls.map( ( [ options ] ) => String( options.path ) ).find( ( p ) => p.includes( '/sections' ) );
		const query = new URLSearchParams( path?.split( '?' )[ 1 ] );
		expect( query.get( 'preset' ) ).toBe( 'calm' );
		expect( JSON.parse( query.get( 'picks' ) ?? '' ) ).toEqual( [ { slot: '0.0.0', photoId: 'p-1' } ] );
		expect( query.get( 'exclude' ) ).toBe( 'p-8,p-9' );
		expect( query.has( 'sections' ) ).toBe( false );
	} );

	it( 'clears the gallery from the Gallery panel', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		const setAttributes = vi.fn();
		render( <Edit attributes={ blockAttributes() } setAttributes={ setAttributes } /> );
		expect( screen.getByText( 'Spring wedding' ) ).toBeTruthy();
		fireEvent.click( screen.getByText( 'Choose another gallery' ) );
		expect( setAttributes ).toHaveBeenCalledWith( { galleryId: '', galleryTitle: '', galleryUrl: '', picks: [], sections: [] } );
	} );

	it( 'offers only captions, lightbox and link in the Display panel and stores the choice', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		const setAttributes = vi.fn();
		render( <Edit attributes={ blockAttributes() } setAttributes={ setAttributes } /> );
		const display = screen.getByRole( 'region', { name: 'Display' } );
		const labels = Array.from( display.querySelectorAll( 'select' ) ).map( ( select ) => select.getAttribute( 'aria-label' ) );
		expect( labels ).toEqual( [ 'Captions', 'Lightbox', 'Link photos to', 'Open photo links in a new tab' ] );
		fireEvent.change( screen.getByLabelText( 'Lightbox' ), { target: { value: 'off' } } );
		expect( setAttributes ).toHaveBeenCalledWith( { lightbox: 'off' } );
	} );

	it( 'stores the excluded photos from the Photos panel', async () => {
		routes( { '/profotograaf/v1/galleries/g-1/photos': [ photoRow() ] } );
		const setAttributes = vi.fn();
		render( <Edit attributes={ blockAttributes() } setAttributes={ setAttributes } /> );
		fireEvent.click( await screen.findByLabelText( 'Bride' ) );
		expect( setAttributes ).toHaveBeenCalledWith( { excludedPhotoIds: [ 'p-1' ] } );
	} );

	it( 'stores image text from its panel', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		const setAttributes = vi.fn();
		render( <Edit attributes={ blockAttributes() } setAttributes={ setAttributes } /> );
		fireEvent.click( screen.getByText( 'Add a photo' ) );
		expect( setAttributes ).toHaveBeenCalledWith( { imageText: [ { id: '' } ] } );
	} );
} );
