import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ImportOutcome } from '../import-screen/import-job';
import { PhotoLibrary } from './library';
import { Panel, noteText, statusText } from './panel';
import { Session } from './session';
import { photo, response } from '../test-support/media-modal';
import type { SelectionPort } from './types';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const port: SelectionPort = {
	multiple: () => true,
	show: () => undefined,
	hide: () => undefined,
	commit: () => Promise.resolve( [] ),
};

async function open(
	loader = vi.fn().mockResolvedValue( response( [ photo( 'a' ), photo( 'b' ) ] ) ),
	outcome: ImportOutcome = { added: {}, failed: [] }
) {
	const library = new PhotoLibrary( loader );
	const session = new Session( port, library, vi.fn().mockResolvedValue( outcome ) );
	const panel = new Panel( session );
	document.body.append( panel.element );
	await vi.waitFor( () => expect( library.state.loading ).toBe( false ) );
	return { library, session, panel, loader };
}

const pressed = ( id: string ) =>
	document.querySelector< HTMLButtonElement >( `[data-photo="${ id }"]` )?.getAttribute( 'aria-pressed' );

describe( 'Panel', () => {
	beforeEach( () => {
		document.body.replaceChildren();
	} );
	afterEach( () => {
		vi.useRealTimers();
	} );

	it( 'loads the photos once and draws a titled button per photo', async () => {
		const { loader } = await open();

		expect( document.querySelectorAll( '.profotograaf-modal__card' ) ).toHaveLength( 2 );
		expect( document.querySelector( '[data-photo="a"]' )?.textContent ).toBe( 'Photo a' );
		expect( loader ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'marks picked photos and shows the selection count', async () => {
		await open();

		document.querySelector< HTMLButtonElement >( '[data-photo="b"]' )?.click();

		expect( pressed( 'b' ) ).toBe( 'true' );
		expect( pressed( 'a' ) ).toBe( 'false' );
		expect( document.querySelector( '[role="status"]' )?.textContent ).toBe( '1 photo selected' );
	} );

	it( 'searches after a pause in typing', async () => {
		vi.useFakeTimers( { toFake: [ 'setTimeout', 'clearTimeout' ] } );
		const { loader } = await open();
		const input = document.querySelector< HTMLInputElement >( 'input[type="search"]' );
		if ( ! input ) {
			throw new Error( 'no search field' );
		}

		input.value = ' rings ';
		input.dispatchEvent( new Event( 'input' ) );
		input.dispatchEvent( new Event( 'input' ) );
		await vi.advanceTimersByTimeAsync( 400 );

		expect( loader ).toHaveBeenCalledTimes( 2 );
		expect( loader ).toHaveBeenLastCalledWith( { search: 'rings', gallery: '', page: 1 } );
	} );

	it( 'filters by gallery when there are several', async () => {
		const loader = vi.fn().mockResolvedValue(
			response( [ photo( 'a' ) ], {
				galleries: [
					{ id: 'g-1', title: 'Spring', count: 1 },
					{ id: 'g-2', title: 'Autumn', count: 2 },
				],
			} )
		);
		await open( loader );
		const select = document.querySelector< HTMLSelectElement >( 'select' );
		if ( ! select ) {
			throw new Error( 'no gallery select' );
		}
		expect( select.options ).toHaveLength( 3 );
		expect( select.closest( '.profotograaf-modal__field' )?.hasAttribute( 'hidden' ) ).toBe( false );

		select.value = 'g-2';
		select.dispatchEvent( new Event( 'change' ) );

		expect( loader ).toHaveBeenLastCalledWith( { search: '', gallery: 'g-2', page: 1 } );
	} );

	it( 'hides the gallery filter for a single gallery', async () => {
		await open();

		expect( document.querySelector( 'select' )?.closest( '.profotograaf-modal__field' )?.hasAttribute( 'hidden' ) ).toBe( true );
	} );

	it( 'loads more photos on demand and appends cards', async () => {
		const loader = vi
			.fn()
			.mockResolvedValueOnce( response( [ photo( 'a' ) ], { totalPages: 2 } ) )
			.mockResolvedValueOnce( response( [ photo( 'b' ) ], { totalPages: 2 } ) );
		await open( loader );
		const more = document.querySelector< HTMLButtonElement >( '.profotograaf-modal__more' );
		expect( more?.hidden ).toBe( false );

		more?.click();
		await vi.waitFor( () => expect( document.querySelectorAll( '.profotograaf-modal__card' ) ).toHaveLength( 2 ) );

		expect( more?.hidden ).toBe( true );
	} );

	it( 'shows the translated failure and keeps the controls usable', async () => {
		const loader = vi.fn().mockRejectedValue( { message: 'Profotograaf is not reachable.' } );
		await open( loader );

		expect( document.querySelector( '.profotograaf-modal__note' )?.textContent ).toBe( 'Profotograaf is not reachable.' );
		expect( document.querySelector< HTMLInputElement >( 'input[type="search"]' )?.disabled ).toBe( false );
	} );

	it( 'lists import failures per photo', async () => {
		const { session } = await open( undefined, {
			added: {},
			failed: [ { id: 'a', title: 'Photo a', message: 'Download failed.' } ],
		} );
		session.toggle( photo( 'a' ) );

		await session.confirm();

		expect( document.querySelector( '[role="alert"]' )?.textContent ).toBe( 'Photo a: Download failed.' );
	} );

	it( 'stops updating after destroy', async () => {
		const { panel, session } = await open();
		panel.destroy();

		session.toggle( photo( 'a' ) );

		expect( document.querySelector( '[role="status"]' )?.textContent ).toBe( '' );
	} );
} );

describe( 'status and note text', () => {
	it( 'describes progress, the selection and an empty result', async () => {
		const library = new PhotoLibrary( vi.fn().mockResolvedValue( response( [] ) ) );
		const session = new Session( port, library );
		expect( statusText( session ) ).toBe( '' );

		session.toggle( photo( 'a' ) );
		session.toggle( photo( 'b' ) );
		expect( statusText( session ) ).toBe( '2 photos selected' );

		session.progress = { done: 1, total: 2 };
		expect( statusText( session ) ).toBe( 'Importing 1 of 2 photos...' );

		await library.first();
		expect( noteText( session ) ).toBe( 'No photos found.' );

		library.state = { ...library.state, stale: true };
		expect( noteText( session ) ).toContain( 'last saved list' );
	} );
} );
