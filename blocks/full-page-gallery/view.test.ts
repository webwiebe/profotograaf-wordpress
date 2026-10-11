import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( './style.css', () => ( {} ) );
vi.mock( '@wordpress/i18n', () => ( { __: ( text: string ) => text } ) );

const PHOTOS = [ 0, 1, 2, 3 ].map( ( n ) => ( {
	src: `p${ n }.jpg`,
	srcset: `p${ n }.jpg 1600w`,
	w: 1600,
	h: 1000,
	alt: `alt ${ n }`,
	caption: n === 2 ? '' : `cap ${ n }`,
} ) );

/**
 * Build the markup contract of a gallery.
 *
 * @param lightbox Whether the lightbox is on.
 */
function build( lightbox: boolean ): void {
	const link = ( n: number ) =>
		`<figure class="pf-fp-photo"><a class="pf-fp-open" href="/photo/${ n }"${ lightbox ? ` data-pf-index="${ n }"` : '' }><img src="p${ n }.jpg" alt=""></a></figure>`;
	const data = { lightbox, photos: PHOTOS, beacon: { url: 'https://profotograaf.nl/share/embed/view', gallery: 'g1' } };
	document.body.innerHTML = `<div class="pf-fp" data-pf-full-page="g1">
		<section class="pf-fp-s pf-fp-grid">${ link( 0 ) }${ link( 1 ) }</section>
		<section class="pf-fp-s pf-fp-filmstrip" tabindex="0"><div class="pf-fp-track">${ link( 2 ) }${ link( 3 ) }</div></section>
		<script type="application/json" class="pf-fp-data">${ JSON.stringify( data ) }</script>
	</div>`;
}

/** Load view.ts fresh so it scans the current DOM. */
async function load(): Promise< void > {
	vi.resetModules();
	await import( './view' );
}

/**
 * Click the photo link at an index.
 *
 * @param n Photo index.
 */
function open( n: number ): { link: HTMLElement; event: MouseEvent } {
	const link = document.querySelectorAll< HTMLElement >( 'a.pf-fp-open' )[ n ]!;
	const event = new MouseEvent( 'click', { bubbles: true, cancelable: true } );
	link.focus();
	link.dispatchEvent( event );
	return { link, event };
}

/** The lightbox image source, or null when closed. */
function shown(): string | null {
	return document.querySelector( '.pf-fp-lightbox .pf-fp-lb-img' )?.getAttribute( 'src' ) ?? null;
}

/**
 * Press a key on the document.
 *
 * @param key   Key name.
 * @param shift Whether shift is held.
 */
function press( key: string, shift = false ): KeyboardEvent {
	const event = new KeyboardEvent( 'keydown', { key, shiftKey: shift, bubbles: true, cancelable: true } );
	( document.activeElement ?? document.body ).dispatchEvent( event );
	return event;
}

/**
 * Fire a touch gesture from one x to another.
 *
 * @param from Start x.
 * @param to   End x.
 */
function swipe( from: number, to: number ): void {
	const overlay = document.querySelector( '.pf-fp-lightbox' )!;
	const touch = ( type: string, x: number ) => {
		const event = new Event( type, { bubbles: true } );
		Object.defineProperty( event, 'changedTouches', { value: [ { clientX: x } ] } );
		overlay.dispatchEvent( event );
	};
	touch( 'touchstart', from );
	touch( 'touchend', to );
}

beforeEach( () => {
	document.body.removeAttribute( 'style' );
	delete ( navigator as { doNotTrack?: string } ).doNotTrack;
} );

afterEach( () => {
	document.body.innerHTML = '';
	vi.unstubAllGlobals();
	vi.restoreAllMocks();
} );

