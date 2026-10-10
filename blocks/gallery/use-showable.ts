import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { showablePath } from './helpers';

/**
 * How many photos the site embed shows for a gallery, or null while that is
 * loading or unknown. A failed request leaves it null: the notice needs a
 * clear answer, and the picker and photo list report errors themselves.
 */
export function useShowable( galleryId: string ): number | null {
	const [ showable, setShowable ] = useState< number | null >( null );
	useEffect( () => {
		setShowable( null );
		if ( ! galleryId ) {
			return undefined;
		}
		const live = { active: true };
		apiFetch< { showable?: number } >( { path: showablePath( galleryId ) } )
			.then( ( result ) => {
				if ( live.active && typeof result.showable === 'number' ) {
					setShowable( result.showable );
				}
			} )
			.catch( () => undefined );
		return () => {
			live.active = false;
		};
	}, [ galleryId ] );
	return showable;
}
