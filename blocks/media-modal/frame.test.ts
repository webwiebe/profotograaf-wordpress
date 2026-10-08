import { beforeEach, describe, expect, it, vi } from 'vitest';
import { response, photo } from '../test-support/media-modal';
import { allowsImages, attach, install, MODE } from './frame';
import type { FrameLike, MediaGlobal } from './types';

const fetchPhotos = vi.fn();
const importPhotos = vi.fn();

vi.mock( '@wordpress/api-fetch', () => ( {
	default: ( options: { path: string } ) => ( options.path.includes( '/import' ) ? importPhotos( options ) : fetchPhotos( options ) ),
} ) );

type Handler = ( arg?: unknown ) => void;

/** A frame that records its handlers like Backbone does and has a toolbar button. */
function makeFrame( type: unknown = 'image' ) {
	const handlers = new Map< string, Handler[] >();
	const models = new Map< string, unknown >();
	type Model = { id: string | number };
	const list = ( value: Model | Model[] | undefined ): Model[] => ( Array.isArray( value ) ? value : value ? [ value ] : [] );
	const selection = {
		multiple: true,
		add: ( added: Model | Model[] ) => list( added ).forEach( ( model ) => models.set( String( model.id ), model ) ),
		remove: ( removed: Model | Model[] | undefined ) => list( removed ).forEach( ( model ) => models.delete( String( model.id ) ) ),
	};
	const library = { props: { get: () => type } };
	const el = document.createElement( 'div' );
	el.innerHTML = '<div class="media-toolbar-primary"><button class="button button-primary">Select</button></div>';
	document.body.replaceChildren( el );
	const frame = {
		el,
		on: ( event: string, callback: Handler ) => {
			handlers.set( event, [ ...( handlers.get( event ) ?? [] ), callback ] );
		},
		state: () => ( { get: ( key: string ) => ( 'selection' === key ? selection : library ) } ),
	} as unknown as FrameLike;
	const fire = ( event: string, arg?: unknown ) => ( handlers.get( event ) ?? [] ).forEach( ( handler ) => handler( arg ) );
	return { frame, fire, models, el, button: el.querySelector< HTMLButtonElement >( 'button' ) as HTMLButtonElement };
}

function makeMedia() {
	class View {
		el = document.createElement( 'div' );
		static extend( proto: Record< string, unknown > ) {
			return class extends View {
				constructor() {
					super();
					Object.assign( this, proto );
				}
			};
		}
		remove() {
			return this;
		}
	}
	return {
		View,
		model: { Attachment: class { constructor( public attributes: Record< string, unknown > ) {} get id() { return this.attributes.id; } } },
		attachment: ( id: number ) => ( { id, fetch: () => Promise.resolve( {} ) } ),
		view: { MediaFrame: { Select: { prototype: { bindHandlers: vi.fn() } } } },
	} as unknown as MediaGlobal;
}

describe( 'allowsImages', () => {
	it.each( [
		[ undefined, true ],
		[ '', true ],
		[ 'image', true ],
		[ 'image/jpeg', true ],
		[ [ 'image', 'video' ], true ],
		[ [], true ],
		[ 'audio', false ],
		[ [ 'audio', 'video' ], false ],
	] )( 'for %j returns %s', ( type, expected ) => {
		expect( allowsImages( type ) ).toBe( expected );
	} );
} );

describe( 'install', () => {
	it( 'patches Select.bindHandlers once and attaches the tab to every frame', () => {
		const media = makeMedia();
		const original = media.view.MediaFrame.Select.prototype.bindHandlers;
		install( media );
		install( media );
		const { frame, fire } = makeFrame();

		media.view.MediaFrame.Select.prototype.bindHandlers.call( frame, 'x' );

		expect( original ).toHaveBeenCalledTimes( 1 );
		expect( original ).toHaveBeenCalledWith( 'x' );
		const router = { set: vi.fn() };
		fire( 'router:render:browse', router );
		expect( router.set ).toHaveBeenCalledWith( { [ MODE ]: { text: 'Profotograaf', priority: 60 } } );
	} );

	it( 'does nothing without the media views', () => {
		expect( () => install( undefined ) ).not.toThrow();
		expect( () => install( { view: {} } as unknown as MediaGlobal ) ).not.toThrow();
	} );
} );