describe( 'lightbox', () => {
	it( 'opens at the clicked index and builds the dialog', async () => {
		build( true );
		await load();
		const { event } = open( 2 );
		expect( event.defaultPrevented ).toBe( true );
		const dialog = document.querySelector( '.pf-fp-lightbox' )!;
		expect( dialog.getAttribute( 'role' ) ).toBe( 'dialog' );
		expect( dialog.getAttribute( 'aria-modal' ) ).toBe( 'true' );
		expect( dialog.getAttribute( 'aria-label' ) ).toBe( 'Photo viewer' );
		expect( shown() ).toBe( 'p2.jpg' );
		expect( dialog.querySelector( '.pf-fp-lb-img' )?.getAttribute( 'sizes' ) ).toBe( '100vw' );
		expect( dialog.querySelector( '.pf-fp-lb-img' )?.getAttribute( 'alt' ) ).toBe( 'alt 2' );
		expect( document.body.style.overflow ).toBe( 'hidden' );
		expect( document.activeElement?.className ).toBe( 'pf-fp-lb-close' );
	} );

	it( 'steps across sections and stops at the ends', async () => {
		build( true );
		await load();
		open( 0 );
		const prev = document.querySelector< HTMLButtonElement >( '.pf-fp-lb-prev' )!;
		const next = document.querySelector< HTMLButtonElement >( '.pf-fp-lb-next' )!;
		expect( prev.disabled ).toBe( true );
		press( 'ArrowLeft' );
		expect( shown() ).toBe( 'p0.jpg' );
		next.click();
		next.click();
		expect( shown() ).toBe( 'p2.jpg' );
		expect( document.querySelector( '.pf-fp-lb-caption' )?.textContent ).toBe( '' );
		press( 'ArrowRight' );
		expect( shown() ).toBe( 'p3.jpg' );
		expect( next.disabled ).toBe( true );
		press( 'ArrowRight' );
		expect( shown() ).toBe( 'p3.jpg' );
		expect( document.querySelector( '.pf-fp-lb-caption' )?.textContent ).toBe( 'cap 3' );
	} );

	it( 'closes on Escape, restores scroll and returns focus', async () => {
		build( true );
		document.body.style.overflow = 'scroll';
		await load();
		const { link } = open( 1 );
		press( 'Escape' );
		expect( shown() ).toBeNull();
		expect( document.body.style.overflow ).toBe( 'scroll' );
		expect( document.activeElement ).toBe( link );
	} );

	it( 'closes with the close button', async () => {
		build( true );
		await load();
		open( 1 );
		document.querySelector< HTMLButtonElement >( '.pf-fp-lb-close' )!.click();
		expect( shown() ).toBeNull();
	} );

	it( 'traps Tab inside the dialog', async () => {
		build( true );
		await load();
		open( 1 );
		const close = document.querySelector< HTMLButtonElement >( '.pf-fp-lb-close' )!;
		const prev = document.querySelector< HTMLButtonElement >( '.pf-fp-lb-prev' )!;
		close.focus();
		expect( press( 'Tab' ).defaultPrevented ).toBe( true );
		expect( document.activeElement ).toBe( prev );
		expect( press( 'Tab', true ).defaultPrevented ).toBe( true );
		expect( document.activeElement ).toBe( close );
	} );

	it( 'steps on a horizontal swipe of 40px or more', async () => {
		build( true );
		await load();
		open( 1 );
		swipe( 200, 100 );
		expect( shown() ).toBe( 'p2.jpg' );
		swipe( 100, 200 );
		expect( shown() ).toBe( 'p1.jpg' );
		swipe( 100, 130 );
		expect( shown() ).toBe( 'p1.jpg' );
	} );

	it( 'leaves clicks alone when the lightbox is off', async () => {
		build( false );
		await load();
		const { event } = open( 0 );
		expect( event.defaultPrevented ).toBe( false );
		expect( shown() ).toBeNull();
	} );
} );

describe( 'filmstrip', () => {
	let reduced = false;

	beforeEach( () => {
		reduced = false;
		vi.stubGlobal( 'matchMedia', ( query: string ) => ( { matches: reduced && query.includes( 'reduce' ) } ) );
	} );

	/** Make the track look wider than it is. */
	function stubTrack(): { track: HTMLElement; scrollBy: ReturnType< typeof vi.fn > } {
		const track = document.querySelector< HTMLElement >( '.pf-fp-track' )!;
		const scrollBy = vi.fn();
		Object.defineProperty( track, 'scrollWidth', { value: 1000, configurable: true } );
		Object.defineProperty( track, 'clientWidth', { value: 400, configurable: true } );
		track.scrollBy = scrollBy;
		return { track, scrollBy };
	}

	it( 'adds buttons that scroll and hide at the ends', async () => {
		build( true );
		const stub = stubTrack();
		stub.track.scrollLeft = 0;
		await load();
		const prev = document.querySelector< HTMLButtonElement >( '.pf-fp-strip-prev' )!;
		const next = document.querySelector< HTMLButtonElement >( '.pf-fp-strip-next' )!;
		expect( prev.getAttribute( 'aria-label' ) ).toBe( 'Scroll back' );
		expect( prev.hidden ).toBe( true );
		expect( next.hidden ).toBe( false );
		next.click();
		expect( stub.scrollBy ).toHaveBeenCalledWith( expect.objectContaining( { left: 360 } ) );
		stub.track.scrollLeft = 600;
		stub.track.dispatchEvent( new Event( 'scroll' ) );
		expect( prev.hidden ).toBe( false );
		expect( next.hidden ).toBe( true );
		expect( next.disabled ).toBe( true );
		prev.click();
		expect( stub.scrollBy ).toHaveBeenLastCalledWith( expect.objectContaining( { left: -360 } ) );
	} );

	it( 'scrolls with the arrow keys on the section', async () => {
		build( true );
		const stub = stubTrack();
		await load();
		const section = document.querySelector< HTMLElement >( 'section.pf-fp-filmstrip' )!;
		section.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowRight', bubbles: true } ) );
		expect( stub.scrollBy ).toHaveBeenCalledTimes( 1 );
		section.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowLeft', bubbles: true } ) );
		expect( stub.scrollBy ).toHaveBeenLastCalledWith( expect.objectContaining( { left: -360 } ) );
	} );

	it( 'scrolls without animation when the visitor asks for reduced motion', async () => {
		build( true );
		const stub = stubTrack();
		reduced = true;
		await load();
		document.querySelector< HTMLButtonElement >( '.pf-fp-strip-next' )!.click();
		expect( stub.scrollBy ).toHaveBeenCalledWith( expect.objectContaining( { behavior: 'auto' } ) );
	} );
} );

