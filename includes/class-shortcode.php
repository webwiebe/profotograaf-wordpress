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
 * Optional `title` and `url` set the fallback link and save the lookup. The
 * display options (columns, columns_tablet, columns_mobile, gap, ratio,
 * captions, sort, per_page, load_more and lightbox) override the site default.
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
		$atts    = shortcode_atts(
			array(
				'id'     => '',
				'layout' => '',
				'title'  => '',
				'url'    => '',
			) + Gallery_Renderer::option_defaults(),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		$options = array_intersect_key( $atts, Gallery_Renderer::option_defaults() );
		return $this->renderer->render( array_diff_key( $atts, $options ), $options );
	}
}
