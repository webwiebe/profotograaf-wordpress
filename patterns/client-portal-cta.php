<?php
/**
 * Pattern: client portal call to action.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Client portal call to action', 'profotograaf' ),
	'description' => __( 'A button that takes clients to their private galleries.', 'profotograaf' ),
	'keywords'    => array( __( 'client', 'profotograaf' ), __( 'portal', 'profotograaf' ), __( 'login', 'profotograaf' ) ),
	'content'     => Patterns::group(
		Patterns::block(
			'profotograaf/client-galleries',
			array(
				'headingLevel' => 2,
				'heading'      => __( 'Find your gallery', 'profotograaf' ),
				'description'  => __( 'Sign in to the client portal to see your photos.', 'profotograaf' ),
				'buttonLabel'  => __( 'Open my gallery', 'profotograaf' ),
			)
		)
	),
);
