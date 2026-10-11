import { __ } from '@wordpress/i18n';

/** What the server adds to an attachment that has no file on this site. */
export interface NotEditable {
	convertUrl?: string;
}

interface ModelLike {
	get: ( key: string ) => unknown;
}

/** The attachment view of wp.media, as far as this module touches it. */
export interface DetailsView {
	el: HTMLElement;
	model?: ModelLike;
}

export interface DetailsPrototype {
	render: ( this: DetailsView, ...args: unknown[] ) => unknown;
}

export interface MaybeMedia {
	view?: { Attachment?: { Details?: { prototype?: DetailsPrototype } } };
}

/** The explanation that takes the place of an Edit Image button. */
export function note( info: NotEditable ): HTMLElement {
	const box = document.createElement( 'div' );
	box.className = 'profotograaf-not-editable';
	const text = document.createElement( 'p' );
	text.className = 'description';
	text.textContent = __(
		'This photo is kept on Profotograaf, so this site has no file to edit. Convert it to local to crop, rotate or scale it.',
		'profotograaf'
	);
	box.append( text );
	if ( info.convertUrl ) {
		const link = document.createElement( 'a' );
		link.className = 'button';
		link.href = info.convertUrl;
		link.textContent = __( 'Convert to local', 'profotograaf' );
		box.append( link );
	}
	return box;
}

/** Replaces each Edit Image button of a rendered view that shows a virtual attachment. */
export function replaceEditButtons( view: DetailsView ): void {
	const info = view.model?.get( 'profotograafNotEditable' );
	if ( ! info || typeof info !== 'object' ) {
		return;
	}
	view.el.querySelectorAll( '.edit-attachment' ).forEach( ( button ) => {
		button.replaceWith( note( info ) );
	} );
}

/**
 * Wraps the render of the attachment details. The two-column view of the
 * Media Library calls this render too, so both views are covered.
 */
export function install( media: MaybeMedia | undefined ): boolean {
	const prototype = media?.view?.Attachment?.Details?.prototype;
	if ( ! prototype ) {
		return false;
	}
	const render = prototype.render;
	prototype.render = function ( this: DetailsView, ...args: unknown[] ) {
		const result = render.apply( this, args );
		replaceEditButtons( this );
		return result;
	};
	return true;
}
