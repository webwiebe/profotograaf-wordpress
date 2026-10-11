import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { registerBlockType } from '@wordpress/blocks';
import type { ComponentType } from 'react';
import type { ClientGalleriesAttributes } from './helpers';
import { galleryRow } from '../test-support/fixtures';

vi.mock( '@wordpress/blocks', () => ( { registerBlockType: vi.fn() } ) );
vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/block-editor', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const SHARE = 'https://profotograaf.nl/share/g/spring-wedding';

afterEach( cleanup );

type EditProps = {
	attributes: ClientGalleriesAttributes;
	setAttributes: ( next: Partial< ClientGalleriesAttributes > ) => void;
};

// The module registers once, on its first import. Later imports hit the module
// cache and register nothing, and vitest 5 resets mock call history between
// tests, so capture the registration call the first time and reuse it.
let registration: Promise< { name: string; settings: { edit: ComponentType< EditProps >; save: () => null } } > | undefined;

function registeredEdit() {
	registration ??= ( async () => {
		await import( './index' );
		const call = vi.mocked( registerBlockType ).mock.calls[ 0 ];
		if ( ! call ) {
			throw new Error( 'the block did not register' );
		}
		return { name: call[ 0 ], settings: call[ 1 ] as unknown as { edit: ComponentType< EditProps >; save: () => null } };
	} )();
	return registration;
}

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

