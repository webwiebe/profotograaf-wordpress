<?php
/**
 * Core-only pattern: client portal call to action.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => 'Client portal call to action',
	'description' => 'A button that takes clients to their private galleries.',
	'keywords'    => array( 'client', 'portal', 'login' ),
	'content'     => Patterns::group(
		Patterns::heading( 'Find your gallery' )
		. Patterns::paragraph( 'Sign in to the client portal to see your photos.' )
		. profotograaf_pattern_button( 'Open my gallery' )
	),
);
