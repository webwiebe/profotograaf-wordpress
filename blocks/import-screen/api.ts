import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import type {
	ImportResponse,
	PhotosResponse,
	RestError,
} from './types';

const PHOTOS_PATH = '/profotograaf/v1/photos';
const IMPORT_PATH = '/profotograaf/v1/photos/import';

/** Photos per page of the grid. */
const PER_PAGE = 24;

/** Photos per import request. The route takes 50, smaller batches show progress. */
export const BATCH_SIZE = 5;

export interface PhotoQuery {
	search: string;
	gallery: string;
	page: number;
}

export function photosPath( query: PhotoQuery ): string {
	const params = new URLSearchParams( {
		page: String( query.page ),
		per_page: String( PER_PAGE ),
	} );
	if ( query.search !== '' ) {
		params.set( 'search', query.search );
	}
	if ( query.gallery !== '' ) {
		params.set( 'gallery', query.gallery );
	}
	return `${ PHOTOS_PATH }?${ params.toString() }`;
}

export function fetchPhotos( query: PhotoQuery ): Promise< PhotosResponse > {
	return apiFetch< PhotosResponse >( { path: photosPath( query ) } );
}

export function importPhotos( ids: string[] ): Promise< ImportResponse > {
	return apiFetch< ImportResponse >( {
		path: IMPORT_PATH,
		method: 'POST',
		data: { ids },
	} );
}

/** The message of a REST rejection, which the server already translated. */
export function errorText( error: RestError | null | undefined ): string {
	return error?.message
		? error.message
		: __( 'Something went wrong. Try again.', 'profotograaf' );
}

export function chunk< T >( items: T[], size: number ): T[][] {
	const chunks: T[][] = [];
	for ( let index = 0; index < items.length; index += size ) {
		chunks.push( items.slice( index, index + size ) );
	}
	return chunks;
}
