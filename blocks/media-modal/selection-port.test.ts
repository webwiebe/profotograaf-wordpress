import { describe, expect, it, vi } from 'vitest';
import { photo } from '../test-support/media-modal';
import { createSelectionPort } from './selection-port';
import type { AttachmentModel, FrameLike, MediaGlobal } from './types';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

/** A selection that keeps models like Backbone does: one per id. */
function selection( multiple: boolean | string = true ) {
	const models = new Map< string, AttachmentModel >();
	return {
		models,
		multiple,
		add( added: AttachmentModel | AttachmentModel[] ) {
			for ( const model of Array.isArray( added ) ? added : [ added ] ) {
				if ( ! multiple ) {
					models.clear();
				}
				models.set( String( model.id ), model );
			}
		},
		remove( removed: AttachmentModel | AttachmentModel[] | undefined ) {
			for ( const model of Array.isArray( removed ) ? removed : removed ? [ removed ] : [] ) {
				models.delete( String( model.id ) );
			}
		},
	};
}

function setup( fetchFails: number[] = [], current: ReturnType< typeof selection > | null = selection() ) {
	const fetched: number[] = [];
	const media = {
		model: { Attachment: class { constructor( public attributes: Record< string, unknown > ) {} get id() { return this.attributes.id as string; } } },
		attachment: ( id: number ): AttachmentModel => ( {
			id,
			fetch: () => {
				fetched.push( id );
				return fetchFails.includes( id ) ? Promise.reject( new Error( 'gone' ) ) : Promise.resolve( {} );
			},
		} ),
	} as unknown as MediaGlobal;
	const frame = { state: () => ( { get: () => current ?? undefined } ) } as unknown as FrameLike;
	return { port: createSelectionPort( frame, media ), media, current, fetched };
}

describe( 'selection port', () => {
	it( 'reports whether the selection takes several items', () => {
		expect( setup().port.multiple() ).toBe( true );
		expect( setup( [], selection( false ) ).port.multiple() ).toBe( false );
		expect( setup( [], null ).port.multiple() ).toBe( false );
	} );

	it( 'puts a stand-in attachment per picked photo into the selection', () => {
		const { port, current } = setup();

		port.show( [ photo( 'a' ), photo( 'b' ) ] );
		port.show( [ photo( 'b' ) ] );

		expect( [ ...( current?.models.keys() ?? [] ) ] ).toEqual( [ 'profotograaf:b' ] );
	} );

	it( 'removes the stand-ins on hide', () => {
		const { port, current } = setup();
		port.show( [ photo( 'a' ) ] );

		port.hide();

		expect( current?.models.size ).toBe( 0 );
	} );

	it( 'does nothing without a selection', () => {
		const { port } = setup( [], null );

		expect( () => port.show( [ photo( 'a' ) ] ) ).not.toThrow();
		expect( () => port.hide() ).not.toThrow();
	} );

	it( 'swaps stand-ins for loaded attachments and reports the ones that failed to load', async () => {
		const { port, current, fetched } = setup( [ 8 ] );
		port.show( [ photo( 'a' ), photo( 'b' ) ] );

		const failed = await port.commit( [
			{ photo: photo( 'a' ), attachmentId: 7 },
			{ photo: photo( 'b' ), attachmentId: 8 },
		] );

		expect( fetched ).toEqual( [ 7, 8 ] );
		expect( failed ).toEqual( [ 'b' ] );
		expect( [ ...( current?.models.keys() ?? [] ) ].sort() ).toEqual( [ '7', 'profotograaf:b' ] );
	} );
} );
