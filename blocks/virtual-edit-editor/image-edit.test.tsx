import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { BODY_CLASS, cropStyle, withVirtualImage } from './image-edit';
import type { BlockEditProps } from './image-edit';

vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/block-editor', async () => import( '../test-support/wp-mocks' ) );

/** Media records by id, as the core store would return them. */
let media: Record< number, unknown > = {};
let getMedia: ( ( id: number, query: { context: string } ) => unknown ) | undefined;

vi.mock( '@wordpress/data', () => ( {
	useSelect: ( callback: ( select: ( store: string ) => unknown ) => unknown ) =>
		callback( ( store: string ) => ( 'core' === store ? { getMedia } : {} ) ),
} ) );

const Inner = ( props: BlockEditProps ) => <div data-testid="inner">{ props.name }</div>;
const Wrapped = withVirtualImage( Inner );

beforeEach( () => {
	media = {};
	getMedia = ( id ) => media[ id ];
} );
afterEach( () => {
	cleanup();
	document.body.classList.remove( BODY_CLASS );
} );

describe( 'withVirtualImage', () => {
	it( 'hides Crop and explains why for a selected kept photo', () => {
		media[ 5 ] = { profotograaf_virtual: { convert_url: 'https://site.test/convert?attachment=5' } };
		render( <Wrapped name="core/image" isSelected attributes={ { id: 5 } } /> );
		expect( screen.getByTestId( 'inner' ).textContent ).toBe( 'core/image' );
		expect( screen.getAllByRole( 'alert' ) ).toHaveLength( 2 );
		expect( screen.getAllByRole( 'alert' )[ 0 ]?.textContent ).toContain( 'This photo is kept on Profotograaf, so this site has no file to edit.' );
		expect( screen.getAllByRole( 'link', { name: 'Convert to local' } )[ 0 ]?.getAttribute( 'href' ) ).toBe( 'https://site.test/convert?attachment=5' );
		expect( document.body.classList.contains( BODY_CLASS ) ).toBe( true );
	} );

	it( 'drops the body class when the block is deselected', () => {
		media[ 5 ] = { profotograaf_virtual: { convert_url: '' } };
		const view = render( <Wrapped name="core/image" isSelected attributes={ { id: 5 } } /> );
		expect( document.body.classList.contains( BODY_CLASS ) ).toBe( true );
		view.rerender( <Wrapped name="core/image" isSelected={ false } attributes={ { id: 5 } } /> );
		expect( document.body.classList.contains( BODY_CLASS ) ).toBe( false );
		expect( screen.queryByRole( 'link' ) ).toBeNull();
		expect( screen.getAllByRole( 'alert' ) ).toHaveLength( 2 );
	} );

	it( 'leaves a normal image alone', () => {
		media[ 6 ] = { profotograaf_virtual: null };
		render( <Wrapped name="core/image" isSelected attributes={ { id: 6 } } /> );
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
		expect( document.body.classList.contains( BODY_CLASS ) ).toBe( false );
	} );

	it( 'leaves an image without an attachment or a loaded record alone', () => {
		render( <Wrapped name="core/image" isSelected attributes={ {} } /> );
		render( <Wrapped name="core/image" isSelected attributes={ { id: 7 } } /> );
		getMedia = undefined;
		render( <Wrapped name="core/image" isSelected attributes={ { id: 8 } } /> );
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
	} );

	it( 'renders other blocks unchanged', () => {
		media[ 5 ] = { profotograaf_virtual: { convert_url: '' } };
		render( <Wrapped name="core/gallery" isSelected attributes={ { id: 5 } } /> );
		expect( screen.getByTestId( 'inner' ).textContent ).toBe( 'core/gallery' );
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
	} );
} );

describe( 'cropStyle', () => {
	it( 'targets the Crop button by its label while the class is set', () => {
		expect( cropStyle( 'Crop' ) ).toBe(
			`body.${ BODY_CLASS } .block-editor-block-toolbar button[aria-label="Crop"] { display: none; }`
		);
	} );

	it( 'escapes quotes in a translated label', () => {
		expect( cropStyle( 'Bij"snijden' ) ).toContain( 'aria-label="Bij\\"snijden"' );
	} );
} );
