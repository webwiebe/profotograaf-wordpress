import type { DisplayAttributes } from '../gallery/display-options';
import type { ImageText } from '../gallery/types';
import type { Section } from './sections';

/** A photo picked for one slot of a preset, `<cycle>.<section index>.<position>`. */
export interface SlotPick {
	slot: string;
	photoId: string;
}

// A type alias: block attributes must be assignable to an
// index signature. Every stored value is empty until the photographer sets it.
export type FullPageAttributes = {
	align: string;
	galleryId: string;
	galleryTitle: string;
	galleryUrl: string;
	preset: string;
	sections: Section[];
	picks: SlotPick[];
	excludedPhotoIds: string[];
	imageText: ImageText[];
} & Pick< DisplayAttributes, 'captions' | 'lightbox' | 'linkTo' | 'linkNewTab' >;

/** One photo of the sections REST answer, cut down to what the preview draws. */
export interface PreviewPhoto {
	id: string;
	thumb: string;
	width: number;
	height: number;
	alt: string;
}

/** One section of the sections REST answer: its fields, slot ids and photos. */
export type PreviewSection = Section & {
	slots: string[];
	photos: PreviewPhoto[];
};

/** The answer of GET /profotograaf/v1/galleries/{id}/sections. */
export interface SectionsAnswer {
	preset: string;
	sections: PreviewSection[];
}
