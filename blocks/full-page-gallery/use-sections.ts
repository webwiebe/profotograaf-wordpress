import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { LIST_PATH } from '../gallery/helpers';
import type { RestError } from '../gallery/types';
import type { FullPageAttributes, SectionsAnswer } from './types';

export interface SectionsState {
	/** The last answer. It stays while a newer request loads. */
	answer: SectionsAnswer | null;
	error: RestError | null;
}

/** How long the preview waits for typing to stop before it asks again. */
const DELAY = 300;

/**
 * The REST path of the editor preview: the sequenced sections of one gallery
 * for the block's preset, sections, picks and excluded photos. Lists travel
 * as JSON, the excluded ids comma separated (Sections_Rest).
 */
export function sectionsPath(
	attributes: Pick< FullPageAttributes, 'galleryId' | 'preset' | 'sections' | 'picks' | 'excludedPhotoIds' >
): string {
	const query = new URLSearchParams();
	if ( attributes.preset ) {
		query.set( 'preset', attributes.preset );
	}
	if ( attributes.sections.length > 0 ) {
		query.set( 'sections', JSON.stringify( attributes.sections ) );
	}
	if ( attributes.picks.length > 0 ) {
		query.set( 'picks', JSON.stringify( attributes.picks ) );
	}
	if ( attributes.excludedPhotoIds.length > 0 ) {
		query.set( 'exclude', attributes.excludedPhotoIds.join( ',' ) );
	}
	const search = query.toString();
	return `${ LIST_PATH }/${ encodeURIComponent( attributes.galleryId ) }/sections${ search ? `?${ search }` : '' }`;
}

/** The sections the front end draws for the block, loaded again when the block changes. */
export function useSections( attributes: FullPageAttributes ): SectionsState {
	const path = attributes.galleryId ? sectionsPath( attributes ) : '';
	const [ state, setState ] = useState< SectionsState >( { answer: null, error: null } );
	useEffect( () => {
		if ( ! path ) {
			setState( { answer: null, error: null } );
			return undefined;
		}
		const live = { active: true };
		const timer = setTimeout( () => {
			apiFetch< SectionsAnswer >( { path } )
				.then( ( answer ) => live.active && setState( { answer, error: null } ) )
				.catch( ( error: RestError ) => live.active && setState( { answer: null, error } ) );
		}, DELAY );
		return () => {
			live.active = false;
			clearTimeout( timer );
		};
	}, [ path ] );
	return state;
}
