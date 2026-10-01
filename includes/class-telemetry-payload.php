<?php
/**
 * The telemetry payload with its allow-list.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the usage batch described in docs/telemetry.md, "Usage telemetry".
 *
 * The build method copies only the fields in FIELDS and coerces each to its type, so a
 * value that is not on the list never reaches the wire, whatever the caller
 * passes in. The site URL, user data and gallery or lead content have no
 * field here.
 */
final class Telemetry_Payload {

	public const TYPE = 'usage';

	public const COUNTERS = array(
		'refresh_success',
		'refresh_failed',
		'refresh_retried',
		'delivery_success',
		'delivery_failed',
	);

	public const FIELDS = array(
		'type',
		'install_id',
		'plugin_version',
		'wordpress_version',
		'php_version',
		'locale',
		'active_modules',
		'refresh_success',
		'refresh_failed',
		'refresh_retried',
		'delivery_success',
		'delivery_failed',
		'error_codes',
	);

	private const MAX_MODULES     = 30;
	private const MAX_ERROR_CODES = 20;

	/**
	 * Builds the payload from raw input. Unknown keys are dropped.
	 *
	 * @param array<string,mixed> $input Raw values.
	 * @return array<string,mixed>
	 */
	public static function build( array $input ): array {
		$payload = array(
			'type'              => self::TYPE,
			'install_id'        => self::token( $input['install_id'] ?? '', 64 ),
			'plugin_version'    => self::token( $input['plugin_version'] ?? '', 32 ),
			'wordpress_version' => self::major_minor( $input['wordpress_version'] ?? '' ),
			'php_version'       => self::major_minor( $input['php_version'] ?? '' ),
			'locale'            => self::token( $input['locale'] ?? '', 12 ),
			'active_modules'    => self::modules( $input['active_modules'] ?? array() ),
		);
		foreach ( self::COUNTERS as $counter ) {
			$payload[ $counter ] = max( 0, (int) ( $input[ $counter ] ?? 0 ) );
		}
		$payload['error_codes'] = self::error_codes( $input['error_codes'] ?? array() );

		return array_intersect_key( $payload, array_flip( self::FIELDS ) );
	}

	/**
	 * Keeps the major and minor part of a version, for example 6.9 from 6.9.1.
	 *
	 * @param mixed $version Version string.
	 */
	public static function major_minor( $version ): string {
		return is_string( $version ) && 1 === preg_match( '/^(\d+)\.(\d+)/', $version, $m ) ? $m[1] . '.' . $m[2] : '';
	}

	/**
	 * A short identifier made of letters, digits, dots, dashes and underscores.
	 *
	 * @param mixed $value  Raw value.
	 * @param int   $length Maximum length.
	 */
	private static function token( $value, int $length ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return substr( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $value ), 0, $length );
	}

	/**
	 * Module slugs.
	 *
	 * @param mixed $modules Raw list.
	 * @return string[]
	 */
	private static function modules( $modules ): array {
		$clean = array();
		foreach ( is_array( $modules ) ? $modules : array() as $module ) {
			$slug = strtolower( self::token( $module, 40 ) );
			if ( '' !== $slug ) {
				$clean[ $slug ] = $slug;
			}
		}
		sort( $clean );
		return array_slice( $clean, 0, self::MAX_MODULES );
	}

	/**
	 * Error code counts.
	 *
	 * @param mixed $codes Raw map of code to count.
	 * @return array<string,int>
	 */
	private static function error_codes( $codes ): array {
		$clean = array();
		foreach ( is_array( $codes ) ? $codes : array() as $code => $count ) {
			$slug = strtolower( self::token( (string) $code, 64 ) );
			if ( '' !== $slug && (int) $count > 0 ) {
				$clean[ $slug ] = ( $clean[ $slug ] ?? 0 ) + (int) $count;
			}
		}
		ksort( $clean );
		return array_slice( $clean, 0, self::MAX_ERROR_CODES, true );
	}
}
