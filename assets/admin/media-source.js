/**
 * Source filter in the media grid and the media modal.
 *
 * Adds a dropdown next to the other attachment filters. Choosing
 * "Profotograaf" sends profotograaf_source=profotograaf with the grid query,
 * which Media_Import_Screen::filter_query turns into a meta query.
 */
( function () {
	'use strict';

	var media = window.wp && window.wp.media;
	if ( ! media || ! media.view || ! media.view.AttachmentFilters || ! media.view.AttachmentsBrowser ) {
		return;
	}

	var __ = window.wp.i18n.__;

	var SourceFilter = media.view.AttachmentFilters.extend( {
		id: 'profotograaf-source-filter',

		createFilters: function () {
			this.filters = {
				all: {
					text: __( 'All sources', 'profotograaf' ),
					props: { profotograaf_source: null },
					priority: 10,
				},
				profotograaf: {
					text: __( 'Profotograaf', 'profotograaf' ),
					props: { profotograaf_source: 'profotograaf' },
					priority: 20,
				},
			};
		},
	} );

	var Browser = media.view.AttachmentsBrowser;
	media.view.AttachmentsBrowser = Browser.extend( {
		createToolbar: function () {
			Browser.prototype.createToolbar.apply( this, arguments );
			this.toolbar.set(
				'profotograafSource',
				new SourceFilter( {
					controller: this.controller,
					model: this.collection.props,
					priority: -60,
				} ).render()
			);
		},
	} );
}() );
