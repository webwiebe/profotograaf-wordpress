<?php
/**
 * Content-Security-Policy frame-ancestors matching.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the frame-ancestors directive of a Content-Security-Policy header.
 */
final class Frame_Ancestors {

	/**
	 * Whether every policy in the header that has a frame-ancestors directive
	 * lists `$origin` (or `*`, or its scheme), and at least one has the directive.
	 *
	 * @param string $header Header value, several policies separated by commas.
	 * @param string $origin Origin to look for.
	 */
	public static function allow( string $header, string $origin ): bool {
		$origin = strtolower( $origin );
		$scheme = (string) strstr( $origin, '://', true ) . ':';
		$found  = false;
		foreach ( explode( ',', $header ) as $policy ) {
			foreach ( explode( ';', $policy ) as $directive ) {
				$tokens = preg_split( '/\\s+/', trim( strtolower( $directive ) ) );
				if ( false === $tokens || 'frame-ancestors' !== $tokens[0] ) {
					continue;
				}
				$found = true;
				if ( array() === array_intersect( array_slice( $tokens, 1 ), array( $origin, '*', $scheme ) ) ) {
					return false;
				}
			}
		}
		return $found;
	}
}
