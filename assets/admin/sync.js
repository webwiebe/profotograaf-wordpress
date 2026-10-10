/**
 * Check now for photo changes, and one background check from admin screens.
 *
 * The panel comes from Sync_Panel::render. A click posts to
 * /profotograaf/v1/sync/run and writes the new "last checked" text. When the
 * page asks for it (profotograafSync.background), the script posts once with
 * `background` set and never shows an error: nobody waits for that run.
 */
( function () {
	'use strict';

	var wp = window.wp;
	if ( ! wp || ! wp.apiFetch || ! wp.i18n ) {
		return;
	}
	var __ = wp.i18n.__;
	var path = '/profotograaf/v1/sync/run';

	function update( response ) {
		document.querySelectorAll( '.profotograaf-sync__text' ).forEach( function ( text ) {
			if ( response.lastCheckedText ) {
				text.textContent = response.lastCheckedText;
			}
		} );
	}

	function say( panel, message, failed ) {
		var status = panel.querySelector( '.profotograaf-sync__status' );
		if ( status ) {
			status.textContent = message;
			status.classList.toggle( 'is-error', !! failed );
		}
	}

	function message( response ) {
		if ( response.busy ) {
			return __( 'A check is already running.', 'profotograaf' );
		}
		if ( response.paused ) {
			return __( 'Checking is paused until the account is confirmed.', 'profotograaf' );
		}
		if ( ! response.ok ) {
			return __( 'The check did not finish. Try again later.', 'profotograaf' );
		}
		return __( 'Photo changes were checked.', 'profotograaf' );
	}

	function check( panel ) {
		var button = panel.querySelector( '.profotograaf-sync__button' );
		if ( ! button || button.disabled ) {
			return;
		}
		button.disabled = true;
		say( panel, __( 'Checking…', 'profotograaf' ), false );
		wp.apiFetch( { path: path, method: 'POST', data: {} } ).then(
			function ( response ) {
				button.disabled = false;
				update( response );
				say( panel, message( response ), ! response.ok && ! response.busy );
			},
			function ( error ) {
				button.disabled = false;
				say( panel, error && error.message ? error.message : __( 'The check did not finish. Try again later.', 'profotograaf' ), true );
			}
		);
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '.profotograaf-sync__button' ) : null;
		if ( button ) {
			event.preventDefault();
			check( button.closest( '.profotograaf-sync' ) );
		}
	} );

	if ( window.profotograafSync && window.profotograafSync.background ) {
		wp.apiFetch( { path: path, method: 'POST', data: { background: true } } ).then( update, function () {} );
	}
}() );
