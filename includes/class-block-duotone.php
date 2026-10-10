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
	 * @param mixed $duotone A list of colours, a preset reference such as
	 *                       `var:preset|duotone|midnight` or
	 *                       `var(--wp--preset--duotone--midnight)`, or `unset`.
	 * @return string Two hex colours, `none` when the block turns duotone off, or
	 *                an empty string when the block has no usable duotone.
	 */
	public static function resolve( $duotone ): string {
		if ( is_array( $duotone ) ) {
			return self::clean( implode( ',', array_filter( $duotone, 'is_string' ) ) ) ?? '';
		}
		if ( ! is_string( $duotone ) || '' === $duotone ) {
			return '';
		}
		if ( 'unset' === $duotone ) {
			return 'none';
		}
		if ( 1 !== preg_match( '/^(?:var:preset\|duotone\||var\(--wp--preset--duotone--)([a-z0-9-]+)\)?$/', $duotone, $match ) ) {
			return '';
		}
		$colors = self::preset_duotone( $match[1] );
		return null === $colors ? '' : ( self::clean( implode( ',', $colors ) ) ?? '' );
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
