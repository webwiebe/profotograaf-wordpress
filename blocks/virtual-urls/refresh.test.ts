import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	BATCH,
	changesFor,
	collectImages,
	createRefresher,
	currentUrl,
	isPlatformUrl,
	mediaPath,
	parseUrls,
} from './refresh';
import type { AttachmentUrls, Change, EditorBlock } from './refresh';

const PLATFORM = 'https://profotograaf.nl';
const OLD = 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg';
const NEW = 'https://profotograaf.nl/share/img/a1/web-bbbbbbbbbbbb.jpg';
const LOCAL = 'https://site.test/wp-content/uploads/2026/10/profotograaf-p-1.jpg';
const LOCAL_LARGE = 'https://site.test/wp-content/uploads/2026/10/profotograaf-p-1-1024x683.jpg';

let next = 0;
const image = ( attributes: Record< string, unknown > ): EditorBlock => ( {
	clientId: `c-${ ++next }`,
	name: 'core/image',
	attributes,
	innerBlocks: [],
} );

const gallery = ( inner: EditorBlock[] ): EditorBlock => ( {
	clientId: `g-${ ++next }`,
	name: 'core/gallery',
	attributes: {},
	innerBlocks: inner,
} );

const local = ( sizes: Record< string, string > = {} ): AttachmentUrls => ( { full: LOCAL, sizes } );

/** Lets the promise callbacks of the refresher run. */
const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

describe( 'isPlatformUrl', () => {
	it( 'matches the platform host only, over http or https', () => {
		expect( isPlatformUrl( OLD, PLATFORM ) ).toBe( true );
		expect( isPlatformUrl( 'http://PROFOTOGRAAF.nl/x.jpg', PLATFORM ) ).toBe( true );
		expect( isPlatformUrl( LOCAL, PLATFORM ) ).toBe( false );
		expect( isPlatformUrl( 'ftp://profotograaf.nl/x.jpg', PLATFORM ) ).toBe( false );
		expect( isPlatformUrl( 'not a url', PLATFORM ) ).toBe( false );
		expect( isPlatformUrl( OLD, 'nonsense' ) ).toBe( false );
	} );
} );

describe( 'collectImages', () => {
	it( 'finds Image blocks with a platform URL, the items of a Gallery block included', () => {
		const single = image( { id: 7, url: OLD, linkDestination: 'media', href: OLD } );
		const item = image( { id: 8, url: OLD, sizeSlug: 'large' } );
		const blocks = [ single, gallery( [ item, image( { id: 9, url: LOCAL } ) ] ) ];

		expect( collectImages( blocks, PLATFORM ) ).toEqual( [
			{ clientId: single.clientId, id: 7, url: OLD, sizeSlug: 'full', href: OLD, linkDestination: 'media' },
			{ clientId: item.clientId, id: 8, url: OLD, sizeSlug: 'large', href: '', linkDestination: '' },
		] );
	} );

	it( 'skips blocks without an attachment id and blocks of other types', () => {
		const blocks: EditorBlock[] = [
			image( { url: OLD } ),
			image( { id: 0, url: OLD } ),
			image( { id: '7', url: OLD } ),
			{ clientId: 'p', name: 'core/paragraph', attributes: { id: 7, url: OLD } },
		];

		expect( collectImages( blocks, PLATFORM ) ).toEqual( [] );
	} );
} );

describe( 'currentUrl', () => {
	it( 'picks the size of the block and falls back to full', () => {
		const urls = local( { large: LOCAL_LARGE } );

		expect( currentUrl( urls, 'large' ) ).toBe( LOCAL_LARGE );
		expect( currentUrl( urls, 'medium' ) ).toBe( LOCAL );
		expect( currentUrl( urls, 'full' ) ).toBe( LOCAL );
		expect( currentUrl( urls, 'constructor' ) ).toBe( LOCAL );
	} );
} );

describe( 'changesFor', () => {
	it( 'updates the url, and the href of a link to the media file', () => {
		const images = collectImages(
			[
				image( { id: 7, url: OLD, linkDestination: 'media', href: OLD } ),
				image( { id: 8, url: OLD, sizeSlug: 'large', linkDestination: 'custom', href: OLD } ),
			],
			PLATFORM
		);
		const known = new Map( [
			[ 7, local() ],
			[ 8, local( { large: LOCAL_LARGE } ) ],
		] );

		expect( changesFor( images, known ) ).toEqual( [
			{ clientId: images[ 0 ]?.clientId, attributes: { url: LOCAL, href: LOCAL } },
			{ clientId: images[ 1 ]?.clientId, attributes: { url: LOCAL_LARGE } },
		] );
	} );

	it( 'leaves current, unknown and null attachments alone', () => {
		const images = collectImages(
			[ image( { id: 7, url: NEW } ), image( { id: 8, url: OLD } ), image( { id: 9, url: OLD } ), image( { id: 10, url: OLD } ) ],
			PLATFORM
		);
		const known = new Map< number, AttachmentUrls | null >( [
			[ 7, { full: NEW, sizes: {} } ],
			[ 9, null ],
			[ 10, { full: '', sizes: {} } ],
		] );

		expect( changesFor( images, known ) ).toEqual( [] );
	} );
} );

