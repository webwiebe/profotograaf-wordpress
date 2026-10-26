<?php
/**
 * The duotone filter of the server-drawn gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * A port of parseDuotone, duotoneValues and the server renderer's duotone() in
 * professionals web/src/public/embed/_lib (options.ts, server.ts), held to the
 * same golden files (tests/fixtures/embed/duotone-*). The filter is a function
 * of the two colours, so it needs nothing from the browser.
 */
final class Declarative_Duotone {

	/**
	 * The dark and light colour from `data-duotone` as #rrggbb, or null when the script would draw no duotone.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 * @return array{0:string,1:string}|null
	 */
	public static function colours( array $attrs ): ?array {
		$parts = preg_split( '/[\s,]+/', trim( $attrs['data-duotone'] ?? '' ) );
		if ( false === $parts || 2 !== count( $parts ) ) {
			return null;
		}
		$dark  = self::hex( $parts[0] );
		$light = self::hex( $parts[1] );
		return null !== $dark && null !== $light ? array( $dark, $light ) : null;
	}

	/**
	 * The inline SVG filter the `duo` class points at.
	 *
	 * @param array{0:string,1:string} $colours Value of colours().
	 */
	public static function svg( array $colours ): string {
		$luma  = '0.2126 0.7152 0.0722 0 0';
		$funcs = '';
		foreach ( array( 'R', 'G', 'B' ) as $i => $channel ) {
			$values = self::channel( $colours[0], $i ) . ' ' . self::channel( $colours[1], $i );
			$funcs .= '<feFunc' . $channel . ' type="table" tableValues="' . $values . '"></feFunc' . $channel . '>';
		}
		return '<svg width="0" height="0" aria-hidden="true" style="position:absolute">'
			. '<filter id="pf-duo" color-interpolation-filters="sRGB"><feColorMatrix type="matrix" values="' . "$luma $luma $luma 0 0 0 1 0" . '"></feColorMatrix>'
			. '<feComponentTransfer>' . $funcs . '</feComponentTransfer></filter></svg>';
	}

	/**
	 * One channel of a #rrggbb colour as a fraction with 4 digits, as the script's toFixed(4) writes it.
	 *
	 * @param string $colour #rrggbb.
	 * @param int    $index  0 red, 1 green, 2 blue.
	 */
	private static function channel( string $colour, int $index ): string {
		return number_format( hexdec( substr( $colour, 1 + $index * 2, 2 ) ) / 255, 4, '.', '' );
	}

	/**
	 * A #rgb or #rrggbb colour (the # is optional) as lower-case #rrggbb.
	 *
	 * @param string $value Colour.
	 */
	private static function hex( string $value ): ?string {
		if ( 1 !== preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6})$/iD', $value, $m ) ) {
			return null;
		}
		$hex = 3 === strlen( $m[1] ) ? (string) preg_replace( '/(.)/', '$1$1', $m[1] ) : $m[1];
		return '#' . strtolower( $hex );
	}
}
