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
	 * scopes the platform grants: galleries:read, leads:write,
	 * galleries:embed and galleries:write. It is also the `source` value of a lead and the
	 * `platform` a pairing reports.
	 */
	public const CLIENT_ID = 'wordpress';

	/**
	 * The permissions this plugin asks for when it connects. The platform
	 * grants the scopes registered for the client id, so this is the request
	 * the approval page shows, not a way to widen the grant.
	 */
	public const SCOPES = array( 'galleries:read', 'leads:write', 'galleries:embed', self::UPLOAD_SCOPE );

	/**
	 * The scope for the two write routes: creating a gallery and uploading a
	 * photo. It covers nothing else. The plugin asks for it at pairing on
	 * every site. A token paired before it existed lacks it, and the platform
	 * grants the whole list registered for the client id whatever the request
	 * names, so a narrower request does not give a narrower token.
	 */
	public const UPLOAD_SCOPE = 'galleries:write';

	/**
	 * Bumped when the plugin starts asking for a scope it did not ask for
	 * before. A connection paired under an older revision holds a token
	 * without the newer scope and has to be paired again.
	 */
	public const SCOPE_REVISION = 2;

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
