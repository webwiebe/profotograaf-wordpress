import { __ } from '@wordpress/i18n';

/** The part of the Clipboard API the copy button uses. */
export type LinkClipboard = Pick< Clipboard, 'writeText' >;

/**
 * Copies a share link to the clipboard and says what happened in the status
 * element. When the browser refuses (no clipboard access on plain http, or a
 * denied permission), the link field is selected so the person can copy it
 * with the keyboard.
 */
export async function copyLink(
	field: HTMLInputElement,
	status: HTMLElement,
	clipboard: LinkClipboard | undefined
): Promise< void > {
	try {
		if ( ! clipboard ) {
			throw new Error( 'The clipboard is not available.' );
		}
		await clipboard.writeText( field.value );
		status.textContent = __( 'Link copied.', 'profotograaf' );
	} catch {
		field.focus();
		field.select();
		status.textContent = __(
			'This browser does not allow copying here. The link is selected, so press Ctrl+C or Cmd+C to copy it.',
			'profotograaf'
		);
	}
}
