/**
 * Re-import button in the attachment details of an imported Profotograaf photo.
 *
 * The button comes from Media_Import_Screen::source_field. A click posts the
 * attachment id to /profotograaf/v1/photos/reimport and reports the result
 * next to the button. The media modal re-renders the fields after a refresh,
 * so the handler sits on the document and the message is written to whatever
 * status element exists for the attachment at that moment.
 */
( function () {
	'use strict';

	var wp = window.wp;
	if ( ! wp || ! wp.apiFetch || ! wp.i18n ) {
		return;
	}
	var __ = wp.i18n.__;

	function show( id, message, failed ) {
		var status = document.querySelector( '.profotograaf-reimport__status[data-attachment-id="' + id + '"]' );
		if ( status ) {
			status.textContent = message;
			status.classList.toggle( 'is-error', !! failed );
		}
	}

	function done( id, button, message, failed ) {
		button.disabled = false;
		show( id, message, failed );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '.profotograaf-reimport__button' ) : null;
		if ( ! button || button.disabled ) {
			return;
		}
		event.preventDefault();
		var id = parseInt( button.getAttribute( 'data-attachment-id' ), 10 );
		if ( ! id ) {
			return;
		}
		button.disabled = true;
		show( id, __( 'Re-importing…', 'profotograaf' ), false );
		wp.apiFetch( {
			path: '/profotograaf/v1/photos/reimport',
			method: 'POST',
			data: { attachment_id: id },
		} ).then(
			function () {
				var message = __( 'The photo was replaced with the current version from Profotograaf.', 'profotograaf' );
				if ( wp.media && wp.media.attachment ) {
					// The refresh re-renders the fields, so the message goes in afterwards.
					wp.media.attachment( id ).fetch().always( function () {
						done( id, button, message, false );
						show( id, message, false );
					} );
					return;
				}
				done( id, button, message, false );
			},
			function ( error ) {
				done( id, button, error && error.message ? error.message : __( 'The photo could not be re-imported.', 'profotograaf' ), true );
			}
		);
	} );
}() );
