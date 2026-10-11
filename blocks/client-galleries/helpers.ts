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
	/** Share link of one delivered gallery. Empty to link to the portal. */
	gallery: string;
	/** `gallery` for blocks from the "Open a delivered gallery" variation. */
	linkTo: 'portal' | 'gallery';
};

export interface DefaultCopy {
	heading: string;
	description: string;
	buttonLabel: string;
}

export interface ClientGalleriesCopy extends DefaultCopy {
	/** The note editors see while the block has nothing to link to, or ''. */
	notice: string;
}

/**
 * Whether a value is a gallery share link the server render uses: an
 * absolute http(s) URL with a host and no spaces. Same rule as
 * Client_Galleries::gallery_url().
 */
export function isGalleryUrl( value: string ): boolean {
	const url = value.trim();
	return /^https?:\/\/[^/?#\s]+/i.test( url ) && ! /\s/.test( url );
}

/** Whether the block links to a gallery, or asks for one. */
export function galleryMode( attributes: ClientGalleriesAttributes ): boolean {
	return isGalleryUrl( attributes.gallery ) || attributes.linkTo === 'gallery';
}

/** The text the server render falls back to, per link target. */
export function defaultCopy( gallery: boolean ): DefaultCopy {
	if ( gallery ) {
		return {
			heading: __( 'Your photos', 'profotograaf' ),
			description: __( 'See the photos in your gallery.', 'profotograaf' ),
			buttonLabel: __( 'Open your gallery', 'profotograaf' ),
		};
	}
	return {
		heading: __( 'Find your gallery', 'profotograaf' ),
		description: __(
			'Sign in to the client portal to see your photos.',
			'profotograaf'
		),
		buttonLabel: __( 'Open my gallery', 'profotograaf' ),
	};
}

function notice( attributes: ClientGalleriesAttributes ): string {
	if ( isGalleryUrl( attributes.gallery ) ) {
		return '';
	}
	if ( attributes.linkTo === 'gallery' ) {
		return __(
			'Only editors see this note. Choose a gallery in the block settings. Until then visitors do not see this block.',
			'profotograaf'
		);
	}
	if ( attributes.portal ) {
		return '';
	}
	return __(
		'Only editors see this note. Enter your Profotograaf address in the block settings. Until then visitors do not see this block.',
		'profotograaf'
	);
}

/**
 * The text the editor preview shows: what the author typed, or the same
 * default the server render falls back to.
 */
export function previewCopy(
	attributes: ClientGalleriesAttributes
): ClientGalleriesCopy {
	const { heading, description, buttonLabel } = attributes;
	const defaults = defaultCopy( galleryMode( attributes ) );
	return {
		heading: heading || defaults.heading,
		description: description || defaults.description,
		buttonLabel: buttonLabel || defaults.buttonLabel,
		notice: notice( attributes ),
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
