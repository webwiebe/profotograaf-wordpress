import type { GalleryRow } from '../gallery/types';

export function galleryRow( overrides: Partial< GalleryRow > = {} ): GalleryRow {
	return {
		id: 'g-1',
		title: 'Spring wedding',
		url: 'https://studio.example/share/g/spring-wedding',
		cover_url: 'https://studio.example/cover.jpg',
		photo_count: 3,
		embeddable: true,
		available: true,
		...overrides,
	};
}
