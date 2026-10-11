import { afterEach, describe, expect, it, vi } from 'vitest';
import { install, note, replaceEditButtons } from './note';
import type { DetailsPrototype, DetailsView } from './note';

function view( html: string, attributes: Record< string, unknown > ): DetailsView {
	const el = document.createElement( 'div' );
	el.innerHTML = html;
	return { el, model: { get: ( key: string ) => attributes[ key ] } };
}

const BUTTONS = '<div class="attachment-actions"><button type="button" class="button edit-attachment">Edit Image</button></div><a class="edit-attachment" href="#">Edit Image</a>';

describe( 'note', () => {
	it( 'explains why and links to Convert to local', () => {
		const box = note( { convertUrl: 'https://site.test/wp-admin/admin-post.php?action=profotograaf_convert_local&attachment=5' } );
		expect( box.className ).toBe( 'profotograaf-not-editable' );
		expect( box.textContent ).toContain( 'This photo is kept on Profotograaf, so this site has no file to edit.' );
		const link = box.querySelector( 'a' );
		expect( link?.textContent ).toBe( 'Convert to local' );
		expect( link?.getAttribute( 'href' ) ).toContain( 'attachment=5' );
	} );

	it( 'leaves the link out when the user may not convert', () => {
		expect( note( { convertUrl: '' } ).querySelector( 'a' ) ).toBeNull();
	} );
} );

describe( 'replaceEditButtons', () => {
	it( 'swaps every Edit Image button of a kept photo for the note', () => {
		const details = view( BUTTONS, { profotograafNotEditable: { convertUrl: 'https://site.test/convert' } } );
		replaceEditButtons( details );
		expect( details.el.querySelectorAll( '.edit-attachment' ) ).toHaveLength( 0 );
		expect( details.el.querySelectorAll( '.profotograaf-not-editable' ) ).toHaveLength( 2 );
	} );

	it( 'leaves a normal attachment alone', () => {
		const details = view( BUTTONS, {} );
		replaceEditButtons( details );
		expect( details.el.querySelectorAll( '.edit-attachment' ) ).toHaveLength( 2 );
		expect( details.el.querySelector( '.profotograaf-not-editable' ) ).toBeNull();
	} );

	it( 'leaves a view without a model alone', () => {
		const el = document.createElement( 'div' );
		el.innerHTML = BUTTONS;
		replaceEditButtons( { el } );
		expect( el.querySelectorAll( '.edit-attachment' ) ).toHaveLength( 2 );
	} );
} );

describe( 'install', () => {
	it( 'wraps the details render and keeps its result', () => {
		const original = vi.fn( function ( this: DetailsView ) {
			this.el.innerHTML = BUTTONS;
			return 'rendered';
		} );
		const prototype: DetailsPrototype = { render: original };
		expect( install( { view: { Attachment: { Details: { prototype } } } } ) ).toBe( true );

		const details = view( '', { profotograafNotEditable: { convertUrl: '' } } );
		expect( prototype.render.call( details, 'arg' ) ).toBe( 'rendered' );
		expect( original ).toHaveBeenCalledWith( 'arg' );
		expect( details.el.querySelector( '.edit-attachment' ) ).toBeNull();
		expect( details.el.querySelector( '.profotograaf-not-editable' ) ).not.toBeNull();
	} );

	it( 'does nothing without the media views', () => {
		expect( install( undefined ) ).toBe( false );
		expect( install( { view: {} } ) ).toBe( false );
	} );
} );

describe( 'entry', () => {
	afterEach( () => {
		vi.resetModules();
		delete ( window as unknown as { wp?: unknown } ).wp;
	} );

	it( 'patches the details view when wp.media is present', async () => {
		const original = vi.fn();
		const prototype = { render: original };
		( window as unknown as { wp: unknown } ).wp = { media: { view: { Attachment: { Details: { prototype } } } } };
		await import( './index' );
		expect( prototype.render ).not.toBe( original );
	} );

	it( 'loads on a screen without the media views', async () => {
		await expect( import( './index' ) ).resolves.toBeDefined();
	} );
} );
