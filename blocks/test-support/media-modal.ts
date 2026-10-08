import type { PhotoItem, PhotosResponse } from '../import-screen/types';

export function photo( id: string, overrides: Partial< PhotoItem > = {} ): PhotoItem {
	return {
		sourceId: id,
		title: `Photo ${ id }`,
		alt: '',
		previewUrl: `https://cdn.example/${ id }.jpg`,
		galleryId: 'g-1',
		galleryTitle: 'Spring wedding',
		...overrides,
	};
}

export function response( items: PhotoItem[], overrides: Partial< PhotosResponse > = {} ): PhotosResponse {
	return {
		items,
		totalItems: items.length,
		totalPages: 1,
		galleries: [ { id: 'g-1', title: 'Spring wedding', count: items.length } ],
		stale: false,
		...overrides,
	};
}
