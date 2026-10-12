/**
 * Keep, Stop watching, Watch again and Remove buttons in the attachment
 * details of an imported Profotograaf photo.
 *
 * The buttons come from Sync_Field. Remove asks for a confirmation that names
 * the number of posts that use the file, and only then posts `confirm: true`.
 * The media modal re-renders the fields after a refresh, so the handler sits
 * on the document and writes to whatever status element exists at that moment.
 */
( function () {
	'use strict';

	var wp = window.wp;
	if ( ! wp || ! wp.apiFetch || ! wp.i18n ) {
		return;
	}
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	var ROUTES = { keep: '/profotograaf/v1/sync/keep', watch: '/profotograaf/v1/sync/watch' };

	function show( id, message, failed ) {
		var status = document.querySelector( '.profotograaf-sync__status[data-attachment-id="' + id + '"]' );
		if ( status ) {
			status.textContent = message;
			status.classList.toggle( 'is-error', !! failed );
		}
	}

	function refresh( id, message ) {
		if ( wp.media && wp.media.attachment ) {
			wp.media.attachment( id ).fetch().always( function () {
				show( id, message, false );
			} );
			return;
		}
		window.location.reload();
	}

	function failure( id, button ) {
		return function ( error ) {
			button.disabled = false;
			show( id, error && error.message ? error.message : __( 'That did not work. Try again.', 'profotograaf' ), true );
		};
	}

	function confirmText( usage ) {
		var count = usage.count;
		var used = count
			? sprintf(
				/* translators: %d: number of posts that use the photo. */
				_n( 'This photo is used in %d post.', 'This photo is used in %d posts.', count, 'profotograaf' ),
				count
			) + ( usage.capped ? ' ' + __( 'There may be more.', 'profotograaf' ) : '' )
			: __( 'No post uses this photo.', 'profotograaf' );
		var what = usage.trashes
			? __( 'Move this copy to the trash?', 'profotograaf' )
			: __( 'Delete this copy from the Media Library? This cannot be undone.', 'profotograaf' );
		return used + ' ' + what + ' ' + __( 'The photo on Profotograaf is not touched.', 'profotograaf' );
	}

	function remove( id, button ) {
		wp.apiFetch( { path: '/profotograaf/v1/sync/usage?attachment_id=' + id } ).then( function ( usage ) {
			if ( ! window.confirm( confirmText( usage ) ) ) {
				button.disabled = false;
				show( id, '', false );
				return;
			}
			wp.apiFetch( {
				path: '/profotograaf/v1/sync/remove',
				method: 'POST',
				data: { attachment_id: id, confirm: true },
			} ).then( function ( result ) {
				var message = result.trashed
					? __( 'The copy was moved to the trash.', 'profotograaf' )
					: __( 'The copy was removed.', 'profotograaf' );
				show( id, message, false );
				if ( wp.media && wp.media.frame && wp.media.frame.close ) {
					wp.media.frame.close();
				}
			}, failure( id, button ) );
		}, failure( id, button ) );
	}

	function bulk( button ) {
		var stop = document.querySelector( '.profotograaf-bulk__stop' );
		var status = document.querySelector( '.profotograaf-bulk__status' );
		var count = parseInt( button.getAttribute( 'data-missing' ), 10 ) || 0;
		var sample = button.getAttribute( 'data-sample' );
		var question = sprintf(
			/* translators: %d: number of copies. */
			_n( 'Remove %d copy that was removed on Profotograaf from the Media Library?', 'Remove %d copies that were removed on Profotograaf from the Media Library?', count, 'profotograaf' ),
			count
		);
		if ( sample ) {
			question += ' ' + sprintf(
				/* translators: %s: file names. */
				__( 'For example: %s.', 'profotograaf' ),
				sample
			);
		}
		question += ' ' + __( 'The photos on Profotograaf are not touched.', 'profotograaf' );
		if ( ! window.confirm( question ) ) {
			return;
		}
		var stopped = false;
		var removed = 0;
		button.disabled = true;
		stop.hidden = false;
		stop.onclick = function () {
			stopped = true;
		};
		function finish( message ) {
			button.disabled = false;
			stop.hidden = true;
			status.textContent = message;
		}
		function next() {
			if ( stopped ) {
				finish( sprintf(
					/* translators: %d: number of copies. */
					__( 'Stopped. %d removed.', 'profotograaf' ),
					removed
				) );
				return;
			}
			wp.apiFetch( { path: '/profotograaf/v1/sync/remove-missing', method: 'POST', data: { confirm: true } } ).then( function ( result ) {
				removed += result.removed;
				status.textContent = sprintf(
					/* translators: %d: number of copies. */
					__( '%d removed…', 'profotograaf' ),
					removed
				);
				if ( result.removed > 0 && result.remaining > 0 ) {
					next();
					return;
				}
				finish( sprintf(
					/* translators: %d: number of copies. */
					__( 'Done. %d removed. Reload the page to see what is left.', 'profotograaf' ),
					removed
				) );
			}, function ( error ) {
				finish( error && error.message ? error.message : __( 'That did not work. Try again.', 'profotograaf' ) );
			} );
		}
		next();
	}

	document.addEventListener( 'click', function ( event ) {
		var all = event.target && event.target.closest ? event.target.closest( '.profotograaf-bulk__remove' ) : null;
		if ( all && ! all.disabled ) {
			event.preventDefault();
			bulk( all );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '.profotograaf-sync__button' ) : null;
		if ( ! button || button.disabled ) {
			return;
		}
		event.preventDefault();
		var id = parseInt( button.getAttribute( 'data-attachment-id' ), 10 );
		var action = button.getAttribute( 'data-action' );
		if ( ! id ) {
			return;
		}
		button.disabled = true;
		if ( 'remove' === action ) {
			remove( id, button );
			return;
		}
		if ( ! ROUTES[ action ] ) {
			button.disabled = false;
			return;
		}
		wp.apiFetch( { path: ROUTES[ action ], method: 'POST', data: { attachment_id: id } } ).then( function () {
			refresh( id, 'keep' === action ? __( 'Kept as this site\'s own file.', 'profotograaf' ) : __( 'Watching this photo again.', 'profotograaf' ) );
		}, failure( id, button ) );
	} );
}() );
