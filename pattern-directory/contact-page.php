<?php
/**
 * Core-only pattern: contact page.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => 'Contact page with enquiry form',
	'description' => 'An introduction, a place for your enquiry form and a small gallery.',
	'keywords'    => array( 'contact', 'enquiry', 'form' ),
	'content'     => Patterns::group(
		Patterns::heading( 'Get in touch' )
		. Patterns::paragraph( 'Tell me about your plans and I will reply within two working days.' )
		. Patterns::paragraph( 'Place your enquiry form here.' )
		. Patterns::heading( 'A look at my work', 3 )
		. profotograaf_pattern_placeholder( 'Your small gallery goes here.' )
	),
);
