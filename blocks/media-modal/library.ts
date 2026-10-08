import { errorText, fetchPhotos } from '../import-screen/api';
import type { PhotoQuery } from '../import-screen/api';
import type { GalleryFacet, PhotoItem, PhotosResponse, RestError } from '../import-screen/types';

export interface LibraryState {
	items: PhotoItem[];
	galleries: GalleryFacet[];
	search: string;
	gallery: string;
	page: number;
	totalPages: number;
	loading: boolean;
	/** Translated message of the last failed load, empty when it worked. */
	error: string;
	stale: boolean;
}

type Loader = ( query: PhotoQuery ) => Promise< PhotosResponse >;

/**
 * The pages of photos the tab lists, with search and gallery filter. A load
 * that a newer query overtakes is dropped. A failure becomes a message in the
 * state and never throws, so the rest of the modal stays usable.
 */
export class PhotoLibrary {
	state: LibraryState = {
		items: [],
		galleries: [],
		search: '',
		gallery: '',
		page: 0,
		totalPages: 0,
		loading: false,
		error: '',
		stale: false,
	};

	private readonly listeners = new Set< () => void >();

	private ticket = 0;

	private began = false;

	constructor( private readonly loader: Loader = fetchPhotos ) {}

	subscribe( listener: () => void ): () => void {
		this.listeners.add( listener );
		return () => this.listeners.delete( listener );
	}

	hasMore(): boolean {
		return this.state.page > 0 && this.state.page < this.state.totalPages;
	}

	/** Whether a first load was started, so opening the tab twice loads once. */
	started(): boolean {
		return this.began;
	}

	setSearch( search: string ): Promise< void > {
		this.state = { ...this.state, search };
		return this.load( 1 );
	}

	setGallery( gallery: string ): Promise< void > {
		this.state = { ...this.state, gallery };
		return this.load( 1 );
	}

	first(): Promise< void > {
		return this.load( 1 );
	}

	more(): Promise< void > {
		if ( this.state.loading || ! this.hasMore() ) {
			return Promise.resolve();
		}
		return this.load( this.state.page + 1 );
	}

	private emit(): void {
		this.listeners.forEach( ( listener ) => listener() );
	}

	private async load( page: number ): Promise< void > {
		this.began = true;
		const ticket = ++this.ticket;
		this.state = { ...this.state, loading: true, error: '' };
		this.emit();
		try {
			const response = await this.loader( {
				search: this.state.search,
				gallery: this.state.gallery,
				page,
			} );
			if ( ticket !== this.ticket ) {
				return;
			}
			this.state = this.loaded( page, response );
		} catch ( error ) {
			if ( ticket !== this.ticket ) {
				return;
			}
			this.state = {
				...this.state,
				items: page === 1 ? [] : this.state.items,
				loading: false,
				error: errorText( error as RestError ),
			};
		}
		this.emit();
	}

	private loaded( page: number, response: PhotosResponse ): LibraryState {
		return {
			...this.state,
			items: page === 1 ? response.items : [ ...this.state.items, ...response.items ],
			galleries: response.galleries,
			page,
			totalPages: response.totalPages,
			stale: response.stale,
			loading: false,
		};
	}
}
