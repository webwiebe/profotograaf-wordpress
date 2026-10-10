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
			return Gallery_Renderer::clean_duotone( implode( ',', array_filter( $duotone, 'is_string' ) ) ) ?? '';
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
		return null === $colors ? '' : ( Gallery_Renderer::clean_duotone( implode( ',', $colors ) ) ?? '' );
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
		$lists = isset( $presets['slug'] ) ? array( $presets ) : $presets;
		$found = null;
		foreach ( $lists as $list ) {
			$items = is_array( $list ) && isset( $list['slug'] ) ? array( $list ) : (array) $list;
			foreach ( $items as $item ) {
				if ( is_array( $item ) && ( $item['slug'] ?? null ) === $slug && is_array( $item['colors'] ?? null ) ) {
					$found = array_values( array_filter( $item['colors'], 'is_string' ) );
				}
			}
		}
		return $found;
	}
}
