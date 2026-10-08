import { __, _n, sprintf } from '@wordpress/i18n';
import type { PhotoItem } from '../import-screen/types';
import type { Session } from './session';

const SEARCH_DELAY = 300;

function make< K extends keyof HTMLElementTagNameMap >(
	tag: K,
	className = '',
	text = ''
): HTMLElementTagNameMap[ K ] {
	const node = document.createElement( tag );
	if ( '' !== className ) {
		node.className = className;
	}
	if ( '' !== text ) {
		node.textContent = text;
	}
	return node;
}

let counter = 0;

function field( label: string, control: HTMLElement ): HTMLElement {
	const id = `profotograaf-modal-field-${ ++counter }`;
	control.id = id;
	const wrap = make( 'div', 'profotograaf-modal__field' );
	const caption = make( 'label', '', label );
	caption.htmlFor = id;
	wrap.append( caption, control );
	return wrap;
}

function card( photo: PhotoItem, session: Session ): HTMLElement {
	const item = make( 'li', 'profotograaf-modal__card' );
	const button = make( 'button', 'profotograaf-modal__pick' );
	button.type = 'button';
	button.dataset.photo = photo.sourceId;
	button.addEventListener( 'click', () => session.toggle( photo ) );
	const image = make( 'img' );
	image.src = photo.previewUrl;
	// The title under the image names the button, so the image itself is decoration.
	image.alt = '';
	image.loading = 'lazy';
	button.append( image, make( 'span', 'profotograaf-modal__title', photo.title ) );
	item.append( button );
	return item;
}

/** The text under the filters: progress while importing, otherwise the selection. */
export function statusText( session: Session ): string {
	if ( session.progress ) {
		return sprintf(
			/* translators: 1: photos done, 2: photos in total. */
			__( 'Importing %1$d of %2$d photos...', 'profotograaf' ),
			session.progress.done,
			session.progress.total
		);
	}
	const count = session.pending().length;
	if ( 0 === count ) {
		return '';
	}
	return sprintf(
		/* translators: %d: number of selected photos. */
		_n( '%d photo selected', '%d photos selected', count, 'profotograaf' ),
		count
	);
}

/** The line under the grid: a load failure, a stale list or an empty result. */
export function noteText( session: Session ): string {
	const { error, stale, loading, items, page } = session.library.state;
	if ( '' !== error ) {
		return error;
	}
	if ( stale ) {
		return __( 'The platform could not be reached. Showing the last saved list.', 'profotograaf' );
	}
	if ( ! loading && 0 === items.length && page > 0 ) {
		return __( 'No photos found.', 'profotograaf' );
	}
	return '';
}

/**
 * The tab's content: filters, a grid of the platform's photos and the status
 * lines. Built with DOM calls only; every text goes in as text.
 */
export class Panel {
	readonly element = make( 'div', 'profotograaf-modal' );

	private readonly search = make( 'input' );

	private readonly gallery = make( 'select' );

	private readonly galleryField: HTMLElement;

	private readonly status = make( 'p', 'profotograaf-modal__status' );

	private readonly problems = make( 'ul', 'profotograaf-modal__problems' );

	private readonly grid = make( 'ul', 'profotograaf-modal__grid' );

	private readonly note = make( 'p', 'profotograaf-modal__note' );

	private readonly more = make( 'button', 'button profotograaf-modal__more', __( 'Load more photos', 'profotograaf' ) );

	private rendered: PhotoItem[] = [];

	private galleryKey = '\u0000';

	private timer: ReturnType< typeof setTimeout > | undefined;

	private readonly unsubscribe: Array< () => void >;

	constructor( private readonly session: Session ) {
		this.search.type = 'search';
		this.more.type = 'button';
		this.status.setAttribute( 'role', 'status' );
		this.problems.setAttribute( 'role', 'alert' );
		const filters = make( 'div', 'profotograaf-modal__filters' );
		this.galleryField = field( __( 'Gallery', 'profotograaf' ), this.gallery );
		filters.append( field( __( 'Search photos', 'profotograaf' ), this.search ), this.galleryField );
		this.element.append( filters, this.status, this.problems, this.grid, this.note, this.more );
		this.listen();
		this.unsubscribe = [ session.subscribe( () => this.render() ), session.library.subscribe( () => this.render() ) ];
		this.render();
		if ( ! session.library.started() ) {
			void session.library.first();
		}
	}

	destroy(): void {
		clearTimeout( this.timer );
		this.unsubscribe.forEach( ( off ) => off() );
	}

	private listen(): void {
		const { library } = this.session;
		this.search.addEventListener( 'input', () => {
			clearTimeout( this.timer );
			this.timer = setTimeout( () => void library.setSearch( this.search.value.trim() ), SEARCH_DELAY );
		} );
		this.gallery.addEventListener( 'change', () => void library.setGallery( this.gallery.value ) );
		this.more.addEventListener( 'click', () => void library.more() );
	}

	private render(): void {
		const { library } = this.session;
		this.renderGalleries();
		this.renderGrid();
		this.status.textContent = statusText( this.session );
		this.problems.replaceChildren(
			...this.session.failures.map( ( item ) => make( 'li', '', `${ item.title }: ${ item.message }` ) )
		);
		this.note.textContent = noteText( this.session );
		this.note.hidden = '' === this.note.textContent;
		this.more.hidden = ! library.hasMore();
		this.more.disabled = library.state.loading;
		this.element.setAttribute( 'aria-busy', String( library.state.loading ) );
	}

	private renderGrid(): void {
		const items = this.session.library.state.items;
		const grown = this.rendered.length > 0 && this.rendered.length <= items.length && this.rendered[ 0 ] === items[ 0 ];
		if ( ! grown ) {
			this.grid.replaceChildren();
			this.rendered = [];
		}
		this.grid.append( ...items.slice( this.rendered.length ).map( ( photo ) => card( photo, this.session ) ) );
		this.rendered = items;
		const byId = new Map( items.map( ( photo ) => [ photo.sourceId, photo ] ) );
		for ( const button of this.grid.querySelectorAll< HTMLButtonElement >( 'button' ) ) {
			const photo = byId.get( button.dataset.photo ?? '' );
			button.setAttribute( 'aria-pressed', String( photo !== undefined && this.session.has( photo ) ) );
			button.disabled = this.session.busy();
		}
	}

	private renderGalleries(): void {
		const { galleries, gallery } = this.session.library.state;
		this.galleryField.hidden = galleries.length < 2;
		const key = galleries.map( ( item ) => item.id ).join( '|' );
		if ( key === this.galleryKey ) {
			return;
		}
		this.galleryKey = key;
		this.gallery.replaceChildren(
			new Option( __( 'All galleries', 'profotograaf' ), '' ),
			...galleries.map( ( item ) => new Option( `${ item.title } (${ item.count })`, item.id ) )
		);
		this.gallery.value = gallery;
	}
}
