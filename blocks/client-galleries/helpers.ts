import { __ } from '@wordpress/i18n';

// A type alias, not an interface: block attributes must be assignable to an
// index signature.
export type ClientGalleriesAttributes = {
	portal: string;
	portalPath: string;
	heading: string;
	headingLevel: number;
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

/** The element a heading level renders as: h2 to h6, or `p` for 0. */
export function headingTag( level: number ): 'h2' | 'h3' | 'h4' | 'h5' | 'h6' | 'p' {
	switch ( level ) {
		case 0:
			return 'p';
		case 2:
			return 'h2';
		case 4:
			return 'h4';
		case 5:
			return 'h5';
		case 6:
			return 'h6';
		default:
			return 'h3';
	}
}
