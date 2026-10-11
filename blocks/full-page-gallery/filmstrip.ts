import { __ } from '@wordpress/i18n';

/**
 * Create a strip button.
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

/**
 * Give one filmstrip section its previous and next buttons and arrow key
 * scrolling. The buttons hide at the ends of the track.
 *
 * @param section The section.pf-fp-filmstrip element.
 */
export function initFilmstrip( section: HTMLElement ): void {
	const found = section.querySelector< HTMLElement >( '.pf-fp-track' );
	if ( ! found ) {
		return;
	}
	const track: HTMLElement = found;
	const prev = makeButton( 'pf-fp-strip-prev', __( 'Scroll back', 'profotograaf' ), '‹' );
	const next = makeButton( 'pf-fp-strip-next', __( 'Scroll forward', 'profotograaf' ), '›' );
	section.append( prev, next );

	/** Hide the button at each end of the track. */
	function update(): void {
		const max = track.scrollWidth - track.clientWidth;
		prev.hidden = track.scrollLeft <= 0;
		next.hidden = track.scrollLeft >= max - 1;
		prev.disabled = prev.hidden;
		next.disabled = next.hidden;
	}

	/**
	 * Scroll by about one visible width.
	 *
	 * @param direction -1 for back, 1 for forward.
	 */
	function scroll( direction: number ): void {
		const reduced = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		track.scrollBy( { left: direction * track.clientWidth * 0.9, behavior: reduced ? 'auto' : 'smooth' } );
	}

	prev.addEventListener( 'click', () => scroll( -1 ) );
	next.addEventListener( 'click', () => scroll( 1 ) );
	track.addEventListener( 'scroll', update, { passive: true } );
	section.addEventListener( 'keydown', ( event ) => {
		if ( event.target !== section ) {
			return;
		}
		if ( event.key === 'ArrowLeft' || event.key === 'ArrowRight' ) {
			event.preventDefault();
			scroll( event.key === 'ArrowLeft' ? -1 : 1 );
		}
	} );
	update();
}
