import apiFetch from '@wordpress/api-fetch';

/** What core's inserter media panel passes to a category's fetch. */
export interface InserterQuery {
	search?: string;
	per_page?: number;
	page?: number;
}

/** An item in the shape core builds an image block from. */
export interface MediaItem {
	url: string;
	previewUrl: string;
	alt: string;
	caption: string;
	title: string;
	sourceId: string;
	type: 'image';
	/** Attachment id of a photo that is already in the Media Library. */
	id?: number;
}

const text = ( value: unknown ): string => ( typeof value === 'string' ? value : '' );

/** One row of GET /profotograaf/v1/photos as a media item, or null for a row without a URL. */
function toItem( row: unknown ): MediaItem | null {
	if ( typeof row !== 'object' || row === null ) {
		return null;
	}
	const source = row as Record< string, unknown >;
	const url = text( source.url );
	if ( '' === url ) {
		return null;
	}
	const item: MediaItem = {
		url,
		previewUrl: text( source.previewUrl ) || url,
		alt: text( source.alt ),
		caption: text( source.caption ),
		title: text( source.title ),
		sourceId: text( source.sourceId ),
		type: 'image',
	};
	// An id makes core insert the existing attachment without uploading again.
	if ( typeof source.id === 'number' && source.id > 0 ) {
		item.id = source.id;
	}
	return item;
}

/**
 * Loads one page of Profotograaf photos for the inserter. Core takes the
 * plain list of items and pages through it with the page query.
 *
 * Core awaits this without a catch, so a rejection leaves the spinner on for
 * good. Every failure therefore ends as an empty list and a console warning.
 */
export async function fetchPhotos( query: InserterQuery ): Promise< MediaItem[] > {
	try {
		const params = new URLSearchParams();
		if ( query.search ) {
			params.set( 'search', query.search );
		}
		if ( query.per_page ) {
			params.set( 'per_page', String( query.per_page ) );
		}
		if ( query.page ) {
			params.set( 'page', String( query.page ) );
		}
		const suffix = params.toString();
		const body: unknown = await apiFetch( {
			path: '/profotograaf/v1/photos' + ( suffix ? '?' + suffix : '' ),
		} );
		const response = typeof body === 'object' && body !== null ? ( body as Record< string, unknown > ) : {};
		const rows: unknown[] = Array.isArray( response.items ) ? response.items : [];
		return rows.map( toItem ).filter( ( item ): item is MediaItem => item !== null );
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.warn( 'Profotograaf photos could not be loaded.', error );
		return [];
	}
}
