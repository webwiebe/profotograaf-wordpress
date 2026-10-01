( function () {
	'use strict';

	var config = window.profotograafSettings;
	var panel = document.getElementById( 'profotograaf-pairing' );
	if ( ! config || ! panel ) {
		return;
	}

	var status = panel.querySelector( '.profotograaf-pairing__status' );
	var __ = window.wp.i18n.__;
	var failures = 0;
	var maxFailures = 5;

	function errorText() {
		return __( 'Could not check the connection. Reload this page to try again.', 'profotograaf' );
	}

	function schedule( seconds ) {
		var delay = Math.max( 1, seconds ) * 1000;
		window.setTimeout( poll, delay );
	}

	function poll() {
		var body = new URLSearchParams();
		body.set( 'action', 'profotograaf_poll' );
		body.set( 'nonce', config.nonce );

		window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				var interval = parseInt( panel.getAttribute( 'data-interval' ), 10 ) || 5;
				if ( ! payload || ! payload.success ) {
					failures += 1;
					if ( failures >= maxFailures ) {
						status.textContent = errorText();
						return;
					}
					schedule( interval );
					return;
				}
				failures = 0;
				if ( 'pending' === payload.data.status ) {
					interval = payload.data.interval || interval;
					panel.setAttribute( 'data-interval', String( interval ) );
					schedule( interval );
					return;
				}
				// approved, denied, expired or none: the server left a notice.
				window.location.reload();
			} )
			.catch( function () {
				failures += 1;
				if ( failures >= maxFailures ) {
					status.textContent = errorText();
					return;
				}
				schedule( parseInt( panel.getAttribute( 'data-interval' ), 10 ) || 5 );
			} );
	}

	schedule( parseInt( panel.getAttribute( 'data-interval' ), 10 ) || 5 );
}() );
