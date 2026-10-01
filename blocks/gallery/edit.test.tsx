import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import Edit from './edit';
import { DISPLAY_DEFAULTS } from './display-options';
import { EXCLUDE_DEFAULTS } from './exclude';
import { galleryRow, photoRow } from '../test-support/fixtures';
import type { GalleryAttributes } from './types';

vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/block-editor', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const fetchMock = vi.mocked( apiFetch );

const empty: GalleryAttributes = {
	galleryId: '',
	galleryTitle: '',
	galleryUrl: '',
	layout: '',
	...DISPLAY_DEFAULTS,
	...EXCLUDE_DEFAULTS,
};

beforeEach( () => {
	fetchMock.mockReset();
} );
afterEach( cleanup );

describe( 'Edit without a gallery', () => {
	it( 'shows the placeholder with the picker', async () => {
		fetchMock.mockResolvedValue( [ galleryRow() ] );
		render( <Edit attributes={ empty } setAttributes={ vi.fn() } /> );
		expect( screen.getByText( 'Choose one of your galleries.' ) ).toBeTruthy();
		await screen.findByText( 'Spring wedding' );
	} );

	it( 'offers no way to clear a gallery that is not chosen', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		render( <Edit attributes={ empty } setAttributes={ vi.fn() } /> );
		expect( screen.queryByText( 'Choose another gallery' ) ).toBeNull();
	} );

	it( 'stores the layout the author selects', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		const setAttributes = vi.fn();
		render( <Edit attributes={ empty } setAttributes={ setAttributes } /> );
		fireEvent.change( screen.getByLabelText( 'Layout' ), { target: { value: 'grid' } } );
		expect( setAttributes ).toHaveBeenCalledWith( { layout: 'grid' } );
	} );

	it( 'stores the picked gallery and shows nothing extra for a ready one', async () => {
		fetchMock.mockResolvedValue( [ galleryRow() ] );
		const setAttributes = vi.fn();
		render( <Edit attributes={ empty } setAttributes={ setAttributes } /> );
		fireEvent.click( await screen.findByText( 'Spring wedding' ) );
		expect( setAttributes ).toHaveBeenCalledWith( {
			galleryId: 'g-1',
			galleryTitle: 'Spring wedding',
			galleryUrl: 'https://studio.example/share/g/spring-wedding',
		} );
		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'asks the server to enable embedding for a gallery that is not embeddable', async () => {
		fetchMock.mockResolvedValueOnce( [ galleryRow( { embeddable: false } ) ] );
		fetchMock.mockResolvedValueOnce( { available: true } );
		render( <Edit attributes={ empty } setAttributes={ vi.fn() } /> );
		fireEvent.click( await screen.findByText( 'Spring wedding' ) );
		await waitFor( () =>
			expect( fetchMock ).toHaveBeenLastCalledWith( {
				path: '/profotograaf/v1/galleries/g-1/embeddable',
				method: 'POST',
			} )
		);
	} );
} );

