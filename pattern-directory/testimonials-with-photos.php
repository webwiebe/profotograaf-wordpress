<?php
/**
 * Core-only pattern: testimonials next to photos.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => 'Testimonials with photos',
	'description' => 'Two client quotes followed by a small gallery of their sessions.',
	'keywords'    => array( 'testimonials', 'reviews', 'photos' ),
	'content'     => Patterns::group(
		Patterns::heading( 'Kind words' )
		. Patterns::quote( 'Replace this with a quote from a happy client.', 'Client name' )
		. Patterns::quote( 'Replace this with a second quote.', 'Another client' )
		. profotograaf_pattern_placeholder( 'Photos of these sessions go here.' )
	),
);
