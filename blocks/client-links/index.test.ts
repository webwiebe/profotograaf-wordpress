import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/i18n', () => ( { __: ( text: string ) => text } ) );

describe( 'client links entry', () => {
	afterEach( () => {
		vi.resetModules();
		document.body.innerHTML = '';
	} );

	it( 'fills in the rows on the page', async () => {
		document.body.innerHTML = `
			<div class="profotograaf-client-link" data-url="https://profotograaf.nl/share/g/a">
				<div class="profotograaf-client-link__qr" data-label="QR"></div>
			</div>`;

		await import( './index' );

		expect( document.querySelector( '.profotograaf-client-link__qr svg' ) ).not.toBeNull();
	} );
} );
