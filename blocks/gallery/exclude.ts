import { _n, sprintf } from '@wordpress/i18n';
import { LIST_PATH } from './helpers';
import type { PhotoRow } from './types';

/** The block attribute that holds the ids of the photos left out. */
// A type alias, not an interface: block attributes must be assignable to an
// index signature.
export type ExcludeAttributes = {
	excludedPhotoIds: string[];
};

export const EXCLUDE_DEFAULTS: ExcludeAttributes = { excludedPhotoIds: [] };

/** REST path of the photo list of one gallery. */
export function photosPath( galleryId: string ): string {
	return `${ LIST_PATH }/${ encodeURIComponent( galleryId ) }/photos`;
}

/** Whether a photo is left out. */
export function isExcluded( excluded: string[], photoId: string ): boolean {
	return excluded.includes( photoId );
}

/** The list with one photo added when it was shown, or removed when it was left out. */
export function toggleExcluded( excluded: string[], photoId: string ): string[] {
	return isExcluded( excluded, photoId )
		? excluded.filter( ( id ) => id !== photoId )
		: [ ...excluded, photoId ];
}

/** The photos that stay in the gallery. */
export function visiblePhotos( photos: PhotoRow[], excluded: string[] ): PhotoRow[] {
	return photos.filter( ( photo ) => ! isExcluded( excluded, photo.id ) );
}

/** How many of the left-out ids still match a photo of the gallery. */
export function excludedCount( photos: PhotoRow[], excluded: string[] ): number {
	return photos.filter( ( photo ) => isExcluded( excluded, photo.id ) ).length;
}

/** The phrase for the number of photos left out. */
export function excludedLabel( count: number ): string {
	return sprintf(
		/* translators: %d: number of photos left out of the gallery. */
		_n( '%d photo left out', '%d photos left out', count, 'profotograaf' ),
		count
	);
}