describe( 'parseUrls', () => {
	it( 'reads the REST field and marks every id it did not get as null', () => {
		const body = [
			{ id: 7, profotograaf_urls: { full: LOCAL, sizes: { large: LOCAL_LARGE, bad: 3 } } },
			{ id: 8, profotograaf_urls: null },
			{ id: 9, profotograaf_urls: { full: '' } },
			{ id: 10, profotograaf_urls: { full: NEW, sizes: 'x' } },
			{ id: 99, profotograaf_urls: { full: LOCAL } },
			{ id: '11' },
			null,
			'x',
		];

		const known = parseUrls( [ 7, 8, 9, 10, 11, 12 ], body );

		expect( [ ...known.entries() ] ).toEqual( [
			[ 7, { full: LOCAL, sizes: { large: LOCAL_LARGE } } ],
			[ 8, null ],
			[ 9, null ],
			[ 10, { full: NEW, sizes: {} } ],
			[ 11, null ],
			[ 12, null ],
		] );
	} );

	it( 'treats a malformed response as no answer', () => {
		expect( [ ...parseUrls( [ 7 ], { code: 'oops' } ).entries() ] ).toEqual( [ [ 7, null ] ] );
	} );
} );

describe( 'mediaPath', () => {
	it( 'asks the media route for the field of the given attachments only', () => {
		expect( mediaPath( [ 7, 8 ] ) ).toBe( '/wp/v2/media?include=7,8&per_page=100&_fields=id,profotograaf_urls' );
	} );
} );

describe( 'createRefresher', () => {
	let blocks: EditorBlock[];
	let updates: Change[];

	beforeEach( () => {
		blocks = [];
		updates = [];
		vi.spyOn( console, 'warn' ).mockImplementation( () => undefined );
	} );
	afterEach( () => {
		vi.restoreAllMocks();
	} );

	const build = ( fetchUrls: ( ids: number[] ) => Promise< Map< number, AttachmentUrls | null > > ) =>
		createRefresher( {
			platformUrl: PLATFORM,
			getBlocks: () => blocks,
			update: ( change ) => {
				updates.push( change );
				// The store answers an update with a new block list.
				blocks = blocks.map( function apply( block ): EditorBlock {
					return {
						...block,
						attributes: block.clientId === change.clientId ? { ...block.attributes, ...change.attributes } : block.attributes,
						innerBlocks: ( block.innerBlocks ?? [] ).map( apply ),
					};
				} );
			},
			fetchUrls,
		} );

	it( 'asks once per attachment and updates the Image block and the gallery items', async () => {
		const single = image( { id: 7, url: OLD } );
		const item = image( { id: 8, url: OLD, sizeSlug: 'large' } );
		const again = image( { id: 7, url: OLD } );
		blocks = [ single, gallery( [ item, again ] ) ];
		const fetchUrls = vi.fn( async ( ids: number[] ) => new Map( ids.map( ( id ) => [ id, local( { large: LOCAL_LARGE } ) ] ) ) );
		const run = build( fetchUrls );

		run();
		run();
		await settle();

		expect( fetchUrls ).toHaveBeenCalledTimes( 1 );
		expect( fetchUrls ).toHaveBeenCalledWith( [ 7, 8 ] );
		expect( updates ).toEqual( [
			{ clientId: single.clientId, attributes: { url: LOCAL } },
			{ clientId: item.clientId, attributes: { url: LOCAL_LARGE } },
			{ clientId: again.clientId, attributes: { url: LOCAL } },
		] );

		// The updated blocks hold local URLs, so nothing is asked or changed again.
		run();
		await settle();
		expect( fetchUrls ).toHaveBeenCalledTimes( 1 );
		expect( updates ).toHaveLength( 3 );
	} );

	it( 'updates a block added later from what it already knows', async () => {
		blocks = [ image( { id: 7, url: OLD } ) ];
		const fetchUrls = vi.fn( async () => new Map( [ [ 7, { full: NEW, sizes: {} } ] ] ) );
		const run = build( fetchUrls );
		run();
		await settle();

		const added = image( { id: 7, url: OLD } );
		blocks = [ ...blocks, added ];
		run();

		expect( fetchUrls ).toHaveBeenCalledTimes( 1 );
		expect( updates.at( -1 ) ).toEqual( { clientId: added.clientId, attributes: { url: NEW } } );
	} );

	it( 'asks in batches of at most one page', async () => {
		blocks = Array.from( { length: BATCH + 1 }, ( _, index ) => image( { id: index + 1, url: OLD } ) );
		const fetchUrls = vi.fn( async ( _ids: number[] ) => new Map< number, AttachmentUrls | null >() );
		const run = build( fetchUrls );

		run();
		await settle();

		expect( fetchUrls ).toHaveBeenCalledTimes( 2 );
		expect( fetchUrls.mock.calls[ 0 ]?.[ 0 ] ).toHaveLength( BATCH );
		expect( fetchUrls.mock.calls[ 1 ]?.[ 0 ] ).toEqual( [ BATCH + 1 ] );
	} );

	it( 'leaves the blocks alone and does not ask again when the request fails', async () => {
		blocks = [ image( { id: 7, url: OLD } ) ];
		const fetchUrls = vi.fn( async () => Promise.reject( new Error( 'offline' ) ) );
		const run = build( fetchUrls );

		run();
		await settle();
		blocks = [ ...blocks ];
		run();
		await settle();

		expect( fetchUrls ).toHaveBeenCalledTimes( 1 );
		expect( updates ).toEqual( [] );
		expect( console.warn ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'makes no request for a post without Profotograaf images', async () => {
		blocks = [ image( { id: 7, url: LOCAL } ) ];
		const fetchUrls = vi.fn();
		const run = build( fetchUrls );

		run();
		await settle();

		expect( fetchUrls ).not.toHaveBeenCalled();
	} );
} );
