import { __ } from '@wordpress/i18n';

// A type alias, not an interface: block attributes must be assignable to an
// index signature.
export type ClientGalleriesAttributes = {
	portal: string;
	heading: string;
	description: string;
	buttonLabel: string;
	openInNewTab: boolean;
};

export interface ClientGalleriesCopy {
	heading: string;
	description: string;
	buttonLabel: string;
	/** True when the editor should show the "enter your address" note. */
	showNotice: boolean;
}

/**
 * The text the editor preview shows: what the author typed, or the same
 * default the server render falls back to.
 */
export function previewCopy(
	attributes: ClientGalleriesAttributes
): ClientGalleriesCopy {
	const { portal, heading, description, buttonLabel } = attributes;
	return {
		heading: heading || __( 'Find your gallery', 'profotograaf' ),
		description:
			description ||
			__(
				'Sign in to the client portal to see your photos.',
				'profotograaf'
			),
		buttonLabel: buttonLabel || __( 'Open my gallery', 'profotograaf' ),
		showNotice: ! portal,
	};
}
