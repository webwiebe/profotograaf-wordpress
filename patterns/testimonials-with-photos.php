<?php
/**
 * Pattern: testimonials next to photos.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Testimonials with photos', 'profotograaf' ),
	'description' => __( 'Two client quotes followed by a small gallery of their sessions.', 'profotograaf' ),
	'keywords'    => array( __( 'testimonials', 'profotograaf' ), __( 'reviews', 'profotograaf' ), __( 'photos', 'profotograaf' ) ),
	'content'     => Patterns::group(
		Patterns::heading( __( 'Kind words', 'profotograaf' ) )
		. Patterns::quote( __( 'Replace this with a quote from a happy client.', 'profotograaf' ), __( 'Client name', 'profotograaf' ) )
		. Patterns::quote( __( 'Replace this with a second quote.', 'profotograaf' ), __( 'Another client', 'profotograaf' ) )
		. Patterns::block(
			'profotograaf/gallery',
			array(
				'layout'        => 'grid',
				'columns'       => '4',
				'columnsTablet' => '2',
				'columnsMobile' => '2',
				'gap'           => '8',
				'ratio'         => '1-1',
				'captions'      => 'off',
				'perPage'       => '4',
				'loadMore'      => 'off',
				'lightbox'      => 'on',
			)
		)
	),
);
