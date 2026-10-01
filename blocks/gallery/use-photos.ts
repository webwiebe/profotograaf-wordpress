import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { photosPath } from './exclude';
import type { PhotoRow, RestError } from './types';

export interface PhotosState {
	/** Null while the list loads or when it failed. */
	photos: PhotoRow[] | null;
	error: RestError | null;
}

/** The photos of one gallery, loaded again when the gallery changes. */
export function usePhotos( galleryId: string ): PhotosState {
	const [ state, setState ] = useState< PhotosState >( { photos: null, error: null } );
	useEffect( () => {
		setState( { photos: null, error: null } );
		if ( ! galleryId ) {
			return undefined;
		}
		const live = { active: true };
		apiFetch< PhotoRow[] >( { path: photosPath( galleryId ) } )
			.then( ( photos ) => live.active && setState( { photos, error: null } ) )
			.catch( ( error: RestError ) => live.active && setState( { photos: null, error } ) );
		return () => {
			live.active = false;
		};
	}, [ galleryId ] );
	return state;
}
