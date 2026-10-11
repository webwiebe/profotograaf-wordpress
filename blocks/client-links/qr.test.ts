import { describe, expect, it } from 'vitest';
import { modulesPath, qrModules, qrSvg } from './qr';

const LINK = 'https://profotograaf.nl/share/g/spring-wedding';

/** The 7 by 7 finder pattern: dark ring, light ring, dark 3 by 3 centre. */
function isFinder( modules: boolean[][], top: number, left: number ): boolean {
	for ( let r = 0; r < 7; r++ ) {
		for ( let c = 0; c < 7; c++ ) {
			const ring = 0 === r || 6 === r || 0 === c || 6 === c;
			const centre = r >= 2 && r <= 4 && c >= 2 && c <= 4;
			if ( modules[ top + r ]?.[ left + c ] !== ( ring || centre ) ) {
				return false;
			}
		}
	}
	return true;
}

describe( 'qrModules', () => {
	it( 'encodes a share link in a version 4 symbol at level M', () => {
		const modules = qrModules( LINK );

		// 46 bytes need version 4 at level M: 17 + 4 * 4 = 33 modules a side.
		expect( modules ).toHaveLength( 33 );
		expect( modules.every( ( row ) => 33 === row.length ) ).toBe( true );
	} );

	it( 'places the three finder patterns', () => {
		const modules = qrModules( LINK );
		const end = modules.length - 7;

		expect( isFinder( modules, 0, 0 ) ).toBe( true );
		expect( isFinder( modules, 0, end ) ).toBe( true );
		expect( isFinder( modules, end, 0 ) ).toBe( true );
		expect( isFinder( modules, end, end ) ).toBe( false );
	} );

	it( 'gives different codes for different links', () => {
		expect( qrModules( LINK ) ).not.toEqual( qrModules( `${ LINK }-2` ) );
	} );
} );

describe( 'modulesPath', () => {
	it( 'draws one square per dark module inside the quiet zone', () => {
		expect(
			modulesPath( [
				[ true, false ],
				[ false, true ],
			] )
		).toBe( 'M4 4h1v1h-1zM5 5h1v1h-1z' );
	} );

	it( 'is empty without dark modules', () => {
		expect( modulesPath( [ [ false ] ] ) ).toBe( '' );
	} );
} );

describe( 'qrSvg', () => {
	it( 'returns a labelled image with a white background and the quiet zone', () => {
		const svg = qrSvg( document, LINK, 'QR code with the share link of Spring wedding' );

		expect( svg.getAttribute( 'role' ) ).toBe( 'img' );
		expect( svg.getAttribute( 'aria-label' ) ).toBe( 'QR code with the share link of Spring wedding' );
		expect( svg.getAttribute( 'viewBox' ) ).toBe( '0 0 41 41' );
		expect( svg.querySelector( 'rect' )?.getAttribute( 'fill' ) ).toBe( '#fff' );
		expect( svg.querySelector( 'path' )?.getAttribute( 'fill' ) ).toBe( '#000' );
		expect( svg.querySelector( 'path' )?.getAttribute( 'd' ) ).toMatch( /^M4 4h1v1h-1z/ );
	} );
} );
