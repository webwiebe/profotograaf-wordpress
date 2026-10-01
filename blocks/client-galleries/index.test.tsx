import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { registerBlockType } from '@wordpress/blocks';
import type { ComponentType } from 'react';
import type { ClientGalleriesAttributes } from './helpers';

vi.mock( '@wordpress/blocks', () => ( { registerBlockType: vi.fn() } ) );
vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/block-editor', async () => import( '../test-support/wp-mocks' ) );

afterEach( cleanup );

type EditProps = {
	attributes: ClientGalleriesAttributes;
	setAttributes: ( next: Partial< ClientGalleriesAttributes > ) => void;
};

async function registeredEdit() {
	await import( './index' );
	const call = vi.mocked( registerBlockType ).mock.calls[ 0 ];
	if ( ! call ) {
		throw new Error( 'the block did not register' );
	}
	return { name: call[ 0 ], settings: call[ 1 ] as unknown as { edit: ComponentType< EditProps >; save: () => null } };
}

const blank: ClientGalleriesAttributes = {
	portal: '',
	portalPath: '',
	heading: '',
	headingLevel: 3,
	description: '',
	buttonLabel: '',
	openInNewTab: false,
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
} );
