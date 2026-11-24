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
 * Both destinations (endpoint, project, key, environment) come from the
 * `error_reporting` and `usage_reporting` blocks the platform hands out in the
 * status call. Without its block nothing of that kind is sent. The plugin
 * source holds no key. Keys are never logged.
 */
final class Telemetry_Delivery {

	public const EVENTS_PATH   = '/api/v1/events';
	public const PAUSE_OPTION  = 'profotograaf_telemetry_queued_pause';
	public const DEFAULT_PAUSE = 300;
	public const MAX_PAUSE     = 86400;

	/**
	 * The usage destination the platform handed out, or null without one.
	 *
	 * @return array{endpoint:string,project:string,key:string,environment:string}|null
	 */
	public static function usage_destination(): ?array {
		return Platform_Status::stored_usage_reporting();
	}

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
	 * Base URL for usage events, or an empty string when there is no usage
	 * destination or sending is disabled.
	 */
	public static function usage_endpoint(): string {
		$destination = self::usage_destination();
		if ( null === $destination ) {
			return '';
		}
		/**
		 * Filters the base URL of the usage destination (FunnelBarn). It starts
		 * as the endpoint from the platform. The plugin appends /api/v1/events.
		 * An empty string disables sending usage.
		 *
		 * @param string $url Base URL.
		 */
		return self::clean_base( apply_filters( 'profotograaf_telemetry_usage_endpoint', $destination['endpoint'] ) );
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
		// Both kinds need the platform block for their key and project, besides the endpoint.
		$destination = $is_error ? self::error_destination() : self::usage_destination();
		if ( '' === $base || null === $destination ) {
			return true;
		}
		if ( self::paused_until() > 0 ) {
			return false;
		}

		$event    = self::event( $item, $is_error, $destination );
		$response = wp_remote_post(
			$base . self::EVENTS_PATH,
			array(
				'timeout'     => Config::http_timeout(),
				'redirection' => 0,
				'headers'     => self::headers( $destination, $is_error ),
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
	 * The event body of one queued item. Both kinds carry the environment of
	 * their destination block. A usage event falls back to the WordPress
	 * environment type when the block leaves it empty.
	 *
	 * @param array<string,mixed>                                                 $item        Queue item.
	 * @param bool                                                                $is_error    Whether the item is an error event.
	 * @param array{endpoint:string,project:string,key:string,environment:string} $destination Destination block of the item.
	 * @return array<string,mixed>
	 */
	private static function event( array $item, bool $is_error, array $destination ): array {
		if ( ! $is_error ) {
			return Telemetry_Payload::funnelbarn_event( $item, '' !== $destination['environment'] ? $destination['environment'] : self::environment() );
		}
		$event = Telemetry_Payload::bugbarn_event( $item );
		if ( '' !== $destination['environment'] ) {
			// BugBarn groups issues by `environment` and fills its environment
			// facet from `deployment.environment`, so the event carries both.
			$event['attributes']['environment']            = $destination['environment'];
			$event['attributes']['deployment.environment'] = $destination['environment'];
		}
		return $event;
	}

	/**
	 * Request headers. No Authorization or x-api-key header is sent, both
	 * services answer 401 to them.
	 *
	 * @param array{endpoint:string,project:string,key:string,environment:string} $destination Destination block of the item.
	 * @param bool                                                                $is_error    Whether the item is an error event.
	 * @return array<string,string>
	 */
	private static function headers( array $destination, bool $is_error ): array {
		if ( $is_error ) {
			return array(
				'Content-Type'      => 'application/json',
				'X-BugBarn-Api-Key' => $destination['key'],
				'X-BugBarn-Project' => $destination['project'],
			);
		}
		return array(
			'Content-Type'         => 'application/json',
			'X-FunnelBarn-Api-Key' => $destination['key'],
			'X-FunnelBarn-Project' => $destination['project'],
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
