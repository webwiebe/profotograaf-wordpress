import type { DisplayAttributes } from './display-options';
import type { ExcludeAttributes } from './exclude';

/** A row of the /profotograaf/v1/galleries/{id}/photos REST route. */
export interface PhotoRow {
	id: string;
	title: string;
	alt: string;
	caption: string;
	width: number;
	height: number;
	thumb_url: string;
}

/** A row of the /profotograaf/v1/galleries REST route. */
export interface GalleryRow {
	id: string;
	title: string;
	url: string;
	cover_url?: string;
	photo_count: number;
	embeddable: boolean;
	available: boolean;
}

// A type alias, not an interface: block attributes must be assignable to an
// index signature.
export type GalleryAttributes = {
	galleryId: string;
	galleryTitle: string;
	galleryUrl: string;
	layout: '' | 'grid' | 'masonry' | 'slideshow';
} & DisplayAttributes &
	ExcludeAttributes;

/** What the REST layer rejects with. */
export interface RestError {
	code?: string;
	message?: string;
	data?: { status?: number; retry_after?: number; retryable?: boolean };
}
