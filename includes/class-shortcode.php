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
 * adds the matching alignment class.
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
		$atts  = shortcode_atts(
			array(
				'id'     => '',
				'layout' => '',
				'title'  => '',
				'url'    => '',
				'class'  => '',
				'align'  => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		$extra = $this->classes( (string) $atts['class'], (string) $atts['align'] );
		unset( $atts['align'] );
		return $this->renderer->render( array_merge( $atts, array( 'class' => $extra ) ) );
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
