import { useEffect, useState } from '@wordpress/element';
import { fetchPhotos } from './api';
import type { PhotoQuery } from './api';
import type { GalleryFacet, PhotosResponse, RestError } from './types';

export interface PhotoList {
	/** Null while the page loads or when it failed. */
	data: PhotosResponse | null;
	error: RestError | null;
	/** The galleries of the last page that loaded, kept while the next one loads. */
	galleries: GalleryFacet[];
	retry: () => void;
}

/** One page of photos, loaded again when the query changes or on retry. */
export function usePhotoList( query: PhotoQuery ): PhotoList {
	const [ data, setData ] = useState< PhotosResponse | null >( null );
	const [ error, setError ] = useState< RestError | null >( null );
	const [ galleries, setGalleries ] = useState< GalleryFacet[] >( [] );
	const [ attempt, setAttempt ] = useState( 0 );
	const { search, gallery, page } = query;

	useEffect( () => {
		const live = { active: true };
		setData( null );
		setError( null );
		fetchPhotos( { search, gallery, page } )
			.then( ( response ) => {
				if ( live.active ) {
					setData( response );
					setGalleries( response.galleries );
				}
			} )
			.catch( ( failure: RestError ) => live.active && setError( failure ) );
		return () => {
			live.active = false;
		};
	}, [ search, gallery, page, attempt ] );

	return { data, error, galleries, retry: () => setAttempt( ( n ) => n + 1 ) };
}
