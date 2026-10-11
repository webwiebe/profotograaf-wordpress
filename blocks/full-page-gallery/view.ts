import './style.css';
import { watchView, type BeaconTarget } from './beacon';
import { initFilmstrip } from './filmstrip';
import { openLightbox, type LightboxPhoto } from './lightbox';

/** The JSON in script.pf-fp-data. */
interface GalleryData {
	lightbox: boolean;
	photos: LightboxPhoto[];
	beacon?: BeaconTarget;
}

/**
 * Read the data script of one gallery.
 *
 * @param root The div[data-pf-full-page] element.
 */
function readData( root: HTMLElement ): GalleryData | null {
	const script = root.querySelector( 'script.pf-fp-data' );
	try {
		const data: GalleryData = JSON.parse( script?.textContent ?? '' );
		return Array.isArray( data.photos ) ? data : null;
	} catch {
		return null;
	}
}

/**
 * Open the lightbox when a photo link is activated. A link is a native
 * control, so Enter and Space arrive as click events.
 *
 * @param root Gallery root.
 * @param data Gallery data.
 */
function bindLightbox( root: HTMLElement, data: GalleryData ): void {
	root.addEventListener( 'click', ( event ) => {
		const target = event.target instanceof Element ? event.target : null;
		const link = target?.closest< HTMLElement >( 'a.pf-fp-open[data-pf-index]' );
		if ( ! link || ! root.contains( link ) ) {
			return;
		}
		const index = Number( link.dataset.pfIndex );
		if ( ! Number.isInteger( index ) || ! data.photos[ index ] ) {
			return;
		}
		event.preventDefault();
		openLightbox( data.photos, index, link );
	} );
}

/**
 * Set up one gallery.
 *
 * @param root The div[data-pf-full-page] element.
 */
function initGallery( root: HTMLElement ): void {
	const data = readData( root );
	if ( data?.lightbox ) {
		bindLightbox( root, data );
	}
	root.querySelectorAll< HTMLElement >( 'section.pf-fp-filmstrip' ).forEach( initFilmstrip );
	watchView( root, data?.beacon );
}

/** Set up every full-page gallery on the page. */
function init(): void {
	document.querySelectorAll< HTMLElement >( 'div[data-pf-full-page]' ).forEach( initGallery );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
