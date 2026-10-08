import { describe, expect, it, vi } from 'vitest';
import type { ImportOutcome } from '../import-screen/import-job';
import type { PhotoItem } from '../import-screen/types';
import { PhotoLibrary } from './library';
import { Session } from './session';
import { photo, response } from '../test-support/media-modal';
import type { ReadyPhoto, SelectionPort } from './types';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

function port( multiple = true, unloaded: string[] = [] ): SelectionPort & { shown: PhotoItem[][]; committed: ReadyPhoto[][] } {
	const shown: PhotoItem[][] = [];
	const committed: ReadyPhoto[][] = [];
	return {
		shown,
		committed,
		multiple: () => multiple,
		show: ( photos ) => void shown.push( photos ),
		hide: vi.fn(),
		commit: ( ready ) => {
			committed.push( ready );
			return Promise.resolve( unloaded );
		},
	};
}

function session( selection: SelectionPort, outcome: ImportOutcome = { added: {}, failed: [] } ) {
	const importer = vi.fn().mockResolvedValue( outcome );
	const library = new PhotoLibrary( vi.fn().mockResolvedValue( response( [] ) ) );
	return { session: new Session( selection, library, importer ), importer };
}

describe( 'Session picking', () => {
	it( 'toggles photos and mirrors the picks into the selection', () => {
		const selection = port();
		const { session: s } = session( selection );

		s.toggle( photo( 'a' ) );
		s.toggle( photo( 'b' ) );
		s.toggle( photo( 'a' ) );

		expect( selection.shown.map( ( list ) => list.map( ( item ) => item.sourceId ) ) ).toEqual( [ [ 'a' ], [ 'a', 'b' ], [ 'b' ] ] );
		expect( s.has( photo( 'b' ) ) ).toBe( true );
	} );

	it( 'keeps only the latest pick when the frame takes one item', () => {
		const selection = port( false );
		const { session: s } = session( selection );

		s.toggle( photo( 'a' ) );
		s.toggle( photo( 'b' ) );

		expect( s.pending().map( ( item ) => item.sourceId ) ).toEqual( [ 'b' ] );
	} );

	it( 'shows and hides the picks with the tab and forgets them on reset', () => {
		const selection = port();
		const { session: s } = session( selection );
		s.toggle( photo( 'a' ) );

		s.conceal();
		s.reveal();
		expect( selection.hide ).toHaveBeenCalledTimes( 1 );
		expect( selection.shown.at( -1 ) ).toHaveLength( 1 );

		s.reset();
		expect( s.pending() ).toEqual( [] );
		expect( selection.hide ).toHaveBeenCalledTimes( 2 );
	} );
} );

describe( 'Session confirm', () => {
	it( 'lets the frame continue when nothing is picked', async () => {
		const { session: s, importer } = session( port() );

		await expect( s.confirm() ).resolves.toBe( true );
		expect( importer ).not.toHaveBeenCalled();
	} );

	it( 'imports new photos, reuses imported ones and commits both as attachments', async () => {
		const selection = port();
		const { session: s, importer } = session( selection, { added: { new: 42 }, failed: [] } );
		s.toggle( photo( 'new' ) );
		s.toggle( photo( 'old', { id: 7 } ) );

		await expect( s.confirm() ).resolves.toBe( true );

		expect( importer.mock.calls[ 0 ]?.[ 0 ].map( ( item: PhotoItem ) => item.sourceId ) ).toEqual( [ 'new' ] );
		expect( selection.committed[ 0 ]?.map( ( item ) => [ item.photo.sourceId, item.attachmentId ] ) ).toEqual( [
			[ 'old', 7 ],
			[ 'new', 42 ],
		] );
		expect( s.pending() ).toEqual( [] );
		expect( s.busy() ).toBe( false );
	} );

	it( 'does not call the importer when every photo is already imported', async () => {
		const { session: s, importer } = session( port() );
		s.toggle( photo( 'old', { id: 7 } ) );

		await expect( s.confirm() ).resolves.toBe( true );
		expect( importer ).not.toHaveBeenCalled();
	} );

	it( 'reports progress while the import runs', async () => {
		const seen: Array< number | undefined > = [];
		const selection = port();
		const library = new PhotoLibrary( vi.fn().mockResolvedValue( response( [] ) ) );
		const importer = vi.fn().mockImplementation( ( _queue: PhotoItem[], onProgress: ( done: number ) => void ) => {
			onProgress( 1 );
			return Promise.resolve( { added: { a: 1, b: 2 }, failed: [] } );
		} );
		const s = new Session( selection, library, importer );
		s.subscribe( () => seen.push( s.progress?.done ) );
		s.toggle( photo( 'a' ) );
		s.toggle( photo( 'b' ) );
		seen.length = 0;

		await s.confirm();

		expect( seen ).toEqual( [ 0, 1, undefined ] );
	} );

	it( 'keeps failed photos picked with the reason and blocks the frame', async () => {
		const selection = port();
		const { session: s } = session( selection, {
			added: { ok: 5 },
			failed: [ { id: 'bad', title: 'Photo bad', message: 'Download failed.' } ],
		} );
		s.toggle( photo( 'ok' ) );
		s.toggle( photo( 'bad' ) );

		await expect( s.confirm() ).resolves.toBe( false );

		expect( s.failures ).toEqual( [ { id: 'bad', title: 'Photo bad', message: 'Download failed.' } ] );
		expect( s.pending().map( ( item ) => item.sourceId ) ).toEqual( [ 'bad' ] );
	} );

	it( 'treats an attachment that cannot be loaded as a failure', async () => {
		const { session: s } = session( port( true, [ 'a' ] ), { added: { a: 9 }, failed: [] } );
		s.toggle( photo( 'a' ) );

		await expect( s.confirm() ).resolves.toBe( false );

		expect( s.failures[ 0 ]?.message ).toContain( 'could not be loaded' );
		expect( s.pending() ).toHaveLength( 1 );
	} );

	it( 'survives an importer that throws', async () => {
		const selection = port();
		const library = new PhotoLibrary( vi.fn().mockResolvedValue( response( [] ) ) );
		const s = new Session( selection, library, vi.fn().mockRejectedValue( new Error( 'boom' ) ) );
		s.toggle( photo( 'a' ) );

		await expect( s.confirm() ).resolves.toBe( false );

		expect( s.failures ).toHaveLength( 1 );
		expect( s.busy() ).toBe( false );
	} );

	it( 'ignores picks while an import runs', async () => {
		let finish: ( outcome: ImportOutcome ) => void = () => undefined;
		const library = new PhotoLibrary( vi.fn().mockResolvedValue( response( [] ) ) );
		const importer = vi.fn().mockReturnValue( new Promise( ( resolve ) => ( finish = resolve ) ) );
		const s = new Session( port(), library, importer );
		s.toggle( photo( 'a' ) );

		const running = s.confirm();
		s.toggle( photo( 'b' ) );
		finish( { added: { a: 1 }, failed: [] } );
		await running;

		expect( s.pending() ).toEqual( [] );
	} );
} );
