( function () {
	'use strict';

	// The field mapping only matters while the form is switched on.
	document.querySelectorAll( '[data-profotograaf-form]' ).forEach( function ( form ) {
		var toggle = form.querySelector( '[data-profotograaf-toggle]' );
		var map = form.querySelector( '[data-profotograaf-map]' );
		if ( ! toggle || ! map ) {
			return;
		}
		function sync() {
			map.hidden = ! toggle.checked;
		}
		toggle.addEventListener( 'change', sync );
		sync();
	} );
}() );
