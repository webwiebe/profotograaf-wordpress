<?php
/**
 * The [profotograaf_gallery] shortcode.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * `[profotograaf_gallery id="..." layout="grid"]` for the classic editor and
 * page builders. It renders through Gallery_Renderer, like the block.
 *
 * Optional `title` and `url` set the fallback link and save the lookup.
 */
class Shortcode {

	public const TAG = 'profotograaf_gallery';

	/**
	 * Renderer.
	 *
	 * @var Gallery_Renderer
	 */
	private Gallery_Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param Gallery_Renderer $renderer Renderer.
	 */
	public function __construct( Gallery_Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Registers the shortcode.
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string,string>|string $atts Attributes as WordPress passes them.
	 * @return string HTML.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'     => '',
				'layout' => '',
				'title'  => '',
				'url'    => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		return $this->renderer->render( $atts );
	}
}