describe( 'client galleries block', () => {
	it( 'registers under its block.json name and saves nothing', async () => {
		const { name, settings } = await registeredEdit();
		expect( name ).toBe( 'profotograaf/client-galleries' );
		expect( settings.save() ).toBeNull();
	} );

	it( 'previews the default copy and the address note without a portal', async () => {
		const { settings } = await registeredEdit();
		const Edit = settings.edit;
		render( <Edit attributes={ blank } setAttributes={ vi.fn() } /> );
		expect( screen.getByRole( 'heading' ).textContent ).toBe( 'Find your gallery' );
		expect( screen.getByText( /Only editors see this note/ ) ).toBeTruthy();
	} );

	it( 'previews the heading at the chosen level or as a paragraph', async () => {
		const { settings } = await registeredEdit();
		const Edit = settings.edit;
		const { container, rerender } = render( <Edit attributes={ { ...blank, headingLevel: 2 } } setAttributes={ vi.fn() } /> );
		expect( screen.getByRole( 'heading', { level: 2 } ).textContent ).toBe( 'Find your gallery' );

		rerender( <Edit attributes={ { ...blank, headingLevel: 0 } } setAttributes={ vi.fn() } /> );
		expect( screen.queryByRole( 'heading' ) ).toBeNull();
		expect( container.querySelector( 'p.wp-block-profotograaf-client-galleries__heading' ) ).toBeTruthy();
	} );

	it( 'hides the note once a portal is set', async () => {
		const { settings } = await registeredEdit();
		const Edit = settings.edit;
		render( <Edit attributes={ { ...blank, portal: 'studio' } } setAttributes={ vi.fn() } /> );
		expect( screen.queryByText( /Only editors see this note/ ) ).toBeNull();
	} );

	it( 'writes each inspector field to its own attribute', async () => {
		const { settings } = await registeredEdit();
		const Edit = settings.edit;
		const setAttributes = vi.fn();
		render( <Edit attributes={ blank } setAttributes={ setAttributes } /> );

		fireEvent.change( screen.getByLabelText( 'Portal address' ), { target: { value: 'studio' } } );
		fireEvent.change( screen.getByLabelText( 'Portal path' ), { target: { value: '/portal' } } );
		fireEvent.click( screen.getByLabelText( 'Open in a new tab' ) );
		fireEvent.change( screen.getByLabelText( 'Heading level' ), { target: { value: '2' } } );
		fireEvent.change( screen.getByLabelText( 'Heading' ), { target: { value: 'Hello' } } );
		fireEvent.change( screen.getByRole( 'textbox', { name: 'Text' } ), { target: { value: 'Body' } } );
		fireEvent.change( screen.getByLabelText( 'Button label' ), { target: { value: 'Go' } } );

		expect( setAttributes.mock.calls.map( ( c ) => c[ 0 ] ) ).toEqual( [
			{ portal: 'studio' },
			{ portalPath: '/portal' },
			{ openInNewTab: true },
			{ headingLevel: 2 },
			{ heading: 'Hello' },
			{ description: 'Body' },
			{ buttonLabel: 'Go' },
		] );
	} );

	it( 'previews the gallery copy and hides the portal panel once a gallery is chosen', async () => {
		const { settings } = await registeredEdit();
		const Edit = settings.edit;
		render( <Edit attributes={ { ...blank, gallery: SHARE } } setAttributes={ vi.fn() } /> );

		expect( screen.getByRole( 'heading' ).textContent ).toBe( 'Your photos' );
		expect( screen.getByText( 'Open your gallery' ) ).toBeTruthy();
		expect( screen.getByText( SHARE ) ).toBeTruthy();
		expect( screen.queryByLabelText( 'Portal address' ) ).toBeNull();
		expect( screen.queryByText( /Only editors see this note/ ) ).toBeNull();
	} );

	it( 'asks for a gallery in the canvas for the variation and stores the picked share link', async () => {
		vi.mocked( apiFetch ).mockResolvedValue( [ galleryRow( { url: SHARE } ) ] );
		const { settings } = await registeredEdit();
		const Edit = settings.edit;
		const setAttributes = vi.fn();
		const { container } = render( <Edit attributes={ { ...blank, linkTo: 'gallery' } } setAttributes={ setAttributes } /> );

		expect( container.querySelector( '[data-placeholder="Open a delivered gallery"]' ) ).toBeTruthy();
		expect( screen.getByText( 'No gallery chosen yet.' ) ).toBeTruthy();
		fireEvent.click( await screen.findByText( 'Spring wedding' ) );

		expect( setAttributes ).toHaveBeenCalledWith( { gallery: SHARE } );
	} );

	it( 'chooses and removes a gallery from the inspector', async () => {
		vi.mocked( apiFetch ).mockResolvedValue( [ galleryRow( { url: SHARE } ) ] );
		const { settings } = await registeredEdit();
		const Edit = settings.edit;
		const setAttributes = vi.fn();
		const { rerender } = render( <Edit attributes={ { ...blank, portal: 'studio' } } setAttributes={ setAttributes } /> );

		expect( screen.getByText( 'No gallery chosen. The block links to the client portal.' ) ).toBeTruthy();
		fireEvent.click( screen.getByText( 'Choose a gallery' ) );
		fireEvent.click( await screen.findByText( 'Spring wedding' ) );
		expect( setAttributes ).toHaveBeenLastCalledWith( { gallery: SHARE } );
		expect( screen.queryByText( 'Spring wedding' ) ).toBeNull();

		rerender( <Edit attributes={ { ...blank, portal: 'studio', gallery: SHARE } } setAttributes={ setAttributes } /> );
		expect( screen.getByText( 'Choose another gallery' ) ).toBeTruthy();
		fireEvent.click( screen.getByText( 'Remove gallery' ) );
		expect( setAttributes ).toHaveBeenLastCalledWith( { gallery: '' } );
	} );

	it( 'stores nothing for a gallery without a share link', async () => {
		vi.mocked( apiFetch ).mockResolvedValue( [ galleryRow( { url: '' } ) ] );
		const { settings } = await registeredEdit();
		const Edit = settings.edit;
		const setAttributes = vi.fn();
		render( <Edit attributes={ { ...blank, linkTo: 'gallery' } } setAttributes={ setAttributes } /> );

		fireEvent.click( await screen.findByText( 'Spring wedding' ) );

		expect( setAttributes ).not.toHaveBeenCalled();
		expect( screen.getByRole( 'alert' ).textContent ).toContain( 'This gallery has no share link yet.' );
	} );
} );
