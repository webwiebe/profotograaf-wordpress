/**
 * Keep, Stop watching, Watch again, Remove and Review buttons in the
 * attachment details of an imported Profotograaf photo.
 *
 * Review opens the conflict dialog: Keep mine, Use Profotograaf's version and
 * Keep both for the file, and per-field "Update text". Nothing from the
 * platform is applied until a button in the dialog is clicked.
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

	function el( tag, text, className ) {
		var node = document.createElement( tag );
		if ( text ) {
			node.textContent = text;
		}
		if ( className ) {
			node.className = className;
		}
		return node;
	}

	function figure( label, url ) {
		var fig = el( 'figure' );
		if ( url ) {
			var img = el( 'img' );
			img.src = url;
			img.alt = label;
			fig.appendChild( img );
		}
		fig.appendChild( el( 'figcaption', label ) );
		return fig;
	}

	function resolve( id, choice, close, message, status ) {
		status.textContent = __( 'Working…', 'profotograaf' );
		wp.apiFetch( {
			path: '/profotograaf/v1/sync/resolve',
			method: 'POST',
			data: { attachment_id: id, choice: choice, confirm: true },
		} ).then( function () {
			close();
			refresh( id, message );
		}, function ( error ) {
			status.textContent = error && error.message ? error.message : __( 'That did not work. Try again.', 'profotograaf' );
		} );
	}

	function fileSection( dialog, data, id, status, close ) {
		var box = el( 'div' );
		box.appendChild( el( 'h3', __( 'File', 'profotograaf' ) ) );
		box.appendChild( el( 'p', __( 'The photo on Profotograaf has a newer file.', 'profotograaf' ) ) );
		if ( data.file_edited ) {
			var warning = el( 'p', '', 'profotograaf-resolve__warning' );
			warning.appendChild( document.createTextNode( __( 'You edited the file on this site. Using the version from Profotograaf replaces your edit and it cannot be brought back.', 'profotograaf' ) + ' ' ) );
			if ( data.site_file_url ) {
				var link = el( 'a', __( 'Download my version first', 'profotograaf' ) );
				link.href = data.site_file_url;
				link.setAttribute( 'download', '' );
				warning.appendChild( link );
			}
			box.appendChild( warning );
		}
		var actions = el( 'div', '', 'profotograaf-resolve__actions' );
		function choice( label, hint, value, message ) {
			var wrap = el( 'div' );
			var button = el( 'button', label, 'button' );
			button.type = 'button';
			button.setAttribute( 'data-choice', value );
			button.addEventListener( 'click', function () {
				if ( 'use_remote' === value && data.file_edited && ! window.confirm( __( 'Replace your edited file with the version from Profotograaf? Your edit is lost.', 'profotograaf' ) ) ) {
					return;
				}
				resolve( id, value, close, message, status );
			} );
			wrap.appendChild( button );
			wrap.appendChild( el( 'p', hint, 'description' ) );
			actions.appendChild( wrap );
		}
		choice( __( 'Keep mine', 'profotograaf' ), __( 'Keeps the file on this site. Later changes on Profotograaf are flagged again.', 'profotograaf' ), 'keep_mine', __( 'Kept your version.', 'profotograaf' ) );
		choice( __( 'Use Profotograaf\'s version', 'profotograaf' ), __( 'Replaces the file on this site.', 'profotograaf' ), 'use_remote', __( 'The photo was replaced with the current version from Profotograaf.', 'profotograaf' ) );
		choice( __( 'Keep both', 'profotograaf' ), __( 'Saves your version as a separate file, then replaces this one. Costs one extra file.', 'profotograaf' ), 'keep_both', __( 'Your version was saved as a separate file and this photo was replaced.', 'profotograaf' ) );
		box.appendChild( actions );
		dialog.appendChild( box );
	}

	function textSection( dialog, data, id, status, close ) {
		var box = el( 'div' );
		box.appendChild( el( 'h3', __( 'Text', 'profotograaf' ) ) );
		var table = el( 'table' );
		var head = el( 'tr' );
		[ __( 'Field', 'profotograaf' ), __( 'This site', 'profotograaf' ), __( 'Profotograaf', 'profotograaf' ), __( 'Use Profotograaf\'s', 'profotograaf' ) ].forEach( function ( label ) {
			head.appendChild( el( 'th', label ) );
		} );
		table.appendChild( head );
		var boxes = [];
		data.fields.forEach( function ( field ) {
			var row = el( 'tr' );
			row.appendChild( el( 'td', field.label ) );
			row.appendChild( el( 'td', field.site || __( '(empty)', 'profotograaf' ) ) );
			row.appendChild( el( 'td', field.platform || __( '(empty)', 'profotograaf' ) ) );
			var cell = el( 'td' );
			if ( field.takeable ) {
				var input = el( 'input' );
				input.type = 'checkbox';
				input.value = field.key;
				// A copy edited on this site keeps its text until the author ticks a field.
				input.checked = 'changed' === data.state;
				input.setAttribute( 'aria-label', field.label );
				cell.appendChild( input );
				boxes.push( input );
			} else {
				cell.textContent = __( 'Kept: Profotograaf has no value', 'profotograaf' );
			}
			row.appendChild( cell );
			table.appendChild( row );
		} );
		box.appendChild( table );
		var button = el( 'button', __( 'Update text', 'profotograaf' ), 'button' );
		button.type = 'button';
		button.setAttribute( 'data-choice', 'update_text' );
		button.addEventListener( 'click', function () {
			var fields = boxes.filter( function ( input ) {
				return input.checked;
			} ).map( function ( input ) {
				return input.value;
			} );
			status.textContent = __( 'Working…', 'profotograaf' );
			wp.apiFetch( {
				path: '/profotograaf/v1/sync/update-text',
				method: 'POST',
				data: { attachment_id: id, fields: fields },
			} ).then( function () {
				close();
				refresh( id, __( 'The text was updated.', 'profotograaf' ) );
			}, function ( error ) {
				status.textContent = error && error.message ? error.message : __( 'That did not work. Try again.', 'profotograaf' );
			} );
		} );
		box.appendChild( button );
		dialog.appendChild( box );
	}

	function review( id, button ) {
		wp.apiFetch( { path: '/profotograaf/v1/sync/compare?attachment_id=' + id } ).then( function ( data ) {
			var dialog = el( 'dialog', '', 'profotograaf-resolve' );
			var title = el( 'h2', 'conflict' === data.state ? __( 'Resolve conflict', 'profotograaf' ) : __( 'Review changes', 'profotograaf' ) );
			title.id = 'profotograaf-resolve-title';
			dialog.setAttribute( 'aria-labelledby', title.id );
			dialog.appendChild( title );
			if ( 'conflict' === data.state ) {
				dialog.appendChild( el( 'p', __( 'This photo was changed on Profotograaf and on this site. Nothing changes until you choose.', 'profotograaf' ) ) );
			}
			var images = el( 'div', '', 'profotograaf-resolve__images' );
			images.appendChild( figure( __( 'This site', 'profotograaf' ), data.site_url ) );
			images.appendChild( figure( __( 'Profotograaf', 'profotograaf' ), data.platform_thumb || data.platform_url ) );
			dialog.appendChild( images );
			var status = el( 'p', '', 'profotograaf-resolve__status' );
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
			function close() {
				dialog.close();
				dialog.remove();
				button.disabled = false;
			}
			if ( data.file_changed ) {
				fileSection( dialog, data, id, status, close );
			}
			if ( data.fields.length ) {
				textSection( dialog, data, id, status, close );
			}
			if ( ! data.file_changed && ! data.fields.length ) {
				dialog.appendChild( el( 'p', __( 'No difference is left to resolve.', 'profotograaf' ) ) );
			}
			dialog.appendChild( status );
			var cancel = el( 'button', __( 'Close', 'profotograaf' ), 'button-link' );
			cancel.type = 'button';
			cancel.addEventListener( 'click', close );
			dialog.appendChild( cancel );
			dialog.addEventListener( 'close', function () {
				button.disabled = false;
			} );
			document.body.appendChild( dialog );
			dialog.showModal();
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
		if ( 'review' === action ) {
			review( id, button );
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
