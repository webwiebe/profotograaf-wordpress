<?php
/**
 * The per-image captions and alt texts of a gallery, as embed.js reads them.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans the per-image text a block or shortcode sets into the JSON of the
 * `data-image-text` attribute: a list of objects with `id` and a `caption`
 * and/or `alt`.
 */
final class Image_Text {

	/** Most per-image entries kept. */
	public const LIMIT = 200;

	/** Longest caption or alt text kept, in characters. */
	public const LENGTH = 500;

	/**
	 * Cleans the per-image captions and alt texts.
	 *
	 * @param mixed $raw A list of arrays, or the same as a JSON string (from the shortcode).
	 * @return string JSON, or an empty string when nothing usable is left.
	 */
	public static function clean( $raw ): string {
		if ( is_string( $raw ) ) {
			$raw = '' === trim( $raw ) ? array() : json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return '';
		}
		$clean = array();
		foreach ( $raw as $item ) {
			$entry = self::entry( $item );
			if ( null !== $entry ) {
				$clean[] = $entry;
			}
			if ( count( $clean ) >= self::LIMIT ) {
				break;
			}
		}
		return array() === $clean ? '' : (string) wp_json_encode( $clean );
	}

	/**
	 * Cleans one per-image entry.
	 *
	 * @param mixed $item Raw entry.
	 * @return array<string,string>|null The entry, or null without a valid id or any text.
	 */
	private static function entry( $item ): ?array {
		if ( ! is_array( $item ) || ! isset( $item['id'] ) || ! is_scalar( $item['id'] ) ) {
			return null;
		}
		$id = trim( (string) $item['id'] );
		if ( ! Gallery_Renderer::valid_id( $id ) ) {
			return null;
		}
		$entry = array( 'id' => $id );
		foreach ( array( 'caption', 'alt' ) as $field ) {
			$text = isset( $item[ $field ] ) && is_scalar( $item[ $field ] ) ? trim( sanitize_text_field( (string) $item[ $field ] ) ) : '';
			if ( '' !== $text ) {
				$entry[ $field ] = mb_substr( $text, 0, self::LENGTH );
			}
		}
		return count( $entry ) > 1 ? $entry : null;
	}
}