describe( 'view beacon', () => {
	/** Stub IntersectionObserver and return a function that reveals the gallery. */
	function stubObserver(): () => void {
		let callback: IntersectionObserverCallback = () => undefined;
		const disconnect = vi.fn();
		vi.stubGlobal( 'IntersectionObserver', class {
			constructor( cb: IntersectionObserverCallback ) {
				callback = cb;
			}
			observe = vi.fn();
			disconnect = disconnect;
		} );
		return () => callback( [ { isIntersecting: true } as IntersectionObserverEntry ], {} as IntersectionObserver );
	}

	it( 'sends once when the gallery first becomes visible', async () => {
		const send = vi.fn( () => true );
		vi.stubGlobal( 'navigator', Object.assign( Object.create( navigator ), { sendBeacon: send } ) );
		const reveal = stubObserver();
		build( true );
		await load();
		expect( send ).not.toHaveBeenCalled();
		reveal();
		reveal();
		expect( send ).toHaveBeenCalledTimes( 1 );
		const calls = send.mock.calls as unknown as [ string, Blob ][];
		const [ url, blob ] = calls[ 0 ]!;
		expect( url ).toBe( 'https://profotograaf.nl/share/embed/view' );
		expect( blob.type ).toBe( 'application/x-www-form-urlencoded' );
		expect( await blob.text() ).toBe( `gallery=g1&source=embed&origin=${ encodeURIComponent( location.origin ) }` );
	} );

	it( 'sends on init without IntersectionObserver', async () => {
		const send = vi.fn( () => true );
		vi.stubGlobal( 'IntersectionObserver', undefined );
		vi.stubGlobal( 'navigator', Object.assign( Object.create( navigator ), { sendBeacon: send } ) );
		build( true );
		await load();
		expect( send ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'is skipped under Do Not Track and Global Privacy Control', async () => {
		const send = vi.fn( () => true );
		vi.stubGlobal( 'IntersectionObserver', undefined );
		vi.stubGlobal( 'navigator', Object.assign( Object.create( navigator ), { sendBeacon: send, doNotTrack: '1' } ) );
		build( true );
		await load();
		vi.stubGlobal( 'navigator', Object.assign( Object.create( navigator ), { sendBeacon: send, doNotTrack: '0', globalPrivacyControl: true } ) );
		await load();
		expect( send ).not.toHaveBeenCalled();
	} );

	it( 'falls back to fetch with keepalive', async () => {
		const fetchMock = vi.fn( () => Promise.resolve( new Response() ) );
		vi.stubGlobal( 'fetch', fetchMock );
		vi.stubGlobal( 'IntersectionObserver', undefined );
		vi.stubGlobal( 'navigator', Object.assign( Object.create( navigator ), { sendBeacon: () => false } ) );
		build( true );
		await load();
		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
		const [ url, init ] = ( fetchMock.mock.calls as unknown as [ string, RequestInit ][] )[ 0 ]!;
		expect( url ).toBe( 'https://profotograaf.nl/share/embed/view' );
		expect( init.keepalive ).toBe( true );
		expect( init.mode ).toBe( 'no-cors' );
		expect( init.method ).toBe( 'POST' );
	} );
} );
