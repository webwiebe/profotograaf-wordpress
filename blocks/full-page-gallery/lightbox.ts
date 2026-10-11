import { __ } from '@wordpress/i18n';

/** One photo of the flat list behind data-pf-index. */
export interface LightboxPhoto {
	src: string;
	srcset: string;
	w: number;
	h: number;
	alt: string;
	caption: string;
}

/** Horizontal distance in pixels that counts as a swipe. */
const SWIPE_THRESHOLD = 40;

/**
 * Create a button of the viewer.
 *
 * @param className Class of the button.
 * @param label     Translated aria-label.
 * @param text      Visible glyph.
 */
function makeButton( className: string, label: string, text: string ): HTMLButtonElement {
	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = className;
	button.setAttribute( 'aria-label', label );
	button.textContent = text;
	return button;
}

/** The elements of the viewer. */
interface Viewer {
	overlay: HTMLDivElement;
	image: HTMLImageElement;
	caption: HTMLParagraphElement;
	prev: HTMLButtonElement;
	next: HTMLButtonElement;
	close: HTMLButtonElement;
}

/** Build the viewer DOM. */
function buildViewer(): Viewer {
	const overlay = document.createElement( 'div' );
	overlay.className = 'pf-fp-lightbox';
	overlay.setAttribute( 'role', 'dialog' );
	overlay.setAttribute( 'aria-modal', 'true' );
	overlay.setAttribute( 'aria-label', __( 'Photo viewer', 'profotograaf' ) );
	overlay.tabIndex = -1;
	const image = document.createElement( 'img' );
	image.className = 'pf-fp-lb-img';
	image.sizes = '100vw';
	const caption = document.createElement( 'p' );
	caption.className = 'pf-fp-lb-caption';
	const prev = makeButton( 'pf-fp-lb-prev', __( 'Previous photo', 'profotograaf' ), '\u2039' );
	const next = makeButton( 'pf-fp-lb-next', __( 'Next photo', 'profotograaf' ), '\u203A' );
	const close = makeButton( 'pf-fp-lb-close', __( 'Close viewer', 'profotograaf' ), '\u00D7' );
	overlay.append( image, caption, prev, next, close );
	return { overlay, image, caption, prev, next, close };
}

/**
 * Keep Tab inside the dialog.
 *
 * @param overlay The dialog element.
 * @param event   Keydown event.
 */
function trapTab( overlay: HTMLElement, event: KeyboardEvent ): void {
	const items = Array.from( overlay.querySelectorAll< HTMLButtonElement >( 'button:not(:disabled)' ) );
	const first = items[ 0 ];
	const last = items[ items.length - 1 ];
	if ( ! first || ! last ) {
		event.preventDefault();
		overlay.focus();
		return;
	}
	const active = document.activeElement;
	if ( event.shiftKey && ( active === first || active === overlay ) ) {
		event.preventDefault();
		last.focus();
	} else if ( ! event.shiftKey && active === last ) {
		event.preventDefault();
		first.focus();
	} else if ( ! overlay.contains( active ) ) {
		event.preventDefault();
		first.focus();
	}
}

/**
 * Call a step function on a horizontal swipe of the threshold or more.
 *
 * @param overlay The dialog element.
 * @param step    Receives 1 for forward and -1 for back.
 */
function bindSwipe( overlay: HTMLElement, step: ( delta: number ) => void ): void {
	let startX: number | null = null;
	overlay.addEventListener( 'touchstart', ( event ) => {
		startX = event.changedTouches[ 0 ]?.clientX ?? null;
	} );
	overlay.addEventListener( 'touchend', ( event ) => {
		const endX = event.changedTouches[ 0 ]?.clientX;
		if ( startX !== null && endX !== undefined && Math.abs( endX - startX ) >= SWIPE_THRESHOLD ) {
			step( endX < startX ? 1 : -1 );
		}
		startX = null;
	} );
}

/**
 * Open the lightbox over the page at one photo. It steps through the whole
 * list, stops at the ends, traps focus, locks body scroll and gives focus back
 * to the opener when it closes.
 *
 * @param photos Flat photo list across all sections.
 * @param start  Index to open at.
 * @param opener Element that gets focus back on close.
 */
export function openLightbox( photos: LightboxPhoto[], start: number, opener: HTMLElement ): void {
	const previousOverflow = document.body.style.overflow;
	const { overlay, image, caption, prev, next, close } = buildViewer();
	let index = Math.min( Math.max( start, 0 ), photos.length - 1 );

	/** Show the photo at the current index and refresh the buttons. */
	function render(): void {
		const photo = photos[ index ];
		if ( ! photo ) {
			return;
		}
		image.src = photo.src;
		image.srcset = photo.srcset;
		image.alt = photo.alt;
		caption.textContent = photo.caption;
		caption.hidden = photo.caption === '';
		prev.disabled = index === 0;
		next.disabled = index === photos.length - 1;
	}

	/**
	 * Move by a number of photos, stopping at the ends.
	 *
	 * @param delta Steps, negative for back.
	 */
	function step( delta: number ): void {
		const target = index + delta;
		if ( target >= 0 && target < photos.length ) {
			index = target;
			render();
		}
	}

	/** Remove the viewer and hand focus back. */
	function dismiss(): void {
		document.removeEventListener( 'keydown', onKeyDown, true );
		overlay.remove();
		document.body.style.overflow = previousOverflow;
		opener.focus();
	}

	/**
	 * Handle the keys of the viewer.
	 *
	 * @param event Keydown event.
	 */
	function onKeyDown( event: KeyboardEvent ): void {
		if ( event.key === 'Escape' ) {
			dismiss();
		} else if ( event.key === 'ArrowLeft' ) {
			step( -1 );
		} else if ( event.key === 'ArrowRight' ) {
			step( 1 );
		} else if ( event.key === 'Tab' ) {
			trapTab( overlay, event );
		}
	}

	prev.addEventListener( 'click', () => step( -1 ) );
	next.addEventListener( 'click', () => step( 1 ) );
	close.addEventListener( 'click', dismiss );
	bindSwipe( overlay, step );
	render();
	document.body.append( overlay );
	document.body.style.overflow = 'hidden';
	document.addEventListener( 'keydown', onKeyDown, true );
	close.focus();
}
