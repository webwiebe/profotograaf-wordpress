<?php
/**
 * Which photos the server-drawn gallery lists, and with what text.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * A port of selectPhotos and the sort, exclude and image-text parsers in
 * professionals web/src/public/embed/_lib/options.ts, held to the same golden
 * files (tests/fixtures/embed/sort-*, exclude-* and image-text-*).
 *
 * The title sort compares with the ICU collation `localeCompare` uses, through
 * PHP's intl Collator (the server's English order, as the golden files pin it).
 * A host without intl goes to embed.js: needs_client()
 * sends that gallery there.
 *
 * The random sort is a shuffle from a seed (Declarative_Shuffle). A page the
 * server draws holds one seeded order, and a cached page keeps it; embed.js
 * draws a fresh shuffle once it adopts the page.
 */
final class Declarative_Selection {

	/**
	 * Most entries read from `data-image-text`.
	 */
	private const IMAGE_TEXT_ENTRIES = 200;

	/**
	 * Most UTF-16 units kept of one override text, as the script counts them.
	 */
	private const IMAGE_TEXT_UNITS = 500;

	/**
	 * What the WordPress plugin sends, mapped to the sorts the script knows.
	 *
	 * @var array<string,string>
	 */
	private const SORT_ALIASES = array(
		'newest' => 'reverse',
		'oldest' => 'default',
		'name'   => 'title',
	);

	/**
	 * Whether the sort needs the browser: a title order on a host without intl.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	public static function needs_client( array $attrs ): bool {
		return 'title' === self::sort( $attrs ) && ! class_exists( '\Collator' );
	}

	/**
	 * The photos to draw: excluded ids dropped, the text overrides applied, then the order.
	 *
	 * @param array<int,mixed>     $photos Payload photos.
	 * @param array<string,string> $attrs  Host attributes.
	 * @param int|null             $seed   Seed of the random sort; a fresh one when null.
	 * @return array<int,array<string,mixed>>
	 */
	public static function select( array $photos, array $attrs, ?int $seed = null ): array {
		$exclude = self::exclude( $attrs );
		$text    = self::image_text( $attrs );
		$out     = array();
		foreach ( $photos as $photo ) {
			$photo = (array) $photo;
			if ( isset( $exclude[ (string) ( $photo['id'] ?? '' ) ] ) ) {
				continue;
			}
			$out[] = self::with_text( $photo, $text );
		}
		$sort = self::sort( $attrs );
		if ( 'reverse' === $sort ) {
			return array_reverse( $out );
		}
		if ( 'random' === $sort ) {
			return Declarative_Shuffle::order( $out, $seed ?? random_int( 1, 0x7FFFFFFF ) );
		}
		return 'title' === $sort ? self::by_title( $out ) : $out;
	}

	/**
	 * The photos ordered by title as `localeCompare` does: letters without regard to case,
	 * an empty title first, equal titles in payload order. Only called with intl present.
	 *
	 * @param array<int,array<string,mixed>> $photos Photos.
	 * @return array<int,array<string,mixed>>
	 */
	private static function by_title( array $photos ): array {
		$collator = new \Collator( 'en' );
		usort(
			$photos,
			static fn( array $a, array $b ): int => (int) $collator->compare( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) )
		);
		return $photos;
	}

	/**
	 * The sort: default, reverse, title or random; the plugin's aliases are mapped, anything else is the payload order.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	private static function sort( array $attrs ): string {
		$value = strtolower( trim( $attrs['data-sort'] ?? '' ) );
		$value = self::SORT_ALIASES[ $value ] ?? $value;
		return in_array( $value, array( 'reverse', 'title', 'random' ), true ) ? $value : 'default';
	}

	/**
	 * The excluded photo ids, as array keys.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 * @return array<string,true>
	 */
	private static function exclude( array $attrs ): array {
		$ids = preg_split( '/[\s,]+/u', (string) ( $attrs['data-exclude'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
		return array_fill_keys( false === $ids ? array() : $ids, true );
	}

	/**
	 * The overrides in `data-image-text`: a JSON list of {id, caption, alt}. Invalid
	 * JSON, a value that is not a list, and entries without an id or any text are ignored;
	 * the later entry for an id wins.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 * @return array<string,array{caption:string,alt:string}>
	 */
	private static function image_text( array $attrs ): array {
		$list = json_decode( (string) ( $attrs['data-image-text'] ?? '' ), true );
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $list, 0, self::IMAGE_TEXT_ENTRIES ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id      = $entry['id'] ?? null;
			$caption = self::text( $entry['caption'] ?? null );
			$alt     = self::text( $entry['alt'] ?? null );
			if ( is_string( $id ) && '' !== $id && ( '' !== $caption || '' !== $alt ) ) {
				$out[ $id ] = array(
					'caption' => $caption,
					'alt'     => $alt,
				);
			}
		}
		return $out;
	}

	/**
	 * A text override: a string, cut to the script's limit, else ''.
	 *
	 * @param mixed $value Raw JSON value.
	 */
	private static function text( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		// The script cuts at 500 UTF-16 units, so a character outside the BMP counts twice.
		$units = (string) mb_convert_encoding( $value, 'UTF-16BE', 'UTF-8' );
		if ( strlen( $units ) <= self::IMAGE_TEXT_UNITS * 2 ) {
			return $value;
		}
		$cut  = substr( $units, 0, self::IMAGE_TEXT_UNITS * 2 );
		$last = hexdec( bin2hex( substr( $cut, -2 ) ) );
		// A cut between the halves of a surrogate pair leaves a lone one, which the markup writes as U+FFFD.
		$tail = $last >= 0xD800 && $last <= 0xDBFF ? "\xFF\xFD" : '';
		$cut  = '' === $tail ? $cut : substr( $cut, 0, -2 ) . $tail;
		return (string) mb_convert_encoding( $cut, 'UTF-8', 'UTF-16BE' );
	}

	/**
	 * The photo with its overrides: a caption replaces the title and caption, an alt replaces the alt.
	 *
	 * @param array<string,mixed>                            $photo Photo.
	 * @param array<string,array{caption:string,alt:string}> $text  Overrides by photo id.
	 * @return array<string,mixed>
	 */
	private static function with_text( array $photo, array $text ): array {
		$override = $text[ (string) ( $photo['id'] ?? '' ) ] ?? null;
		if ( null === $override ) {
			return $photo;
		}
		if ( '' !== $override['caption'] ) {
			$photo['title']   = '';
			$photo['caption'] = $override['caption'];
		}
		if ( '' !== $override['alt'] ) {
			$photo['alt'] = $override['alt'];
		}
		return $photo;
	}
}
