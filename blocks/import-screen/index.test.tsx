import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/components', async () => import( '../test-support/wp-mocks' ) );
vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => undefined ) ),
} ) );

beforeEach( () => {
	vi.resetModules();
} );
afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'import screen entry', () => {
	it( 'mounts the app on the root element', async () => {
		document.body.innerHTML =
			'<div id="profotograaf-import-root" data-admin-url="https://site.test/wp-admin/"></div>';
		await import( './index' );
		await vi.waitFor( () =>
			expect( document.querySelector( '[data-testid="spinner"]' ) ).not.toBeNull()
		);
	} );

	it( 'offers Keep on Profotograaf only when the root asks for it', async () => {
		document.body.innerHTML = '<div id="profotograaf-import-root" data-keep="1"></div>';
		await import( './index' );
		await vi.waitFor( () => expect( document.body.textContent ).toContain( 'Keep 0 photos on Profotograaf' ) );
	} );

	it( 'leaves Keep on Profotograaf out without data-keep', async () => {
		document.body.innerHTML = '<div id="profotograaf-import-root"></div>';
		await import( './index' );
		await vi.waitFor( () => expect( document.body.textContent ).toContain( 'Import 0 photos' ) );
		expect( document.body.textContent ).not.toContain( 'on Profotograaf' );
	} );

	it( 'does nothing without the root element', async () => {
		await import( './index' );
		expect( document.body.innerHTML ).toBe( '' );
	} );
} );
