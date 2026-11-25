<?php
/**
 * Core-only pattern: masonry showcase.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => 'Masonry showcase',
	'description' => 'A heading and a masonry gallery for photos of different shapes.',
	'keywords'    => array( 'portfolio', 'gallery', 'masonry' ),
	'content'     => Patterns::group(
		Patterns::heading( 'Showcase' )
		. profotograaf_pattern_placeholder( 'Your masonry gallery goes here.' )
	),
);
