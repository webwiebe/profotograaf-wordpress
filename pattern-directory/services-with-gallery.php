<?php
/**
 * Core-only pattern: services, a gallery and an enquiry form.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => 'Services with gallery and enquiry form',
	'description' => 'What you offer, a gallery of recent work and a form for enquiries.',
	'keywords'    => array( 'services', 'gallery', 'enquiry' ),
	'content'     => Patterns::group(
		Patterns::heading( 'Services' )
		. Patterns::paragraph( 'Describe the sessions you offer, such as weddings, portraits and family shoots.' )
		. profotograaf_pattern_placeholder( 'Your gallery goes here.' )
		. Patterns::heading( 'Book a session', 3 )
		. Patterns::paragraph( 'Place your enquiry form here.' )
	),
);