describe( 'Edit with a gallery', () => {
	const chosen: GalleryAttributes = {
		galleryId: 'g-1',
		galleryTitle: 'Spring wedding',
		galleryUrl: 'https://studio.example/share/g/spring-wedding',
		layout: 'slideshow',
		...DISPLAY_DEFAULTS,
		...EXCLUDE_DEFAULTS,
	};

	it( 'stores each display option the author sets', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		const setAttributes = vi.fn();
		render( <Edit attributes={ chosen } setAttributes={ setAttributes } /> );
		fireEvent.change( screen.getByLabelText( 'Columns' ), { target: { value: '12' } } );
		expect( setAttributes ).toHaveBeenLastCalledWith( { columns: '8' } );
		fireEvent.change( screen.getByLabelText( 'Columns on phones' ), { target: { value: '2' } } );
		expect( setAttributes ).toHaveBeenLastCalledWith( { columnsMobile: '2' } );
		fireEvent.change( screen.getByLabelText( 'Captions' ), { target: { value: 'overlay' } } );
		expect( setAttributes ).toHaveBeenLastCalledWith( { captions: 'overlay' } );
		fireEvent.change( screen.getByLabelText( 'Lightbox' ), { target: { value: 'off' } } );
		expect( setAttributes ).toHaveBeenLastCalledWith( { lightbox: 'off' } );
	} );

	it( 'previews columns, gap, shape and captions', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		const { container } = render(
			<Edit
				attributes={ {
					...chosen,
					columns: '4',
					gap: '0',
					ratio: '16-9',
					captions: 'below',
					perPage: '12',
					lightbox: 'on',
				} }
				setAttributes={ vi.fn() }
			/>
		);
		const grid = container.querySelector< HTMLElement >( '.profotograaf-gallery-grid' );
		expect( grid?.style.getPropertyValue( '--profotograaf-columns' ) ).toBe( '4' );
		expect( grid?.style.getPropertyValue( '--profotograaf-gap' ) ).toBe( '0px' );
		expect( grid?.style.getPropertyValue( '--profotograaf-ratio' ) ).toBe( '16 / 9' );
		expect( screen.getAllByText( 'Caption' ) ).toHaveLength( 6 );
		expect( screen.getByText( '12 photos per page, lightbox on' ) ).toBeTruthy();
	} );

	it( 'shows no captions in the preview when they are hidden', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		render( <Edit attributes={ { ...chosen, captions: 'off' } } setAttributes={ vi.fn() } /> );
		expect( screen.queryByText( 'Caption' ) ).toBeNull();
	} );

	it( 'shows the title and layout, and no picker', async () => {
		fetchMock.mockResolvedValue( [ galleryRow() ] );
		render( <Edit attributes={ chosen } setAttributes={ vi.fn() } /> );
		expect( screen.getByText( 'Spring wedding' ) ).toBeTruthy();
		expect( screen.getByText( 'layout: Slideshow' ) ).toBeTruthy();
		// The gallery list and the photo list.
		await waitFor( () => expect( fetchMock ).toHaveBeenCalledTimes( 2 ) );
		expect( screen.queryByText( /may have been deleted/ ) ).toBeNull();
	} );

	it( 'warns when the gallery is no longer in the account', async () => {
		fetchMock.mockResolvedValue( [ galleryRow( { id: 'g-other' } ) ] );
		render( <Edit attributes={ chosen } setAttributes={ vi.fn() } /> );
		await screen.findByText( /may have been deleted/ );
	} );

	it( 'shows no warning when the list cannot be loaded', async () => {
		fetchMock.mockRejectedValue( { code: 'profotograaf_not_connected' } );
		render( <Edit attributes={ chosen } setAttributes={ vi.fn() } /> );
		await waitFor( () => expect( fetchMock ).toHaveBeenCalledTimes( 2 ) );
		expect( screen.queryByText( /may have been deleted/ ) ).toBeNull();
	} );

	it( 'falls back to a generic title when the block has none', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		render(
			<Edit attributes={ { ...chosen, galleryTitle: '' } } setAttributes={ vi.fn() } />
		);
		expect( screen.getByText( 'Profotograaf gallery' ) ).toBeTruthy();
	} );

	it( 'clears the gallery from the inspector button', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		const setAttributes = vi.fn();
		render( <Edit attributes={ chosen } setAttributes={ setAttributes } /> );
		fireEvent.click( screen.getByText( 'Choose another gallery' ) );
		expect( setAttributes ).toHaveBeenCalledWith( {
			galleryId: '',
			galleryTitle: '',
			galleryUrl: '',
		} );
	} );
} );

describe( 'Edit after picking in this session', () => {
	it( 'shows the cover, count and a warning for a gallery that cannot be embedded', async () => {
		fetchMock.mockResolvedValue( [ galleryRow( { available: false } ) ] );

		let attrs: GalleryAttributes = empty;
		const setAttributes = ( next: Partial< GalleryAttributes > ) => {
			attrs = { ...attrs, ...next };
			view.rerender( <Edit attributes={ attrs } setAttributes={ setAttributes } /> );
		};
		const view = render( <Edit attributes={ attrs } setAttributes={ setAttributes } /> );

		fireEvent.click( await screen.findByText( 'Spring wedding' ) );

		await screen.findByText( /cannot be shown on other sites/ );
		expect( screen.getByText( /3 photos,/ ) ).toBeTruthy();
		expect( view.container.querySelector( 'img.profotograaf-gallery-preview__thumb' ) ).toBeTruthy();
	} );
} );

