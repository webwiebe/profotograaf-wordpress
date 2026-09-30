<?php
/**
 * Static configuration.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Where the platform lives and how the plugin identifies itself to it.
 */
final class Config {

	public const DEFAULT_PLATFORM_URL = 'https://profotograaf.nl';

	/**
	 * The client id the platform registers for this plugin. It decides the
	 * scopes the platform grants: galleries:read, leads:write and
	 * galleries:embed. It is also the `source` value of a lead and the
	 * `platform` a pairing reports.
	 */
	public const CLIENT_ID = 'wordpress';

	public const DEFAULT_TIMEOUT = 10;

	/**
	 * Base URL of the platform, without a trailing slash.
	 *
	 * Overridable for testing with the PROFOTOGRAAF_PLATFORM_URL constant (for
	 * example in wp-config.php) or the `profotograaf_platform_url` filter.
	 */
	public static function platform_url(): string {
		$url = defined( 'PROFOTOGRAAF_PLATFORM_URL' ) ? (string) constant( 'PROFOTOGRAAF_PLATFORM_URL' ) : self::DEFAULT_PLATFORM_URL;

		/**
		 * Filters the platform base URL.
		 *
		 * @param string $url Base URL such as https://profotograaf.nl.
		 */
		$url = (string) apply_filters( 'profotograaf_platform_url', $url );

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return self::DEFAULT_PLATFORM_URL;
		}
		return rtrim( $url, '/' );
	}

	/**
	 * Absolute URL for a platform path.
	 *
	 * @param string $path Path starting with a slash.
	 */
	public static function platform_endpoint( string $path ): string {
		return self::platform_url() . '/' . ltrim( $path, '/' );
	}

	/**
	 * Timeout in seconds for every request to the platform.
	 */
	public static function http_timeout(): int {
		/**
		 * Filters the request timeout in seconds.
		 *
		 * @param int $seconds Timeout, 1 to 30.
		 */
		$seconds = (int) apply_filters( 'profotograaf_http_timeout', self::DEFAULT_TIMEOUT );
		return max( 1, min( 30, $seconds ) );
	}

	/**
	 * The origin (scheme, host and optional port) of this site.
	 */
	public static function site_origin(): string {
		$home   = home_url();
		$scheme = strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		$port   = wp_parse_url( $home, PHP_URL_PORT );
		if ( '' === $host || '' === $scheme ) {
			return '';
		}
		return $scheme . '://' . $host . ( $port ? ':' . (int) $port : '' );
	}
}
