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

	public const TYPE        = 'usage';
	public const USAGE_EVENT = 'daily_usage';

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
		'errors',
	);

	public const EVENT_FIELDS = array(
		'error_code',
		'http_status',
		'error_location',
		'plugin_version',
		'wordpress_version',
		'php_version',
		'timestamp',
	);

	public const MAX_ERRORS = 50;

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
		$payload['errors']      = self::errors( $input['errors'] ?? array() );

		return array_intersect_key( $payload, array_flip( self::FIELDS ) );
	}

	/**
	 * The FunnelBarn event for one usage batch: a daily_usage event whose
	 * session is the install id. Counters stay numbers, lists become strings.
	 *
	 * @param array<string,mixed> $batch       Raw usage batch.
	 * @param string              $environment WordPress environment type.
	 * @return array<string,mixed>
	 */
	public static function funnelbarn_event( array $batch, string $environment ): array {
		$clean      = self::build( $batch );
		$properties = array(
			'plugin_version'    => $clean['plugin_version'],
			'wordpress_version' => $clean['wordpress_version'],
			'php_version'       => $clean['php_version'],
			'locale'            => $clean['locale'],
			'active_modules'    => implode( ',', $clean['active_modules'] ),
		);
		foreach ( self::COUNTERS as $counter ) {
			$properties[ $counter ] = $clean[ $counter ];
		}
		foreach ( $clean['error_codes'] as $code => $count ) {
			$properties[ 'error_code_' . $code ] = $count;
		}
		return array(
			'name'        => self::USAGE_EVENT,
			'session_id'  => $clean['install_id'],
			'environment' => self::token( $environment, 20 ),
			'properties'  => $properties,
		);
	}

	/**
	 * The BugBarn event for one error. The code is the body, the exception
	 * type and the message. The stack trace has one frame, the plugin location.
	 *
	 * @param array<string,mixed> $item Error event, with the install id.
	 * @return array<string,mixed>
	 */
	public static function bugbarn_event( array $item ): array {
		$errors = self::errors( array( $item ) );
		$error  = $errors[0] ?? array(
			'error_code'        => 'unknown',
			'http_status'       => 0,
			'error_location'    => 'unknown',
			'plugin_version'    => '',
			'wordpress_version' => '',
			'php_version'       => '',
			'timestamp'         => '',
		);
		$frames = array();
		if ( 1 === preg_match( '/^(.+):(\d+)$/', $error['error_location'], $m ) ) {
			$frames[] = array(
				'function' => 'unknown',
				'filename' => $m[1],
				'lineno'   => (int) $m[2],
			);
		}
		$event = array(
			'body'         => $error['error_code'],
			'severityText' => 'error',
			'exception'    => array(
				'type'       => $error['error_code'],
				'message'    => $error['error_code'],
				'stacktrace' => $frames,
			),
			'attributes'   => array(
				'install_id'        => self::token( $item['install_id'] ?? '', 64 ),
				'http_status'       => $error['http_status'],
				'error_location'    => $error['error_location'],
				'plugin_version'    => $error['plugin_version'],
				'wordpress_version' => $error['wordpress_version'],
				'php_version'       => $error['php_version'],
			),
		);
		if ( '' !== $error['timestamp'] ) {
			$event['timestamp'] = $error['timestamp'];
		}
		return $event;
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

	/**
	 * Error events, at most MAX_ERRORS. Each keeps only EVENT_FIELDS.
	 *
	 * @param mixed $events Raw list of events.
	 * @return array<int,array<string,mixed>>
	 */
	private static function errors( $events ): array {
		$clean = array();
		foreach ( is_array( $events ) ? $events : array() as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$code = strtolower( self::token( $event['error_code'] ?? '', 64 ) );
			if ( '' === $code ) {
				continue;
			}
			$clean[] = array(
				'error_code'        => $code,
				'http_status'       => max( 0, min( 599, (int) ( $event['http_status'] ?? 0 ) ) ),
				'error_location'    => self::location( $event['error_location'] ?? '' ),
				'plugin_version'    => self::token( $event['plugin_version'] ?? '', 32 ),
				'wordpress_version' => self::major_minor( $event['wordpress_version'] ?? '' ),
				'php_version'       => self::major_minor( $event['php_version'] ?? '' ),
				'timestamp'         => self::timestamp( $event['timestamp'] ?? '' ),
			);
		}
		return array_slice( $clean, -self::MAX_ERRORS );
	}

	/**
	 * A plugin location such as `includes/x.php:9`, or `unknown`.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function location( $value ): string {
		$location = is_string( $value ) ? substr( (string) preg_replace( '/[^A-Za-z0-9._\/:-]/', '', $value ), 0, 120 ) : '';
		return '' === $location ? 'unknown' : $location;
	}

	/**
	 * A UTC timestamp such as `2024-10-01T09:30:00Z`, or an empty string.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function timestamp( $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ? $value : '';
	}
}
