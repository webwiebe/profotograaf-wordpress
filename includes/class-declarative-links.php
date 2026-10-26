<?php
/**
 * Where a lightbox-off tile links, in the server-drawn gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * A port of professionals web/src/public/embed/_lib/links.ts, held to the same
 * golden files (tests/fixtures/embed/link-*). The URL comes from the gallery
 * owner, so only http(s) links, absolute or relative, are ever returned; any
 * other scheme gives the empty string and the tile is an inert box.
 */
final class Declarative_Links {

	/**
	 * The link targets embed.js knows.
	 *
	 * @var string[]
	 */
	private const TARGETS = array( 'none', 'page', 'site', 'file' );

	/**
	 * The link target from `data-link-to`: one of none, page, site, file, else ''.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	public static function target( array $attrs ): string {
		$value = strtolower( trim( $attrs['data-link-to'] ?? '' ) );
		return in_array( $value, self::TARGETS, true ) ? $value : '';
	}

	/**
	 * Whether a link opens in a new tab: on unless `data-link-new-tab` turns it off.
	 *
	 * @param array<string,string> $attrs Host attributes.
	 */
	public static function new_tab( array $attrs ): bool {
		$value = strtolower( trim( $attrs['data-link-new-tab'] ?? '' ) );
		return ! in_array( $value, array( 'off', 'false', '0', 'no' ), true );
	}

	/**
	 * Where a photo's tile links, '' for none or when the payload lacks the target.
	 *
	 * @param array<string,mixed> $photo   Photo.
	 * @param array<string,mixed> $gallery Gallery payload.
	 * @param string              $target  Value of target().
	 */
	public static function href( array $photo, array $gallery, string $target ): string {
		if ( 'file' === $target ) {
			return self::web( self::best_url( (array) ( $photo['images'] ?? array() ) ) );
		}
		if ( 'page' === $target ) {
			$page = self::web( (string) ( $photo['url'] ?? '' ) );
			return '' !== $page ? $page : self::web( (string) ( $gallery['url'] ?? '' ) );
		}
		if ( 'site' === $target ) {
			return self::web( (string) ( $gallery['site_url'] ?? '' ) );
		}
		return '';
	}

	/**
	 * The value when it is an http(s) or relative URL, else ''. Like the script's
	 * URL parser, leading and trailing control characters and spaces, and tabs and
	 * newlines anywhere, are ignored when the scheme is read. An absolute URL needs
	 * a host, which is slightly stricter than the script and only ever gives an inert box.
	 *
	 * @param string $value Raw URL from the payload.
	 */
	private static function web( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$probe = trim( (string) preg_replace( '/[\t\n\r]/', '', $value ), "\x00..\x20" );
		if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $probe, $m ) ) {
			if ( ! in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ) {
				return '';
			}
			$parts = parse_url( $probe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- a pure function, so the renderer needs no WordPress to run.
			$host  = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
			// A bracket only belongs to an IPv6 literal.
			$ok = '' !== $host && ( false === strpbrk( $host, '[]' ) || 1 === preg_match( '/^\[[0-9a-fA-F:.]+\]$/', $host ) );
			return $ok ? $value : '';
		}
		return $value;
	}

	/**
	 * The largest image: the web variant, else the last one.
	 *
	 * @param array<int,mixed> $images Payload images.
	 */
	private static function best_url( array $images ): string {
		$best = '';
		foreach ( $images as $image ) {
			$image = (array) $image;
			$best  = (string) ( $image['url'] ?? '' );
			if ( 'web' === ( $image['variant'] ?? '' ) ) {
				break;
			}
		}
		return $best;
	}
}
