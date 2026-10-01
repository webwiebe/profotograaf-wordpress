import { __, _n, sprintf } from '@wordpress/i18n';
import type { GalleryAttributes, GalleryRow, RestError } from './types';

export const LIST_PATH = '/profotograaf/v1/galleries';

export function countLabel( count: number ): string {
	return sprintf(
		/* translators: %d: number of photos in a gallery. */
		_n( '%d photo', '%d photos', count, 'profotograaf' ),
		count
	);
}

export interface LayoutOption {
	label: string;
	value: GalleryAttributes[ 'layout' ];
}

export function layoutOptions(): LayoutOption[] {
	return [
		{ label: __( 'Site default', 'profotograaf' ), value: '' },
		{ label: __( 'Grid', 'profotograaf' ), value: 'grid' },
		{ label: __( 'Masonry', 'profotograaf' ), value: 'masonry' },
		{ label: __( 'Slideshow', 'profotograaf' ), value: 'slideshow' },
	];
}

export function layoutLabel( layout: GalleryAttributes[ 'layout' ] ): string {
	return (
		layoutOptions().find( ( option ) => option.value === layout )
			?.label ?? ''
	);
}

export type ErrorKind =
	| 'not-connected'
	| 'reconnect'
	| 'rate-limited'
	| 'network'
	| 'platform';

// Codes the browser side of @wordpress/api-fetch rejects with when no usable
// answer came back from the site.
const TRANSPORT_CODES = [ 'fetch_error', 'invalid_json' ];

/** Which of the five editor failures a REST rejection is. */
export function errorKind( error: RestError | null | undefined ): ErrorKind {
	const code = error?.code ?? '';
	const status = error?.data?.status ?? 0;
	if ( code === 'profotograaf_not_connected' ) {
		return 'not-connected';
	}
	if ( code === 'profotograaf_reconnect' ) {
		return 'reconnect';
	}
	if ( status === 429 ) {
		return 'rate-limited';
	}
	if (
		code === 'profotograaf_network' ||
		TRANSPORT_CODES.includes( code ) ||
		status === 504
	) {
		return 'network';
	}
	return 'platform';
}

/** Whether trying the same request again can help. */
export function canRetry( error: RestError | null | undefined ): boolean {
	const kind = errorKind( error );
	return kind !== 'not-connected' && kind !== 'reconnect';
}

function rateLimitedMessage( seconds: number ): string {
	if ( seconds <= 0 ) {
		return __(
			'Profotograaf is receiving too many requests. Try again in a moment.',
			'profotograaf'
		);
	}
	return sprintf(
		/* translators: %d: number of seconds to wait. */
		_n(
			'Profotograaf is receiving too many requests. Try again in %d second.',
			'Profotograaf is receiving too many requests. Try again in %d seconds.',
			seconds,
			'profotograaf'
		),
		seconds
	);
}

/** The translated message for a REST rejection. The server text is never shown. */
export function errorMessage( error: RestError | null | undefined ): string {
	switch ( errorKind( error ) ) {
		case 'not-connected':
			return __(
				'Connect this site to Profotograaf under Settings > Profotograaf to pick a gallery.',
				'profotograaf'
			);
		case 'reconnect':
			return __(
				'Connect this site to Profotograaf again under Settings > Profotograaf. The connection needs a new permission.',
				'profotograaf'
			);
		case 'rate-limited':
			return rateLimitedMessage( error?.data?.retry_after ?? 0 );
		case 'network':
			return __(
				'Profotograaf could not be reached. Check your connection and try again.',
				'profotograaf'
			);
		default:
			return __(
				'Profotograaf reported a problem. Try again later.',
				'profotograaf'
			);
	}
}

export function notEligible(): string {
	return __(
		'This gallery cannot be shown on other sites right now. A password, an expiry date, proofing mode or a client-only setting stops embedding.',
		'profotograaf'
	);
}

/** The attributes stored on the block when a gallery is picked. */
export function pickedAttributes(
	gallery: GalleryRow
): Pick< GalleryAttributes, 'galleryId' | 'galleryTitle' | 'galleryUrl' > {
	return {
		galleryId: gallery.id,
		galleryTitle: gallery.title,
		galleryUrl: gallery.url,
	};
}

/** The attributes that clear the picked gallery. */
export function clearedAttributes(): Pick<
	GalleryAttributes,
	'galleryId' | 'galleryTitle' | 'galleryUrl'
> {
	return { galleryId: '', galleryTitle: '', galleryUrl: '' };
}

export type Eligibility = 'ok' | 'not-eligible' | 'ask-server';

/**
 * What to do after a gallery was picked. An embeddable gallery that is
 * available needs nothing, an embeddable one that is not available cannot be
 * shown right now, and a gallery not yet embeddable has to be asked to become
 * so.
 */
export function eligibility( gallery: GalleryRow ): Eligibility {
	if ( gallery.embeddable && gallery.available ) {
		return 'ok';
	}
	return gallery.embeddable ? 'not-eligible' : 'ask-server';
}

/** REST path that turns embedding on for one gallery. */
export function embeddablePath( id: string ): string {
	return `${ LIST_PATH }/${ encodeURIComponent( id ) }/embeddable`;
}

/** The notice for the server's answer to the embeddable request, or ''. */
export function noticeForEmbeddable(
	result: { available?: boolean } | null | undefined
): string {
	return result && result.available === false ? notEligible() : '';
}

export type PostEmbeddable = (
	path: string
) => Promise< { available?: boolean } | null | undefined >;

/**
 * The warning to show after a gallery was picked, or '' when there is none.
 * Asks the server to turn embedding on for a gallery that is not embeddable
 * yet, through `post` so the caller owns the transport.
 */
export async function pickNotice(
	gallery: GalleryRow,
	post: PostEmbeddable
): Promise< string > {
	const outcome = eligibility( gallery );
	if ( outcome === 'ok' ) {
		return '';
	}
	if ( outcome === 'not-eligible' ) {
		return notEligible();
	}
	try {
		return noticeForEmbeddable( await post( embeddablePath( gallery.id ) ) );
	} catch ( e ) {
		return errorMessage( e as RestError );
	}
}
