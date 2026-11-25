<?php
/**
 * Core-only pattern: portfolio grid section.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => 'Portfolio grid',
	'description' => 'A heading, a short introduction and a gallery grid.',
	'keywords'    => array( 'portfolio', 'gallery', 'grid' ),
	'content'     => Patterns::group(
		Patterns::heading( 'Selected work' )
		. Patterns::paragraph( 'A selection of recent sessions.' )
		. profotograaf_pattern_placeholder( 'Your gallery grid goes here.' )
	),
);
