import { copyLink } from './copy';
import type { LinkClipboard } from './copy';
import { qrSvg } from './qr';

/**
 * Fills in one row of the client galleries list: draws the QR code and shows
 * the copy button, which stays hidden until this script runs.
 */
export function installRow( row: HTMLElement, clipboard: LinkClipboard | undefined ): void {
	const url = row.dataset.url ?? '';
	if ( '' === url ) {
		return;
	}
	const qr = row.querySelector< HTMLElement >( '.profotograaf-client-link__qr' );
	if ( qr ) {
		qr.replaceChildren( qrSvg( row.ownerDocument, url, qr.dataset.label ?? '' ) );
	}
	const field = row.querySelector< HTMLInputElement >( '.profotograaf-client-link__url' );
	const button = row.querySelector< HTMLButtonElement >( '.profotograaf-client-link__copy' );
	const status = row.querySelector< HTMLElement >( '.profotograaf-client-link__status' );
	if ( field && button && status ) {
		button.hidden = false;
		button.addEventListener( 'click', () => {
			void copyLink( field, status, clipboard );
		} );
	}
}

/** Fills in every row of the client galleries list below a root element. */
export function install( root: ParentNode, clipboard: LinkClipboard | undefined ): void {
	root.querySelectorAll< HTMLElement >( '.profotograaf-client-link' ).forEach( ( row ) => {
		installRow( row, clipboard );
	} );
}
