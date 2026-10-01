<?php
/**
 * Pattern: contact page with an enquiry form and a gallery.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

/**
 * Filters the block markup of the enquiry form in the contact page pattern.
 *
 * The default is a paragraph that asks the editor to insert the form of their
 * form plugin (Contact Form 7 or Gravity Forms). Return the form's block markup
 * to ship a ready made form.
 *
 * @param string $markup Block markup.
 */
$profotograaf_form = (string) apply_filters(
	'profotograaf_pattern_enquiry_form',
	Patterns::paragraph( __( 'Replace this text with your enquiry form block.', 'profotograaf' ) )
);

return array(
	'title'       => __( 'Contact page with enquiry form', 'profotograaf' ),
	'description' => __( 'An introduction, a place for your enquiry form and a small gallery.', 'profotograaf' ),
	'keywords'    => array( __( 'contact', 'profotograaf' ), __( 'enquiry', 'profotograaf' ), __( 'form', 'profotograaf' ) ),
	'content'     => Patterns::group(
		Patterns::heading( __( 'Get in touch', 'profotograaf' ) )
		. Patterns::paragraph( __( 'Tell me about your plans and I will reply within two working days.', 'profotograaf' ) )
		. $profotograaf_form
		. Patterns::heading( __( 'A look at my work', 'profotograaf' ), 3 )
		. Patterns::block(
			'profotograaf/gallery',
			array(
				'layout'        => 'grid',
				'columns'       => '3',
				'columnsTablet' => '2',
				'columnsMobile' => '1',
				'gap'           => '12',
				'ratio'         => '1-1',
				'captions'      => 'off',
				'perPage'       => '6',
				'loadMore'      => 'off',
				'lightbox'      => 'on',
			)
		)
	),
);
