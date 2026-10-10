import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// The watcher is the string that Empty_Gallery::script() returns. Its source
// is read from the PHP file, the constants are filled in and the string
// concatenation is evaluated, so this test runs the script that ships.
function watcherSource(): string {
	const php = readFileSync( resolve( process.cwd(), 'includes/class-empty-gallery.php' ), 'utf8' );
	const constant = ( name: string ) => Number( new RegExp( `const ${ name } = (\\d+);` ).exec( php )?.[ 1 ] );
	const body = /public static function script\(\): string \{\s*return ([\s\S]*?);\s*\}\s*\}\s*$/.exec( php )?.[ 1 ];
	if ( ! body ) {
		throw new Error( 'Empty_Gallery::script() was not found' );
	}
	const js = body
		.replace( /self::TICKS/g, String( constant( 'TICKS' ) ) )
		.replace( /self::TICK/g, String( constant( 'TICK' ) ) )
		.replace( /\s+\.\s+/g, ' + ' );
	// oxlint-disable-next-line typescript/no-implied-eval -- evaluates the string literals of the PHP source into the watcher string
	return new Function( `return ${ js };` )() as string;
}

const TICK = 250;

function addHost( attrs: Record<string, string> = {}, inner = '<a href="https://example.test/g">View gallery</a><p data-pf-hint hidden>hint</p>' ): HTMLElement {
	const host = document.createElement( 'div' );
	host.setAttribute( 'data-profotograaf-gallery', 'g-1' );
	for ( const [ name, value ] of Object.entries( attrs ) ) {
		host.setAttribute( name, value );
	}
	host.innerHTML = inner;
	document.body.append( host );
	return host;
}

function state( host: HTMLElement, value: string ): void {
	host.setAttribute( 'data-pf-state', value );
	host.dispatchEvent( new CustomEvent( 'profotograaf:state', { bubbles: true, detail: { state: value } } ) );
}

// oxlint-disable-next-line typescript/no-implied-eval -- runs the watcher string that PHP ships, in the jsdom window
const run = () => new Function( watcherSource() )();

describe( 'the empty gallery watcher', () => {
	beforeEach( () => {
		vi.useFakeTimers();
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		vi.useRealTimers();
		vi.restoreAllMocks();
	} );

	it( 'marks a host empty from the state event at once and shows the editor hint', () => {
		const host = addHost( { 'data-pf-ready': '', 'data-profotograaf-failed': '' } );
		run();

		state( host, 'empty' );

		expect( host.hasAttribute( 'data-pf-empty' ) ).toBe( true );
		expect( host.hasAttribute( 'data-profotograaf-failed' ) ).toBe( false );
		expect( host.querySelector< HTMLElement >( '[data-pf-hint]' )?.hidden ).toBe( false );
	} );

	it( 'shows the failed look at once on the error state', () => {
		const host = addHost( { 'data-pf-ready': '' } );
		run();

		state( host, 'error' );

		expect( host.hasAttribute( 'data-profotograaf-failed' ) ).toBe( true );
		expect( host.hasAttribute( 'data-pf-empty' ) ).toBe( false );
		expect( host.querySelector( 'a' ) ).not.toBeNull();
	} );

	it( 'leaves a drawn host alone', () => {
		const host = addHost( { 'data-pf-ready': '' } );
		run();

		state( host, 'drawn' );

		expect( host.hasAttribute( 'data-pf-empty' ) ).toBe( false );
		expect( host.hasAttribute( 'data-profotograaf-failed' ) ).toBe( false );
	} );

	it( 'reads a state that was set before the watcher ran', () => {
		const empty = addHost( { 'data-pf-ready': '', 'data-pf-state': 'empty' } );
		const failed = addHost( { 'data-pf-ready': '', 'data-pf-state': 'error' } );

		run();

		expect( empty.hasAttribute( 'data-pf-empty' ) ).toBe( true );
		expect( failed.hasAttribute( 'data-profotograaf-failed' ) ).toBe( true );
	} );

	it( 'keeps the empty look when the renderer already marked the host and an error follows', () => {
		const host = addHost( { 'data-pf-empty': '' } );
		run();

		state( host, 'error' );

		expect( host.hasAttribute( 'data-profotograaf-failed' ) ).toBe( false );
	} );

	it( 'ignores the event of an element that is not a gallery host', () => {
		addHost( { 'data-pf-ready': '' } );
		run();
		const other = document.createElement( 'div' );
		document.body.append( other );

		state( other, 'error' );

		expect( other.hasAttribute( 'data-profotograaf-failed' ) ).toBe( false );
	} );

	describe( 'for an embed.js that sets no state', () => {
		const answered = () =>
			vi.spyOn( performance, 'getEntriesByType' ).mockReturnValue( [
				{ name: 'https://platform.test/api/v1/embed/galleries/g-1', responseEnd: 10, responseStatus: 200 },
			] as unknown as PerformanceEntryList );

		it( 'marks a ready host without a shadow root once its request finished, on the second look', () => {
			answered();
			const host = addHost( { 'data-pf-ready': '' } );

			run();
			expect( host.hasAttribute( 'data-pf-empty' ) ).toBe( false );
			vi.advanceTimersByTime( TICK );

			expect( host.hasAttribute( 'data-pf-empty' ) ).toBe( true );
		} );

		it( 'does not mark a host that is not ready yet', () => {
			answered();
			const host = addHost();

			run();
			vi.advanceTimersByTime( TICK * 5 );

			expect( host.hasAttribute( 'data-pf-empty' ) ).toBe( false );
		} );

		it( 'does not mark a host whose request is still open', () => {
			vi.spyOn( performance, 'getEntriesByType' ).mockReturnValue( [] );
			const host = addHost( { 'data-pf-ready': '' } );

			run();
			vi.advanceTimersByTime( TICK * 5 );

			expect( host.hasAttribute( 'data-pf-empty' ) ).toBe( false );
		} );

		it( 'does not mark a host that has a shadow root', () => {
			answered();
			const host = addHost( { 'data-pf-ready': '' } );
			host.attachShadow( { mode: 'open' } );

			run();
			vi.advanceTimersByTime( TICK * 5 );

			expect( host.hasAttribute( 'data-pf-empty' ) ).toBe( false );
		} );
	} );
} );