describe( 'attach', () => {
	beforeEach( () => {
		fetchPhotos.mockReset().mockResolvedValue( response( [ photo( 'a' ), photo( 'b' ) ] ) );
		importPhotos.mockReset().mockResolvedValue( { results: [ { id: 'a', attachment_id: 11 }, { id: 'b', attachment_id: 12 } ] } );
	} );

	it( 'adds no tab to a frame that lists only other media types', () => {
		const { frame, fire } = makeFrame( 'audio' );
		attach( frame, makeMedia() );
		const router = { set: vi.fn() };

		fire( 'router:render:browse', router );

		expect( router.set ).not.toHaveBeenCalled();
	} );

	function open( type: unknown = 'image' ) {
		const media = makeMedia();
		const parts = makeFrame( type );
		attach( parts.frame, media );
		const region: { view?: { el: HTMLElement } } = {};
		parts.fire( `content:create:${ MODE }`, region );
		parts.fire( `content:activate:${ MODE }` );
		const view = region.view as unknown as { el: HTMLElement; render: () => unknown; remove: () => unknown };
		view.render();
		return { ...parts, view };
	}

	it( 'renders the panel into the content region and loads the photos', async () => {
		const { view } = open();

		await vi.waitFor( () => expect( view.el.querySelectorAll( '[data-photo]' ) ).toHaveLength( 2 ) );
		expect( fetchPhotos ).toHaveBeenCalledTimes( 1 );
		view.remove();
	} );

	it( 'imports on the primary button, swaps in the attachments, then repeats the click', async () => {
		const { view, button, models } = open();
		await vi.waitFor( () => expect( view.el.querySelectorAll( '[data-photo]' ) ).toHaveLength( 2 ) );
		view.el.querySelectorAll< HTMLButtonElement >( '[data-photo]' ).forEach( ( card ) => card.click() );
		expect( [ ...models.keys() ] ).toEqual( [ 'profotograaf:a', 'profotograaf:b' ] );
		const frameClick = vi.fn();
		button.addEventListener( 'click', frameClick );

		button.click();
		expect( frameClick ).not.toHaveBeenCalled();
		await vi.waitFor( () => expect( frameClick ).toHaveBeenCalledTimes( 1 ) );

		expect( importPhotos ).toHaveBeenCalledTimes( 1 );
		expect( importPhotos.mock.calls[ 0 ]?.[ 0 ].data ).toEqual( { ids: [ 'a', 'b' ] } );
		expect( [ ...models.keys() ].sort() ).toEqual( [ '11', '12' ] );
		expect( button.disabled ).toBe( false );
	} );

	it( 'does not continue when a photo fails and shows the reason', async () => {
		importPhotos.mockResolvedValue( { results: [ { id: 'a', attachment_id: 11 }, { id: 'b', error: 'Download failed.' } ] } );
		const { view, button } = open();
		await vi.waitFor( () => expect( view.el.querySelectorAll( '[data-photo]' ) ).toHaveLength( 2 ) );
		view.el.querySelectorAll< HTMLButtonElement >( '[data-photo]' ).forEach( ( card ) => card.click() );
		const frameClick = vi.fn();
		button.addEventListener( 'click', frameClick );

		button.click();
		await vi.waitFor( () => expect( view.el.querySelector( '[role="alert"]' )?.textContent ).toBe( 'Photo b: Download failed.' ) );

		expect( frameClick ).not.toHaveBeenCalled();
		expect( button.disabled ).toBe( false );
	} );

	it( 'leaves the frame button alone when nothing is picked or another tab is active', async () => {
		const { view, button, fire } = open();
		await vi.waitFor( () => expect( view.el.querySelectorAll( '[data-photo]' ) ).toHaveLength( 2 ) );
		const frameClick = vi.fn();
		button.addEventListener( 'click', frameClick );
		button.click();
		expect( frameClick ).toHaveBeenCalledTimes( 1 );

		view.el.querySelector< HTMLButtonElement >( '[data-photo]' )?.click();
		fire( `content:deactivate:${ MODE }` );
		button.click();

		expect( frameClick ).toHaveBeenCalledTimes( 2 );
		expect( importPhotos ).not.toHaveBeenCalled();
	} );

	it( 'drops the picks when the frame closes', async () => {
		const { view, fire, models } = open();
		await vi.waitFor( () => expect( view.el.querySelectorAll( '[data-photo]' ) ).toHaveLength( 2 ) );
		view.el.querySelector< HTMLButtonElement >( '[data-photo]' )?.click();
		expect( models.size ).toBe( 1 );

		fire( 'close' );

		expect( models.size ).toBe( 0 );
	} );

	it( 'ignores clicks outside the primary toolbar button', async () => {
		const { view, el } = open();
		await vi.waitFor( () => expect( view.el.querySelectorAll( '[data-photo]' ) ).toHaveLength( 2 ) );
		view.el.querySelector< HTMLButtonElement >( '[data-photo]' )?.click();

		el.click();

		expect( importPhotos ).not.toHaveBeenCalled();
	} );
} );
