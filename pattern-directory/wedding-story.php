<?php
/**
 * Core-only pattern: a wedding story with a slideshow.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => 'Wedding story with slideshow',
	'description' => 'A heading, the story of one wedding and a slideshow of its photos.',
	'keywords'    => array( 'wedding', 'story', 'slideshow' ),
	'content'     => Patterns::group(
		Patterns::heading( 'Anna and Tom, a day in June' )
		. Patterns::paragraph( 'Tell the story of the day in a few lines.' )
		. profotograaf_pattern_placeholder( 'Your wedding slideshow goes here.' )
	),
);
