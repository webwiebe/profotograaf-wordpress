<?php
/**
 * Image sources, sizes and links of the full-page gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the image variants of one payload photo the way embed.js does.
 *
 * The payload lists every photo's images smallest first: `thumb` (a 400 px
 * square crop), `web` (1600 px on the long side, the photo's own shape) and,
 * once wiebe-xyz/professionals#2393 ships, a larger variant. A watermarked
 * gallery lists only `web`. The `src` is `web`. The `srcset` holds every
 * variant with the photo's shape; `thumb` joins it only when the tile is a
 * square or the photo is one, so a cropped square never stands in for a
 * landscape tile.
 */
final class Full_Page_Images {

	/** Largest difference in aspect ratio that still counts as the same shape. */
	private const SAME_SHAPE = 0.03;

	/**
	 * The URL of the `web` variant, or of the last image when there is no `web`.
	 *
	 * @param array<string,mixed> $photo Payload photo.
	 */
	public static function src( array $photo ): string {
		$images = self::images( $photo );
		foreach ( $images as $image ) {
			if ( 'web' === $image['variant'] ) {
				return $image['url'];
			}
		}
		$last = end( $images );
		return false === $last ? '' : $last['url'];
	}

	/**
	 * The srcset of one photo.
	 *
	 * @param array<string,mixed> $photo  Payload photo.
	 * @param bool                $square Whether the tile crops the photo to a square.
	 */
	public static function srcset( array $photo, bool $square ): string {
		[ $width, $height ] = self::size( $photo );
		$ratio              = $height > 0 ? $width / $height : 0;
		$entries            = array();
		foreach ( self::images( $photo ) as $image ) {
			$same_shape = $ratio > 0 && $image['height'] > 0 && abs( $image['width'] / $image['height'] - $ratio ) < self::SAME_SHAPE;
			if ( 'thumb' === $image['variant'] && ! $square && ! $same_shape ) {
				continue;
			}
			if ( $image['width'] > 0 ) {
				$entries[] = $image['url'] . ' ' . $image['width'] . 'w';
			}
		}
		return implode( ', ', $entries );
	}

	/**
	 * Width and height of the photo: its own, else those of the `web` variant.
	 *
	 * @param array<string,mixed> $photo Payload photo.
	 * @return array{0:int,1:int}
	 */
	public static function size( array $photo ): array {
		$width  = (int) ( $photo['width'] ?? 0 );
		$height = (int) ( $photo['height'] ?? 0 );
		if ( $width > 0 && $height > 0 ) {
			return array( $width, $height );
		}
		foreach ( self::images( $photo ) as $image ) {
			if ( 'web' === $image['variant'] && $image['width'] > 0 && $image['height'] > 0 ) {
				return array( $image['width'], $image['height'] );
			}
		}
		return array( 0, 0 );
	}

	/**
	 * The centre of the photographer's crop box as an object-position value, or
	 * an empty string when the photo has none.
	 *
	 * @param array<string,mixed> $photo Payload photo.
	 */
	public static function focus( array $photo ): string {
		$crop = $photo['crop'] ?? null;
		if ( ! is_array( $crop ) || ! is_numeric( $crop['x'] ?? null ) || ! is_numeric( $crop['y'] ?? null ) || ! is_numeric( $crop['w'] ?? null ) || ! is_numeric( $crop['h'] ?? null ) ) {
			return '';
		}
		$x = max( 0, min( 100, ( (float) $crop['x'] + (float) $crop['w'] / 2 ) * 100 ) );
		$y = max( 0, min( 100, ( (float) $crop['y'] + (float) $crop['h'] / 2 ) * 100 ) );
		return round( $x, 2 ) . '% ' . round( $y, 2 ) . '%';
	}

	/**
	 * The sizes attribute for a photo in a section.
	 *
	 * @param array<string,mixed> $section  Sequenced section.
	 * @param int                 $position Position of the photo in the section.
	 * @param float               $ratio    Width over height of the photo.
	 */
	public static function sizes( array $section, int $position, float $ratio ): string {
		$phone = '(max-width: ' . ( Sections_Schema::phone_breakpoint() - 1 ) . 'px) ';
		switch ( $section['type'] ) {
			case 'hero':
				return '100vw';
			case 'feature':
				return $phone . '100vw, ' . min( 100, (int) ceil( 125 / max( 1, (int) $section['count'] ) ) ) . 'vw';
			case 'pair':
				return 0 === $position ? $phone . '100vw, 67vw' : $phone . '50vw, 34vw';
			case 'filmstrip':
				$phone_row = (int) ( Sections_Schema::phone( 'filmstrip' )['rowHeight'] ?? $section['rowHeight'] );
				return $phone . (int) ceil( $phone_row * $ratio ) . 'px, ' . (int) ceil( (int) $section['rowHeight'] * $ratio ) . 'px';
			default:
				return $phone . (int) ceil( 100 / self::phone_columns( $section ) ) . 'vw, ' . (int) ceil( 100 / max( 1, (int) $section['columns'] ) ) . 'vw';
		}
	}

	/**
	 * Columns of a grid or masonry section below the phone breakpoint: the
	 * phone value of the type, never more than the section's own columns.
	 *
	 * @param array<string,mixed> $section Sequenced section.
	 */
	public static function phone_columns( array $section ): int {
		$columns = max( 1, (int) ( $section['columns'] ?? 1 ) );
		return min( $columns, max( 1, (int) ( Sections_Schema::phone( (string) $section['type'] )['columns'] ?? $columns ) ) );
	}

	/**
	 * Where a photo links with the lightbox off, as embed.js decides it: the
	 * photo page (or the gallery page), the photographer's site, the web image,
	 * or nothing.
	 *
	 * @param array<string,mixed> $photo   Payload photo.
	 * @param array<string,mixed> $gallery Payload.
	 * @param string              $link_to `page`, `site`, `file`, or anything else for none.
	 */
	public static function link( array $photo, array $gallery, string $link_to ): string {
		switch ( $link_to ) {
			case 'file':
				return self::web_url( self::src( $photo ) );
			case 'page':
				$page = self::web_url( $photo['url'] ?? '' );
				return '' !== $page ? $page : self::web_url( $gallery['url'] ?? '' );
			case 'site':
				return self::web_url( $gallery['site_url'] ?? '' );
			default:
				return '';
		}
	}

	/**
	 * An http(s) URL, or an empty string.
	 *
	 * @param mixed $url Candidate.
	 */
	public static function web_url( $url ): string {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url ) {
			return '';
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}

	/**
	 * The usable images of a photo: each with a variant, an http(s) URL and its size.
	 *
	 * @param array<string,mixed> $photo Payload photo.
	 * @return array<int,array{variant:string,url:string,width:int,height:int}>
	 */
	private static function images( array $photo ): array {
		$out = array();
		foreach ( is_array( $photo['images'] ?? null ) ? $photo['images'] : array() as $image ) {
			$url = is_array( $image ) ? self::web_url( $image['url'] ?? '' ) : '';
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'variant' => is_string( $image['variant'] ?? null ) ? $image['variant'] : '',
				'url'     => $url,
				'width'   => (int) ( $image['width'] ?? 0 ),
				'height'  => (int) ( $image['height'] ?? 0 ),
			);
		}
		return $out;
	}
}
