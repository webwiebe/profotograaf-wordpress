<?php
/**
 * The duotone of a gallery block, as embed.js reads it.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Translates the duotone of the block's color support (`style.color.duotone`)
 * into the two hex colours of the `data-duotone` attribute.
 */
final class Block_Duotone {

	/**
	 * Translates the duotone of a block's color support into two hex colours.
	 *
	 * A block that sets a duotone always gets its own: when the value cannot be
	 * turned into two colours (an unknown preset, a colour format this class
	 * does not read), the result is `none`, so the site-wide duotone does not
	 * show instead.
	 *
	 * @param mixed $duotone A list of colours, a preset reference such as
	 *                       `var:preset|duotone|midnight` or
	 *                       `var(--wp--preset--duotone--midnight)`, or `unset`.
	 * @return string Two hex colours, `none` when the block turns duotone off or
	 *                sets one that cannot be read, or an empty string when the
	 *                block sets no duotone.
	 */
	public static function resolve( $duotone ): string {
		if ( is_array( $duotone ) ) {
			return array() === $duotone ? '' : ( self::pair( $duotone ) ?? 'none' );
		}
		if ( ! is_string( $duotone ) || '' === trim( $duotone ) ) {
			return '';
		}
		if ( 1 !== preg_match( '/^(?:var:preset\|duotone\||var\(--wp--preset--duotone--)([a-z0-9-]+)\)?$/', $duotone, $match ) ) {
			return 'none';
		}
		$colors = self::preset_duotone( $match[1] );
		return ( null === $colors ? null : self::pair( $colors ) ) ?? 'none';
	}

	/**
	 * The shadow and highlight of a list of duotone colours: the first and the
	 * last, as hex.
	 *
	 * @param array<mixed> $colors Colours in any format hex() reads.
	 * @return string|null Two hex colours, or null when the list has fewer than two
	 *                     readable colours.
	 */
	private static function pair( array $colors ): ?string {
		$hex = array();
		foreach ( $colors as $color ) {
			$value = is_string( $color ) ? self::hex( $color ) : null;
			if ( null === $value ) {
				return null;
			}
			$hex[] = $value;
		}
		if ( count( $hex ) < 2 ) {
			return null;
		}
		return $hex[0] . ',' . $hex[ count( $hex ) - 1 ];
	}

	/**
	 * One colour as lower-case hex. Reads 3, 4, 6 and 8 digit hex and `rgb()` or
	 * `rgba()` in the comma and the space syntax; the alpha is dropped, because
	 * a duotone filter maps to opaque colours.
	 *
	 * @param string $color Colour.
	 * @return string|null Hex colour, or null when the format is not read.
	 */
	public static function hex( string $color ): ?string {
		$color = strtolower( trim( $color ) );
		if ( 1 === preg_match( '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $color, $match ) ) {
			$digits = strlen( $match[1] );
			return '#' . ( 4 === $digits || 8 === $digits ? substr( $match[1], 0, intdiv( $digits * 3, 4 ) ) : $match[1] );
		}
		$channel = '(\d+(?:\.\d+)?%?)';
		if ( 1 !== preg_match( '/^rgba?\(\s*' . $channel . '\s*[,\s]\s*' . $channel . '\s*[,\s]\s*' . $channel . '\s*(?:[,\/]\s*\d*(?:\.\d+)?%?\s*)?\)$/', $color, $match ) ) {
			return null;
		}
		$hex = '#';
		foreach ( array( $match[1], $match[2], $match[3] ) as $value ) {
			$number = '%' === substr( $value, -1 ) ? (float) $value * 255 / 100 : (float) $value;
			$hex   .= sprintf( '%02x', (int) round( max( 0, min( 255, $number ) ) ) );
		}
		return $hex;
	}

	/**
	 * The colours of a duotone preset from the global settings, which hold the
	 * presets of core, the theme and the user, in a flat list or grouped by origin.
	 * The last origin that defines the slug wins.
	 *
	 * @param string $slug Preset slug.
	 * @return array<int,string>|null
	 */
	private static function preset_duotone( string $slug ): ?array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return null;
		}
		$presets = wp_get_global_settings( array( 'color', 'duotone' ) );
		if ( ! is_array( $presets ) ) {
			return null;
		}
		$found = null;
		foreach ( $presets as $entry ) {
			foreach ( self::presets_in( $entry ) as $item ) {
				if ( ( $item['slug'] ?? null ) === $slug && is_array( $item['colors'] ?? null ) ) {
					$found = array_values( array_filter( $item['colors'], 'is_string' ) );
				}
			}
		}
		return $found;
	}

	/**
	 * The presets in one entry of the settings: the entry itself when it is a
	 * preset (a flat list), or its members when it is the list of one origin.
	 *
	 * @param mixed $entry An entry of the duotone settings.
	 * @return array<int,array<mixed>>
	 */
	private static function presets_in( $entry ): array {
		if ( ! is_array( $entry ) ) {
			return array();
		}
		if ( isset( $entry['slug'] ) ) {
			return array( $entry );
		}
		return array_values( array_filter( $entry, 'is_array' ) );
	}

	/**
	 * Cleans two hex colours such as `#1a1a2e,#f5c542`, the duotone shadow and
	 * highlight.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null The colours in lower case, an empty string for an empty
	 *                     value, or null when the value is not two hex colours.
	 */
	public static function clean( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		$hex = '#[0-9a-f]{6}|#[0-9a-f]{3}';
		return 1 === preg_match( '/^(?:' . $hex . '),(?:' . $hex . ')$/', $value ) ? $value : null;
	}
}
