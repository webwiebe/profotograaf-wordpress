import type { GalleryRow, PhotoRow } from '../gallery/types';

export function photoRow( overrides: Partial< PhotoRow > = {} ): PhotoRow {
	return {
		id: 'p-1',
		title: 'Bride',
		alt: '',
		caption: '',
		width: 3000,
		height: 2000,
		thumb_url: 'https://studio.example/img/p-1/thumb.jpg',
		...overrides,
	};
}

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
