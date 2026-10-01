<?php
/**
 * Pattern: portfolio grid section.
 *
 * @package Profotograaf
 */

use Profotograaf\Modules\Patterns;

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Portfolio grid', 'profotograaf' ),
	'description' => __( 'A heading, a short introduction and a gallery grid.', 'profotograaf' ),
	'keywords'    => array( __( 'portfolio', 'profotograaf' ), __( 'gallery', 'profotograaf' ), __( 'grid', 'profotograaf' ) ),
	'content'     => Patterns::group(
		Patterns::heading( __( 'Selected work', 'profotograaf' ) )
		. Patterns::paragraph( __( 'A selection of recent sessions. Choose a gallery in the block settings.', 'profotograaf' ) )
		. Patterns::block(
			'profotograaf/gallery',
			array(
				'layout'        => 'grid',
				'columns'       => '3',
				'columnsTablet' => '2',
				'columnsMobile' => '1',
				'gap'           => '16',
				'ratio'         => '3-2',
				'captions'      => 'off',
				'lightbox'      => 'on',
			)
		)
	),
);
