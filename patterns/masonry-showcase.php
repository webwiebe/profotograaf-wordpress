<?php
/**
 * Pattern: masonry showcase.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Masonry showcase', 'profotograaf' ),
	'description' => __( 'A wide gallery in masonry layout that keeps every photo as shot.', 'profotograaf' ),
	'keywords'    => array( __( 'masonry', 'profotograaf' ), __( 'showcase', 'profotograaf' ), __( 'gallery', 'profotograaf' ) ),
	'content'     => Patterns::group(
		Patterns::heading( __( 'Showcase', 'profotograaf' ) )
		. Patterns::block(
			'profotograaf/gallery',
			array(
				'layout'        => 'masonry',
				'columns'       => '4',
				'columnsTablet' => '3',
				'columnsMobile' => '2',
				'gap'           => '8',
				'ratio'         => 'original',
				'captions'      => 'overlay',
				'lightbox'      => 'on',
			)
		)
	),
);
