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
 * Optional `class` adds CSS classes to the wrapper and `align` (wide or full)
 * adds the matching alignment class. The display options (columns,
 * columns_tablet, columns_mobile, gap, ratio, captions, sort, per_page,
 * load_more, lightbox, duotone and link_to) override the site default. `ratio`
 * crops the photos. `duotone` is two hex colours such as "#1a1a2e,#f5c542".
 * `image_text` sets a caption and alt text per photo as JSON, for example
 * image_text='[{"id":"p1","caption":"Sunrise","alt":"Sun over the sea"}]'.
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
				'class'  => '',
				'align'  => '',
			) + Gallery_Renderer::option_defaults(),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		$options = array_intersect_key( $atts, Gallery_Renderer::option_defaults() );
		$args    = array_diff_key( $atts, $options );
		$extra   = $this->classes( (string) $args['class'], (string) $args['align'] );
		unset( $args['align'] );
		return $this->renderer->render( array_merge( $args, array( 'class' => $extra ) ), $options );
	}

	/**
	 * The extra classes for the wrapper: the `class` value cleaned per class,
	 * plus `alignwide` or `alignfull` for the `align` value.
	 *
	 * @param string $extra Space separated classes.
	 * @param string $align Alignment: wide or full.
	 */
	private function classes( string $extra, string $align ): string {
		$classes = array();
		$names   = preg_split( '/\s+/', trim( $extra ) );
		foreach ( false === $names ? array() : $names as $name ) {
			$name = sanitize_html_class( $name );
			if ( '' !== $name ) {
				$classes[] = $name;
			}
		}
		$align = strtolower( trim( $align ) );
		if ( in_array( $align, array( 'wide', 'full' ), true ) ) {
			$classes[] = 'align' . $align;
		}
		return implode( ' ', array_unique( $classes ) );
	}
}
