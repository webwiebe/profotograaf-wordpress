// Test data for the full-page gallery editor tests.
import type { FullPageAttributes, PreviewPhoto, PreviewSection, SectionsAnswer } from '../full-page-gallery/types';

/** Block attributes as block.json stores them, with a gallery chosen. */
export function blockAttributes( overrides: Partial< FullPageAttributes > = {} ): FullPageAttributes {
	return {
		align: 'full',
		galleryId: 'g-1',
		galleryTitle: 'Spring wedding',
		galleryUrl: 'https://studio.example/share/g/spring-wedding',
		preset: '',
		sections: [],
		picks: [],
		excludedPhotoIds: [],
		captions: '',
		lightbox: '',
		linkTo: '',
		linkNewTab: '',
		imageText: [],
		...overrides,
	};
}

export function previewPhoto( id: string, overrides: Partial< PreviewPhoto > = {} ): PreviewPhoto {
	return {
		id,
		thumb: `https://studio.example/img/${ id }/thumb.jpg`,
		width: 3000,
		height: 2000,
		alt: `Photo ${ id }`,
		...overrides,
	};
}

/** A sequenced section with one photo per slot, photo ids p-<first>, p-<first + 1> and so on. */
export function previewSection( section: Omit< PreviewSection, 'photos' >, first = 1 ): PreviewSection {
	return {
		...section,
		photos: section.slots.map( ( _slot, at ) => previewPhoto( `p-${ first + at }` ) ),
	};
}

/** An editorial answer cut short: a hero, a pair and a tail grid of two photos. */
export function editorialAnswer(): SectionsAnswer {
	return {
		preset: 'editorial',
		sections: [
			previewSection( { type: 'hero', height: 85, captions: 'off', title: 'Our day', slots: [ '0.0.0' ] }, 1 ),
			previewSection( { type: 'text', heading: 'Ceremony', text: 'In the garden', align: 'left', slots: [] }, 0 ),
			previewSection( { type: 'pair', side: 'right', mirror: true, gap: 8, captions: 'off', slots: [ '0.2.0', '0.2.1', '0.2.2' ] }, 2 ),
			previewSection( { type: 'grid', columns: 2, rows: 1, ratio: '3-2', gap: 8, captions: 'off', slots: [ '', '' ] }, 5 ),
		],
	};
}
