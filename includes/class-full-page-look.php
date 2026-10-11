<?php
/**
 * Spacing and shape of the sections of the full-page gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the spacing and shape fields of one section (#194), the options #198
 * gave the gallery block, as custom properties and data attributes on the
 * section element. The values are resolved already (Sections_Schema), and a
 * type writes only the fields it has in includes/sections.json.
 *
 * - `--pf-gap-row` and `--pf-gap-col` only when the section sets a gap per
 *   axis; style.css falls back to `--pf-gap`.
 * - `--pf-radius` in pixels, or 50% for a circle. A circle needs square
 *   photos, so a section that is not a square grid uses the default corners
 *   of its type instead.
 * - `--pf-frame` and `--pf-frame-color` when the frame is wider than 0.
 * - `data-hover` and `data-hover-strength` unless the effect is `none`.
 * - `data-shadow` unless the shadow is `none`.
 */
final class Full_Page_Look {

	/** Corner radius names and the CSS value of each, as Gallery_Look names them. */
	private const RADIUS = array(
		'none'    => '',
		'subtle'  => '4px',
		'rounded' => '12px',
		'full'    => '50%',
	);

	/**
	 * The custom properties of a section, each ending in a semicolon.
	 *
	 * @param array<string,mixed> $section Sequenced section.
	 */
	public static function style( array $section ): string {
		$style = '';
		foreach ( array(
			'gapRow'    => '--pf-gap-row',
			'gapColumn' => '--pf-gap-col',
		) as $field => $property ) {
			if ( is_int( $section[ $field ] ?? null ) ) {
				$style .= $property . ':' . $section[ $field ] . 'px;';
			}
		}
		$radius = self::RADIUS[ self::radius( $section ) ] ?? '';
		if ( '' !== $radius ) {
			$style .= '--pf-radius:' . $radius . ';';
		}
		$frame = (int) ( $section['frameWidth'] ?? 0 );
		if ( $frame > 0 ) {
			$color  = Gallery_Look::clean_color( $section['frameColor'] ?? '' );
			$style .= '--pf-frame:' . $frame . 'px;--pf-frame-color:' . ( null === $color || '' === $color ? Gallery_Look::FRAME_COLOR : $color ) . ';';
		}
		return $style;
	}

	/**
	 * The data attributes of a section.
	 *
	 * @param array<string,mixed> $section Sequenced section.
	 * @return array<string,string>
	 */
	public static function data( array $section ): array {
		$data  = array();
		$hover = (string) ( $section['hover'] ?? 'none' );
		if ( 'none' !== $hover ) {
			$data['data-hover']          = $hover;
			$data['data-hover-strength'] = (string) ( $section['hoverStrength'] ?? 'subtle' );
		}
		$shadow = (string) ( $section['shadow'] ?? 'none' );
		if ( 'none' !== $shadow ) {
			$data['data-shadow'] = $shadow;
		}
		return $data;
	}

	/**
	 * The corner radius name a section draws: a circle only for a square grid.
	 *
	 * @param array<string,mixed> $section Sequenced section.
	 */
	private static function radius( array $section ): string {
		$radius = (string) ( $section['radius'] ?? 'none' );
		if ( 'full' !== $radius || ( 'grid' === ( $section['type'] ?? '' ) && '1-1' === ( $section['ratio'] ?? '' ) ) ) {
			return $radius;
		}
		return (string) ( Sections_Schema::defaults( (string) ( $section['type'] ?? '' ) )['radius'] ?? 'none' );
	}
}
