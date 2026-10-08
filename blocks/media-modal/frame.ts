import { __ } from '@wordpress/i18n';
import { Panel } from './panel';
import { createSelectionPort } from './selection-port';
import { Session } from './session';
import type { FrameLike, MaybeMedia, MediaGlobal, RegionLike, RouterView } from './types';

/** Mode name of the tab: the router item id and the content mode. */
export const MODE = 'profotograaf';

const PRIMARY_BUTTON = '.media-toolbar-primary .button-primary';

/** Whether a library filter on `type` can list images. No filter lists everything. */
export function allowsImages( type: unknown ): boolean {
	if ( Array.isArray( type ) ) {
		return type.length === 0 || type.some( allowsImages );
	}
	return typeof type !== 'string' || '' === type || type === 'image' || type.startsWith( 'image/' );
}

function libraryType( frame: FrameLike ): unknown {
	const library = frame.state().get( 'library' ) as { props?: { get?: ( key: string ) => unknown } } | undefined;
	return library?.props?.get?.( 'type' );
}

/**
 * Runs the import before the frame's own primary button acts. The capture
 * listener stops the click, waits for the session to swap the picked photos
 * for attachments and then repeats the click, so every toolbar of every frame
 * (Select, Insert, Add to gallery, Set featured image) works unchanged.
 */
function guardConfirm( frame: FrameLike, session: Session, isActive: () => boolean ): void {
	let repeating = false;
	frame.el.addEventListener(
		'click',
		( event ) => {
			const target = event.target instanceof Element ? event.target.closest< HTMLButtonElement >( PRIMARY_BUTTON ) : null;
			if ( repeating || ! target || ! isActive() || 0 === session.pending().length ) {
				return;
			}
			event.stopImmediatePropagation();
			event.preventDefault();
			target.disabled = true;
			void session.confirm().then( ( proceed ) => {
				target.disabled = false;
				if ( ! proceed ) {
					return;
				}
				repeating = true;
				try {
					target.click();
				} finally {
					repeating = false;
				}
			} );
		},
		true
	);
}

function tabView( media: MediaGlobal, panel: Panel ): unknown {
	const base = media.View as unknown as { prototype: { remove: () => unknown } };
	const View = media.View.extend( {
		className: 'profotograaf-modal-wrap',
		render() {
			( this as unknown as { el: HTMLElement } ).el.replaceChildren( panel.element );
			return this;
		},
		remove() {
			panel.destroy();
			return base.prototype.remove.call( this );
		},
	} );
	return new View( {} );
}

/** Adds the tab to one media frame. */
export function attach( frame: FrameLike, media: MediaGlobal ): void {
	const session = new Session( createSelectionPort( frame, media ) );
	let active = false;

	frame.on( 'router:render:browse', ( router: RouterView ) => {
		if ( allowsImages( libraryType( frame ) ) ) {
			router.set( { [ MODE ]: { text: __( 'Profotograaf', 'profotograaf' ), priority: 60 } } );
		}
	} );

	frame.on( `content:create:${ MODE }`, ( region: RegionLike ) => {
		frame.el.classList.remove( 'hide-toolbar' );
		region.view = tabView( media, new Panel( session ) );
	} );

	frame.on( `content:activate:${ MODE }`, () => {
		active = true;
		session.reveal();
	} );

	frame.on( `content:deactivate:${ MODE }`, () => {
		active = false;
		session.conceal();
	} );

	frame.on( 'close', () => session.reset() );
	guardConfirm( frame, session, () => active );
}

const patched = new WeakSet< object >();

/**
 * Adds the tab to every frame built on MediaFrame.Select, which includes
 * MediaFrame.Post and the frames of the block editor. Post and the editor's
 * frames call Select's bindHandlers, so patching it once reaches them all.
 */
export function install( media: MediaGlobal | undefined ): void {
	const proto = ( media as MaybeMedia | undefined )?.view?.MediaFrame?.Select?.prototype;
	if ( ! media || ! proto || patched.has( proto ) ) {
		return;
	}
	patched.add( proto );
	const original = proto.bindHandlers;
	proto.bindHandlers = function ( this: FrameLike, ...args: unknown[] ) {
		original.apply( this, args );
		attach( this, media );
	};
}