describe( 'Edit leaving photos out', () => {
	const chosen: GalleryAttributes = {
		...empty,
		galleryId: 'g-1',
		galleryTitle: 'Spring wedding',
		galleryUrl: 'https://studio.example/share/g/spring-wedding',
	};
	const photos = [
		photoRow( { id: 'p-1', title: 'Bride', thumb_url: 'https://studio.example/p-1.jpg' } ),
		photoRow( { id: 'p-2', title: 'Groom', thumb_url: 'https://studio.example/p-2.jpg' } ),
		photoRow( { id: 'p-3', title: 'Rings', thumb_url: 'https://studio.example/p-3.jpg' } ),
	];

	function serve( photoList: unknown ) {
		fetchMock.mockImplementation( ( options ) =>
			Promise.resolve(
				( options as { path: string } ).path.endsWith( '/photos' ) ? photoList : [ galleryRow() ]
			)
		);
	}

	const previewSources = ( container: HTMLElement ) =>
		Array.from( container.querySelectorAll( '.profotograaf-gallery-grid img' ) ).map( ( img ) =>
			img.getAttribute( 'src' )
		);

	it( 'loads the photos of the chosen gallery', async () => {
		serve( photos );
		render( <Edit attributes={ chosen } setAttributes={ vi.fn() } /> );
		await screen.findByLabelText( 'Bride' );
		expect( fetchMock ).toHaveBeenCalledWith( { path: '/profotograaf/v1/galleries/g-1/photos' } );
		expect( screen.getByLabelText( 'Rings' ) ).toBeTruthy();
	} );

	it( 'stores a photo the author selects and shows it again on the next click', async () => {
		serve( photos );
		const setAttributes = vi.fn();
		const view = render( <Edit attributes={ chosen } setAttributes={ setAttributes } /> );
		fireEvent.click( await screen.findByLabelText( 'Groom' ) );
		expect( setAttributes ).toHaveBeenLastCalledWith( { excludedPhotoIds: [ 'p-2' ] } );

		view.rerender(
			<Edit attributes={ { ...chosen, excludedPhotoIds: [ 'p-2' ] } } setAttributes={ setAttributes } />
		);
		fireEvent.click( screen.getByLabelText( 'Groom' ) );
		expect( setAttributes ).toHaveBeenLastCalledWith( { excludedPhotoIds: [] } );
	} );

	it( 'leaves excluded photos out of the editor preview', async () => {
		serve( photos );
		const { container } = render(
			<Edit attributes={ { ...chosen, excludedPhotoIds: [ 'p-2' ] } } setAttributes={ vi.fn() } />
		);
		await screen.findByLabelText( 'Bride' );
		await waitFor( () =>
			expect( previewSources( container ) ).toEqual( [
				'https://studio.example/p-1.jpg',
				'https://studio.example/p-3.jpg',
			] )
		);
		// Once in the inspector panel and once under the preview.
		expect( screen.getAllByText( '1 photo left out' ) ).toHaveLength( 2 );
		expect( screen.getByLabelText( 'Groom' ).getAttribute( 'aria-pressed' ) ).toBe( 'true' );
	} );

	it( 'shows every photo again from the inspector button', async () => {
		serve( photos );
		const setAttributes = vi.fn();
		render(
			<Edit attributes={ { ...chosen, excludedPhotoIds: [ 'p-1', 'p-2' ] } } setAttributes={ setAttributes } />
		);
		fireEvent.click( await screen.findByText( 'Show all photos' ) );
		expect( setAttributes ).toHaveBeenLastCalledWith( { excludedPhotoIds: [] } );
	} );

	it( 'says so when the gallery has no photos', async () => {
		serve( [] );
		render( <Edit attributes={ chosen } setAttributes={ vi.fn() } /> );
		await screen.findByText( 'This gallery has no photos yet.' );
	} );

	it( 'shows a translated warning when the photo list cannot be loaded and keeps the cover preview', async () => {
		fetchMock.mockImplementation( ( options ) =>
			( options as { path: string } ).path.endsWith( '/photos' )
				? Promise.reject( { code: 'profotograaf_http', data: { status: 502 } } )
				: Promise.resolve( [ galleryRow() ] )
		);
		const { container } = render( <Edit attributes={ chosen } setAttributes={ vi.fn() } /> );
		await screen.findByText( 'Profotograaf reported a problem. Try again later.' );
		expect( container.querySelectorAll( '.profotograaf-gallery-grid__tile' ) ).toHaveLength( 6 );
	} );

	it( 'offers no photo panel before a gallery is chosen', () => {
		fetchMock.mockReturnValue( new Promise( () => undefined ) );
		render( <Edit attributes={ empty } setAttributes={ vi.fn() } /> );
		expect( screen.queryByLabelText( 'Photos' ) ).toBeNull();
	} );
} );
