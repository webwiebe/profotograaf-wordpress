<?php
/**
 * Pattern: a wedding story with a slideshow.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Wedding story with slideshow', 'profotograaf' ),
	'description' => __( 'A heading, the story of one wedding and a slideshow of its photos.', 'profotograaf' ),
	'keywords'    => array( __( 'wedding', 'profotograaf' ), __( 'story', 'profotograaf' ), __( 'slideshow', 'profotograaf' ) ),
	'content'     => Patterns::group(
		Patterns::heading( __( 'Anna and Tom, a day in June', 'profotograaf' ) )
		. Patterns::paragraph( __( 'Tell the story of the day in a few lines. Choose the wedding gallery in the block settings.', 'profotograaf' ) )
		. Patterns::block(
			'profotograaf/gallery',
			array(
				'layout'   => 'slideshow',
				'captions' => 'off',
				'lightbox' => 'on',
			)
		)
	),
);
