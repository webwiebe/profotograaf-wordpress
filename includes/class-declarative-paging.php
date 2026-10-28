<?php
/**
 * The page size of the server-drawn gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * A port of the page size handling in professionals
 * web/src/public/embed/_lib (options.ts, server.ts), held to the same golden
 * files (tests/fixtures/embed/per-page-*). With `data-per-page` the server draws
 * the first page of tiles and, while `data-load-more` is on, the Show more button
 * after the list. embed.js adopts the markup and adds the later pages itself.
 */
final class Declarative_Paging {

	/**
	 * The page size from `data-per-page`, or null when the gallery is not paged.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	public static function size( array $attrs ): ?int {
		$value = trim( $attrs['data-per-page'] ?? '' );
		if ( 1 !== preg_match( '/^\d{1,4}$/', $value ) ) {
			return null;
		}
		$size = (int) $value;
		return $size >= 1 && $size <= 500 ? $size : null;
	}

	/**
	 * Whether the Show more button is on: anything but an explicit off.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	public static function button( array $attrs ): bool {
		return ! in_array( strtolower( trim( $attrs['data-load-more'] ?? '' ) ), array( 'off', 'false', '0', 'no' ), true );
	}

	/**
	 * The photos drawn on the server: the first page, or every photo when the gallery is not paged.
	 *
	 * @param array<int,array<string,mixed>> $photos Selected photos.
	 * @param array<string,string>           $attrs  Host attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public static function first_page( array $photos, array $attrs ): array {
		$size = self::size( $attrs );
		return null === $size ? $photos : array_slice( $photos, 0, $size );
	}

	/**
	 * How many photos count for the columns: the whole list while the button is on, the first page without it.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 * @param int                  $total Photos after selection.
	 */
	public static function column_count( array $attrs, int $total ): int {
		$size = self::size( $attrs );
		return null === $size || self::button( $attrs ) ? $total : min( $size, $total );
	}

	/**
	 * The Show more button, hidden when the first page holds every photo; empty when there is none.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 * @param int                  $total Photos after selection.
	 * @param string               $label Button text, in the page language.
	 */
	public static function more( array $attrs, int $total, string $label ): string {
		$size = self::size( $attrs );
		if ( null === $size || ! self::button( $attrs ) ) {
			return '';
		}
		$hidden = $total <= $size ? ' hidden' : '';
		return '<button class="more" type="button"' . $hidden . '>' . htmlspecialchars( $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</button>';
	}
}
