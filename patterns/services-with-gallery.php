<?php
/**
 * Pattern: services, a gallery and an enquiry form.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Services with gallery and enquiry form', 'profotograaf' ),
	'description' => __( 'What you offer, a gallery of recent work and a form for enquiries.', 'profotograaf' ),
	'keywords'    => array( __( 'services', 'profotograaf' ), __( 'gallery', 'profotograaf' ), __( 'enquiry', 'profotograaf' ) ),
	'content'     => Patterns::group(
		Patterns::heading( __( 'Services', 'profotograaf' ) )
		. Patterns::paragraph( __( 'Describe the sessions you offer, such as weddings, portraits and family shoots.', 'profotograaf' ) )
		. Patterns::block(
			'profotograaf/gallery',
			array(
				'layout'        => 'grid',
				'columns'       => '3',
				'columnsTablet' => '2',
				'columnsMobile' => '1',
				'gap'           => '12',
				'ratio'         => '3-2',
				'captions'      => 'off',
				'perPage'       => '6',
				'loadMore'      => 'off',
				'lightbox'      => 'on',
			)
		)
		. Patterns::heading( __( 'Book a session', 'profotograaf' ), 3 )
		. Patterns::block( 'profotograaf/enquiry' )
	),
);
