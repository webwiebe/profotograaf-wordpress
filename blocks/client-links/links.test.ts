import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/i18n', () => ( { __: ( text: string ) => text } ) );

import { copyLink } from './copy';
import { install, installRow } from './links';

const LINK = 'https://profotograaf.nl/share/g/spring-wedding';

function row( url = LINK ): HTMLElement {
	const tr = document.createElement( 'div' );
	tr.className = 'profotograaf-client-link';
	tr.dataset.url = url;
	tr.innerHTML = `
		<input class="profotograaf-client-link__url" value="${ url }" readonly />
		<button type="button" class="profotograaf-client-link__copy" hidden>Copy link</button>
		<span class="profotograaf-client-link__status" role="status"></span>
		<div class="profotograaf-client-link__qr" data-label="QR code with the share link of Spring wedding"></div>`;
	document.body.appendChild( tr );
	return tr;
}

function part< T extends Element >( element: HTMLElement, name: string ): T {
	const found = element.querySelector< T >( `.profotograaf-client-link__${ name }` );
	if ( ! found ) {
		throw new Error( `missing ${ name }` );
	}
	return found;
}

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'installRow', () => {
	it( 'draws the QR code and shows the copy button', () => {
		const element = row();

		installRow( element, undefined );

		expect( part( element, 'qr' ).querySelector( 'svg' )?.getAttribute( 'aria-label' ) ).toBe(
			'QR code with the share link of Spring wedding'
		);
		expect( part< HTMLButtonElement >( element, 'copy' ).hidden ).toBe( false );
	} );

	it( 'leaves a row without a link alone', () => {
		const element = row( '' );

		installRow( element, undefined );

		expect( part( element, 'qr' ).children ).toHaveLength( 0 );
		expect( part< HTMLButtonElement >( element, 'copy' ).hidden ).toBe( true );
	} );

	it( 'copies the link when the button is clicked', async () => {
		const element = row();
		const writeText = vi.fn().mockResolvedValue( undefined );
		installRow( element, { writeText } );

		part< HTMLButtonElement >( element, 'copy' ).click();
		await vi.waitFor( () => expect( part( element, 'status' ).textContent ).toBe( 'Link copied.' ) );

		expect( writeText ).toHaveBeenCalledWith( LINK );
	} );

	it( 'skips a row without a data-url attribute and a row without a button', () => {
		const bare = document.createElement( 'div' );
		bare.innerHTML = '<div class="profotograaf-client-link__qr"></div>';
		installRow( bare, undefined );
		expect( part( bare, 'qr' ).children ).toHaveLength( 0 );

		const element = row();
		part( element, 'copy' ).remove();
		delete part< HTMLElement >( element, 'qr' ).dataset.label;
		installRow( element, undefined );
		expect( part( element, 'qr' ).querySelector( 'svg' )?.getAttribute( 'aria-label' ) ).toBe( '' );
	} );

	it( 'keeps working in a row without a QR cell', () => {
		const element = row();
		part( element, 'qr' ).remove();

		installRow( element, undefined );

		expect( part< HTMLButtonElement >( element, 'copy' ).hidden ).toBe( false );
	} );
} );

describe( 'install', () => {
	it( 'fills in every row', () => {
		row();
		row( `${ LINK }-2` );

		install( document, undefined );

		expect( document.querySelectorAll( '.profotograaf-client-link__qr svg' ) ).toHaveLength( 2 );
	} );
} );

describe( 'copyLink', () => {
	it( 'selects the link when there is no clipboard', async () => {
		const element = row();
		const field = part< HTMLInputElement >( element, 'url' );

		await copyLink( field, part( element, 'status' ), undefined );

		expect( document.activeElement ).toBe( field );
		expect( field.selectionEnd ).toBe( LINK.length );
		expect( part( element, 'status' ).textContent ).toContain( 'The link is selected' );
	} );

	it( 'selects the link when the browser refuses', async () => {
		const element = row();
		const writeText = vi.fn().mockRejectedValue( new Error( 'denied' ) );

		await copyLink( part( element, 'url' ), part( element, 'status' ), { writeText } );

		expect( part( element, 'status' ).textContent ).toContain( 'The link is selected' );
	} );
} );
