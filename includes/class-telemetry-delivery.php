<?php
/**
 * Delivery of telemetry events to BugBarn and FunnelBarn.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Posts one telemetry event per request. Errors go to BugBarn, the daily usage
 * counters go to FunnelBarn. Both hosts take `POST <base>/api/v1/events` with
 * their own key and project headers.
 *
 * The error destination (endpoint, project, key, environment) comes from the
 * `error_reporting` block the platform hands out in the status call. Without
 * that block no error is sent. The FunnelBarn key is an ingest-only collector
 * key that ships in the plugin source. Keys are never logged.
 */
final class Telemetry_Delivery {

	public const USAGE_ENDPOINT = 'https://f.profotograaf.nl';
	public const EVENTS_PATH    = '/api/v1/events';
	public const PROJECT        = 'profotograaf-wordpress';
	public const FUNNELBARN_KEY = '87e9507246186dde1a354a5e333d42b445c2a267';
	public const PAUSE_OPTION   = 'profotograaf_telemetry_queued_pause';
	public const DEFAULT_PAUSE  = 300;
	public const MAX_PAUSE      = 86400;

	/**
	 * The error destination the platform handed out, or null without one.
	 *
	 * @return array{endpoint:string,project:string,key:string,environment:string}|null
	 */
	public static function error_destination(): ?array {
		return Platform_Status::stored_error_reporting();
	}

	/**
	 * Base URL for error events, or an empty string when there is no error
	 * destination or sending is disabled.
	 */
	public static function errors_endpoint(): string {
		$destination = self::error_destination();
		if ( null === $destination ) {
			return '';
		}
		/**
		 * Filters the base URL of the error destination (BugBarn). It starts as
		 * the endpoint from the platform. The plugin appends /api/v1/events. An
		 * empty string disables sending errors.
		 *
		 * @param string $url Base URL.
		 */
		return self::clean_base( apply_filters( 'profotograaf_telemetry_endpoint', $destination['endpoint'] ) );
	}

	/**
	 * Base URL for usage events, or an empty string when sending is disabled.
	 */
	public static function usage_endpoint(): string {
		/**
		 * Filters the base URL of the usage destination (FunnelBarn). The plugin
		 * appends /api/v1/events. An empty string disables sending usage.
		 *
		 * @param string $url Base URL.
		 */
		return self::clean_base( apply_filters( 'profotograaf_telemetry_usage_endpoint', self::USAGE_ENDPOINT ) );
	}

	/**
	 * The time sending resumes after a 429 or 503 answer, or 0 when it is not paused.
	 */
	public static function paused_until(): int {
		$until = (int) get_option( self::PAUSE_OPTION, 0 );
		return $until > time() ? $until : 0;
	}

	/**
	 * Delivers one queued item.
	 *
	 * @param array<string,mixed> $item Queue item. An item of type `error` is a BugBarn event, anything else is a usage batch.
	 * @return bool Whether the item is done. A disabled destination counts as done, so the item is dropped.
	 */
	public static function deliver( array $item ): bool {
		$is_error = 'error' === ( $item['type'] ?? '' );
		$base     = $is_error ? self::errors_endpoint() : self::usage_endpoint();
		// An error needs the platform block for its key and project, besides the endpoint.
		$destination = $is_error ? self::error_destination() : null;
		if ( '' === $base || ( $is_error && null === $destination ) ) {
			return true;
		}
		if ( self::paused_until() > 0 ) {
			return false;
		}

		$event = $is_error ? Telemetry_Payload::bugbarn_event( $item ) : Telemetry_Payload::funnelbarn_event( $item, self::environment() );
		if ( null !== $destination && '' !== $destination['environment'] ) {
			// The BugBarn SDKs send the environment as an event attribute.
			$event['attributes']['environment'] = $destination['environment'];
		}
		$response = wp_remote_post(
			$base . self::EVENTS_PATH,
			array(
				'timeout'     => Config::http_timeout(),
				'redirection' => 0,
				'headers'     => self::headers( $destination ),
				'body'        => (string) wp_json_encode( $event ),
			)
		);
		if ( is_wp_error( $response ) ) {
			Logger::warning( 'Telemetry could not be sent.', array( 'error' => $response->get_error_code() ) );
			return false;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $status || 503 === $status ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			self::pause( is_string( $retry_after ) ? $retry_after : '' );
		}
		if ( $status < 200 || $status >= 300 ) {
			Logger::warning( 'The telemetry endpoint refused an event.', array( 'status' => $status ) );
			return false;
		}
		return true;
	}

	/**
	 * Request headers. No Authorization or x-api-key header is sent, both
	 * services answer 401 to them.
	 *
	 * @param array{endpoint:string,project:string,key:string,environment:string}|null $destination Error destination, or null for a usage request.
	 * @return array<string,string>
	 */
	private static function headers( ?array $destination ): array {
		if ( null !== $destination ) {
			return array(
				'Content-Type'      => 'application/json',
				'X-BugBarn-Api-Key' => $destination['key'],
				'X-BugBarn-Project' => $destination['project'],
			);
		}
		return array(
			'Content-Type'         => 'application/json',
			'X-FunnelBarn-Api-Key' => self::FUNNELBARN_KEY,
			'X-FunnelBarn-Project' => self::PROJECT,
		);
	}

	/**
	 * Stops sending for the number of seconds in a Retry-After header, or five
	 * minutes without one. At most one day.
	 *
	 * @param string $retry_after Header value in seconds.
	 */
	private static function pause( string $retry_after ): void {
		$seconds = ctype_digit( $retry_after ) && (int) $retry_after > 0 ? (int) $retry_after : self::DEFAULT_PAUSE;
		update_option( self::PAUSE_OPTION, time() + min( $seconds, self::MAX_PAUSE ), false );
	}

	/**
	 * The WordPress environment type, for example `production`.
	 */
	private static function environment(): string {
		return wp_get_environment_type();
	}

	/**
	 * A base URL with an http or https scheme and a host, or an empty string.
	 *
	 * @param mixed $url Raw value from a filter.
	 */
	private static function clean_base( $url ): string {
		$url    = rtrim( trim( (string) $url ), '/' );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		return '' !== $host && in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}
}
