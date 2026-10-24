<?php
/**
 * The look options of the server-drawn gallery: columns, gap, radius, shadow and hover.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the host div's look attributes into the classes and custom properties
 * of the gallery box. A port of professionals web/src/public/embed/_lib/look.ts
 * and the cssVars function of options.ts, held to the same golden files
 * (tests/fixtures/embed/look-*). A value the script would not accept is left
 * out, exactly as the script leaves it out.
 */
final class Declarative_Look {

	/**
	 * Largest gap in px.
	 */
	private const MAX_GAP = 96;

	/**
	 * The gap in px when the host sets none.
	 */
	private const DEFAULT_GAP = 8;

	/**
	 * Host attributes this class reads.
	 *
	 * @var string[]
	 */
	public const ATTRIBUTES = array(
		'data-columns',
		'data-columns-tablet',
		'data-columns-mobile',
		'data-gap',
		'data-gap-row',
		'data-gap-column',
		'data-radius',
		'data-shadow',
		'data-hover',
		'data-hover-strength',
	);

	/**
	 * Class names for the gallery box, after the layout class.
	 *
	 * @param array<string,string> $attrs  Host attributes.
	 * @param string               $layout grid or masonry.
	 * @return string[]
	 */
	public static function classes( array $attrs, string $layout ): array {
		$hover    = self::hover( $attrs );
		$classes  = array();
		$strength = self::one_of( array( 'subtle', 'strong' ), $attrs['data-hover-strength'] ?? '', 'subtle' );
		$shadow   = self::one_of( array( 'none', 'soft', 'strong' ), $attrs['data-shadow'] ?? '', 'none' );
		if ( 'zoom' !== $hover ) {
			$classes[] = 'hv-' . $hover;
		}
		if ( 'strong' === $strength && 'none' !== $hover ) {
			$classes[] = 'hs-strong';
		}
		if ( 'none' !== $shadow ) {
			$classes[] = 'sh-' . $shadow;
		}
		if ( self::full_radius( $attrs, $layout ) ) {
			$classes[] = 'rfull';
		}
		return $classes;
	}

	/**
	 * The inline style of the gallery box, or an empty string when every option is at its default.
	 *
	 * @param array<string,string> $attrs  Host attributes.
	 * @param string               $layout grid or masonry.
	 * @param int                  $count  Photos drawn: a column count above it would leave empty tracks.
	 */
	public static function style( array $attrs, string $layout, int $count ): string {
		$vars = array();
		foreach ( array(
			'--cols'        => 'data-columns',
			'--cols-tablet' => 'data-columns-tablet',
			'--cols-mobile' => 'data-columns-mobile',
		) as $property => $name ) {
			$columns = self::bounded_int( $attrs[ $name ] ?? '', 1, 12 );
			if ( $columns ) {
				$vars[] = $property . ':' . ( $count > 0 ? min( $columns, $count ) : $columns );
			}
		}
		foreach ( array(
			'--gap'     => 'data-gap',
			'--gap-row' => 'data-gap-row',
			'--gap-col' => 'data-gap-column',
		) as $property => $name ) {
			$gap = self::bounded_int( $attrs[ $name ] ?? '', 0, self::MAX_GAP );
			if ( null !== $gap ) {
				$vars[] = $property . ':' . $gap . 'px';
			}
		}
		$radius = self::radius( $attrs, $layout );
		if ( '' !== $radius ) {
			$vars[] = $radius;
		}
		return implode( ';', $vars );
	}

	/**
	 * Whether the grow hover is on, which marks each tile for the script's stylesheet.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	public static function grow( array $attrs ): bool {
		return 'grow' === self::hover( $attrs );
	}

	/**
	 * The hover effect.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	private static function hover( array $attrs ): string {
		return self::one_of( array( 'none', 'zoom', 'grow' ), $attrs['data-hover'] ?? '', 'zoom' );
	}

	/**
	 * Whether `data-radius="full"` applies: only to square tiles, which are the grid's default crop.
	 *
	 * @param array<string,string> $attrs  Host attributes.
	 * @param string               $layout grid or masonry.
	 */
	private static function full_radius( array $attrs, string $layout ): bool {
		return 'full' === strtolower( trim( $attrs['data-radius'] ?? '' ) ) && 'grid' === $layout;
	}

	/**
	 * The `--radius` declaration, or an empty string when the default applies.
	 * Touching tiles (no gap on either axis) get square corners unless a radius was asked for.
	 *
	 * @param array<string,string> $attrs  Host attributes.
	 * @param string               $layout grid or masonry.
	 */
	private static function radius( array $attrs, string $layout ): string {
		if ( self::full_radius( $attrs, $layout ) ) {
			return '--radius:50%';
		}
		$gap     = self::bounded_int( $attrs['data-gap'] ?? '', 0, self::MAX_GAP );
		$row     = self::bounded_int( $attrs['data-gap-row'] ?? '', 0, self::MAX_GAP );
		$column  = self::bounded_int( $attrs['data-gap-column'] ?? '', 0, self::MAX_GAP );
		$any_gap = null !== $gap || null !== $row || null !== $column;
		$flush   = $any_gap && 0 === ( $row ?? $gap ?? self::DEFAULT_GAP ) && 0 === ( $column ?? $gap ?? self::DEFAULT_GAP );
		$radius  = self::bounded_int( $attrs['data-radius'] ?? '', 0, 64 ) ?? ( $flush ? 0 : null );
		return null === $radius ? '' : '--radius:' . $radius . 'px';
	}

	/**
	 * A whole number within [min, max], else null.
	 *
	 * @param string $value Attribute value.
	 * @param int    $min   Smallest accepted value.
	 * @param int    $max   Largest accepted value.
	 */
	private static function bounded_int( string $value, int $min, int $max ): ?int {
		$value = trim( $value );
		if ( 1 !== preg_match( '/^\d{1,4}$/', $value ) ) {
			return null;
		}
		$number = (int) $value;
		return $number >= $min && $number <= $max ? $number : null;
	}

	/**
	 * The listed value that matches, else the fallback.
	 *
	 * @param string[] $list     Accepted values.
	 * @param string   $value    Attribute value.
	 * @param string   $fallback Value when none matches.
	 */
	private static function one_of( array $list, string $value, string $fallback ): string {
		$value = strtolower( trim( $value ) );
		return in_array( $value, $list, true ) ? $value : $fallback;
	}
}
