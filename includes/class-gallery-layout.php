<?php
/**
 * The layout the photographer set for a gallery on Profotograaf.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * The `sections` object of the embed payload (professionals#2454): the preset
 * of the gallery, its picks (`slot`, `photo_id`) and, for `custom`, its own
 * sequence. The full-page gallery block can use it with the preset value
 * `gallery` (#216). Without the object the value falls back to the default
 * preset, so a block saved before the platform serves the layout renders as it
 * does today.
 */
final class Gallery_Layout {

	/** The block's preset value for "use the gallery's own layout". */
	public const PRESET = 'gallery';

	/**
	 * The preset the payload carries, or an empty string when it carries none
	 * or one this plugin does not know.
	 *
	 * @param array<string,mixed> $gallery Embed payload.
	 */
	public static function preset_of( array $gallery ): string {
		$preset = is_array( $gallery['sections'] ?? null ) && is_string( $gallery['sections']['preset'] ?? null ) ? $gallery['sections']['preset'] : '';
		if ( '' === $preset || ( Sections_Schema::CUSTOM !== $preset && ! in_array( $preset, Sections_Schema::presets(), true ) ) ) {
			return '';
		}
		return $preset;
	}

	/**
	 * The preset, custom sections and picks to sequence a block with.
	 *
	 * With the preset `gallery` and a layout in the payload, the layout of the
	 * payload is used and the block's own picks win over its picks. Any other
	 * value is read like before.
	 *
	 * @param array<string,mixed> $gallery   Embed payload.
	 * @param mixed               $requested The block's preset attribute.
	 * @param array<int,mixed>    $custom    The block's custom sections.
	 * @param array<int,mixed>    $picks     The block's picks, `{ slot, photoId }`.
	 * @return array{preset:string,custom:array<int,mixed>,picks:array<int,mixed>}
	 */
	public static function resolve( array $gallery, $requested, array $custom, array $picks ): array {
		$own = is_string( $requested ) && self::PRESET === trim( $requested ) ? self::preset_of( $gallery ) : '';
		if ( '' === $own ) {
			return array(
				'preset' => Sections_Schema::preset_id( self::PRESET === $requested ? '' : $requested ),
				'custom' => $custom,
				'picks'  => $picks,
			);
		}
		$theirs = self::picks( $gallery );
		if ( Sections_Schema::CUSTOM === $own ) {
			return array(
				'preset' => $own,
				'custom' => self::seat( (array) ( $gallery['sections']['custom'] ?? array() ), array_merge( $picks, $theirs ) ),
				'picks'  => array(),
			);
		}
		return array(
			'preset' => $own,
			'custom' => array(),
			'picks'  => array_merge( $picks, $theirs ),
		);
	}

	/**
	 * The payload's picks in the block's shape.
	 *
	 * @param array<string,mixed> $gallery Embed payload.
	 * @return array<int,array{slot:string,photoId:string}>
	 */
	private static function picks( array $gallery ): array {
		$out = array();
		foreach ( (array) ( $gallery['sections']['picks'] ?? array() ) as $pick ) {
			if ( is_array( $pick ) && is_string( $pick['slot'] ?? null ) && is_scalar( $pick['photo_id'] ?? null ) ) {
				$out[] = array(
					'slot'    => $pick['slot'],
					'photoId' => (string) $pick['photo_id'],
				);
			}
		}
		return $out;
	}

	/**
	 * Puts picks of the first cycle into the `photoIds` of a custom sequence,
	 * the first pick of a slot winning.
	 *
	 * @param array<int,mixed> $custom Custom sections.
	 * @param array<int,mixed> $picks  Picks, `{ slot, photoId }`.
	 * @return array<int,mixed>
	 */
	private static function seat( array $custom, array $picks ): array {
		$custom = array_values( $custom );
		$taken  = array();
		foreach ( $picks as $pick ) {
			if ( ! is_array( $pick ) || 1 !== preg_match( '/^0\.(\d{1,3})\.(\d{1,3})$/', (string) ( $pick['slot'] ?? '' ), $slot ) ) {
				continue;
			}
			$key = $slot[1] . '.' . $slot[2];
			if ( isset( $taken[ $key ] ) || ! is_array( $custom[ (int) $slot[1] ] ?? null ) ) {
				continue;
			}
			$index = (int) $slot[1];
			$ids   = (array) ( $custom[ $index ]['photoIds'] ?? array() );

			$ids[ (int) $slot[2] ]        = (string) ( $pick['photoId'] ?? '' );
			$custom[ $index ]['photoIds'] = $ids;
			$taken[ $key ]                = true;
		}
		return $custom;
	}
}
