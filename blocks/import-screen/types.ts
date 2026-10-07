/** One photo as GET /profotograaf/v1/photos returns it. */
export interface PhotoItem {
	sourceId: string;
	title: string;
	alt: string;
	previewUrl: string;
	galleryId: string;
	galleryTitle: string;
	/** Attachment id of an earlier import. */
	id?: number;
}

export interface GalleryFacet {
	id: string;
	title: string;
	count: number;
}

export interface PhotosResponse {
	items: PhotoItem[];
	totalItems: number;
	totalPages: number;
	galleries: GalleryFacet[];
	stale: boolean;
}

/** One entry of the answer to POST /profotograaf/v1/photos/import. */
export interface ImportResult {
	id: string;
	attachment_id?: number;
	error?: string;
}

export interface ImportResponse {
	results: ImportResult[];
}

export interface RestError {
	code?: string;
	message?: string;
}

/** A photo that failed to import, with the reason. */
export interface ImportFailure {
	id: string;
	title: string;
	message: string;
}
