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
		$vars   = array_merge( self::column_vars( $attrs, $count ), self::gap_vars( $attrs ) );
		$radius = self::radius( $attrs, $layout );
		if ( '' !== $radius ) {
			$vars[] = $radius;
		}
		$frame = self::frame( $attrs['data-frame'] ?? '' );
		if ( null !== $frame ) {
			$vars[] = '--frame-w:' . $frame[1] . 'px';
			$vars[] = '--frame-c:' . $frame[0];
		}
		$ratio = self::ratio( $attrs['data-ratio'] ?? '' );
		if ( null !== $ratio ) {
			$vars[] = '--ratio:' . self::number( $ratio[0] ) . '/' . self::number( $ratio[1] );
		}
		return implode( ';', $vars );
	}

	/**
	 * The column custom properties, each capped at the number of photos.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 * @param int                  $count Photos in the gallery, 0 when unknown.
	 * @return string[]
	 */
	private static function column_vars( array $attrs, int $count ): array {
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
		return $vars;
	}

	/**
	 * The gap custom properties.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 * @return string[]
	 */
	private static function gap_vars( array $attrs ): array {
		$vars = array();
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
		return $vars;
	}

	/**
	 * Whether the host div asks for something the server cannot draw: `data-ratio="original"`
	 * keeps every photo's own proportions in justified rows, which needs each photo measured.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	public static function needs_client( array $attrs ): bool {
		return 'original' === strtolower( trim( $attrs['data-ratio'] ?? '' ) );
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
		return 'full' === strtolower( trim( $attrs['data-radius'] ?? '' ) ) && self::square_crop( $attrs, $layout );
	}

	/**
	 * Whether every tile is a square: the grid's default crop, or any layout with a 1:1 ratio.
	 *
	 * @param array<string,string> $attrs  Host attributes.
	 * @param string               $layout grid or masonry.
	 */
	private static function square_crop( array $attrs, string $layout ): bool {
		$ratio = self::ratio( $attrs['data-ratio'] ?? '' );
		if ( null === $ratio ) {
			return 'grid' === $layout;
		}
		return $ratio[0] === $ratio[1];
	}

	/**
	 * The frame colour (#rrggbb) and width in px, or null: width 0 or any bad part means no frame.
	 *
	 * @param string $value Attribute value, such as `#ffffff,8`.
	 * @return array{0:string,1:int}|null
	 */
	private static function frame( string $value ): ?array {
		$parts = preg_split( '/[\s,]+/', trim( $value ) );
		if ( false === $parts || 2 !== count( $parts ) || 1 !== preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6})$/iD', $parts[0], $m ) ) {
			return null;
		}
		$hex   = 3 === strlen( $m[1] ) ? preg_replace( '/(.)/', '$1$1', $m[1] ) : $m[1];
		$width = self::bounded_int( (string) preg_replace( '/px$/i', '', $parts[1] ), 0, 24 );
		return $width ? array( '#' . strtolower( (string) $hex ), $width ) : null;
	}

	/**
	 * A fixed crop ratio as width and height: `4:3`, `4/3`, `4x3` or one number such as `1.5`.
	 * Null for anything the script rejects, and for `original`.
	 *
	 * @param string $value Attribute value.
	 * @return array{0:float,1:float}|null
	 */
	private static function ratio( string $value ): ?array {
		if ( 1 !== preg_match( '/^\s*(\d+(?:\.\d+)?)\s*(?:[:\/x]\s*(\d+(?:\.\d+)?))?\s*$/D', $value, $m ) ) {
			return null;
		}
		$w = (float) $m[1];
		$h = isset( $m[2] ) ? (float) $m[2] : 1.0;
		if ( ! ( $w > 0 && $h > 0 ) || $w > 100 || $h > 100 || $w / $h > 10 || $h / $w > 10 ) {
			return null;
		}
		return array( $w, $h );
	}

	/**
	 * A number the way the script writes it: 4, not 4.0.
	 *
	 * @param float $value Number.
	 */
	private static function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' );
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
		$radius = self::bounded_int( $attrs['data-radius'] ?? '', 0, 64 ) ?? ( self::flush( $attrs ) ? 0 : null );
		return null === $radius ? '' : '--radius:' . $radius . 'px';
	}

	/**
	 * Whether a gap is set and both gaps are zero, so tiles touch.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	private static function flush( array $attrs ): bool {
		$gap    = self::bounded_int( $attrs['data-gap'] ?? '', 0, self::MAX_GAP );
		$row    = self::bounded_int( $attrs['data-gap-row'] ?? '', 0, self::MAX_GAP );
		$column = self::bounded_int( $attrs['data-gap-column'] ?? '', 0, self::MAX_GAP );
		return ( null !== $gap || null !== $row || null !== $column )
			&& 0 === ( $row ?? $gap ?? self::DEFAULT_GAP )
			&& 0 === ( $column ?? $gap ?? self::DEFAULT_GAP );
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
	 * @param string[] $accepted Accepted values.
	 * @param string   $value    Attribute value.
	 * @param string   $fallback Value when none matches.
	 */
	private static function one_of( array $accepted, string $value, string $fallback ): string {
		$value = strtolower( trim( $value ) );
		return in_array( $value, $accepted, true ) ? $value : $fallback;
	}
}
