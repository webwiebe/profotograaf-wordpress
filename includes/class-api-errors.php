<?php
/**
 * Builds the WP_Error values the API client returns.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Gives every Api_Client error the same data shape (`status`, `retryable`) and
 * logs the failures whose detail the error itself drops.
 */
final class Api_Errors {

	/**
	 * Builds an error with the standard data shape.
	 *
	 * @param string $code      Error code.
	 * @param string $message   Message.
	 * @param int    $status    HTTP status, 0 when none.
	 * @param bool   $retryable Whether a later attempt can succeed.
	 */
	public static function make( string $code, string $message, int $status, bool $retryable ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'    => $status,
				'retryable' => $retryable,
			)
		);
	}

	/**
	 * Platform error codes that have a local message.
	 *
	 * @return array<int,string>
	 */
	public static function known_codes(): array {
		return array(
			'device.expired',
			'device.slow_down',
			'device.unknown_code',
			'device.unknown_client',
			'lead.invalid',
		);
	}

	/**
	 * Translated message for a platform error code, empty for an unknown code.
	 *
	 * @param string $code Platform error code.
	 */
	public static function message_for( string $code ): string {
		switch ( $code ) {
			case 'device.expired':
				return __( 'The connection to Profotograaf has expired. Connect this site again.', 'profotograaf' );
			case 'device.slow_down':
				return __( 'Profotograaf asked for fewer connection checks. Trying again shortly.', 'profotograaf' );
			case 'device.unknown_code':
				return __( 'Profotograaf does not know this connection code. Start the connection again.', 'profotograaf' );
			case 'device.unknown_client':
				return __( 'Profotograaf does not recognise this site. Start the connection again.', 'profotograaf' );
			case 'lead.invalid':
				return __( 'Profotograaf rejected the enquiry because it is incomplete or invalid.', 'profotograaf' );
			default:
				return '';
		}
	}

	/**
	 * Error for a site without a connection.
	 */
	public static function not_connected(): WP_Error {
		return self::make( 'profotograaf_not_connected', __( 'This site is not connected to Profotograaf.', 'profotograaf' ), 0, false );
	}

	/**
	 * Builds the error for an HTTP status of 400 or above.
	 *
	 * @param array{status:int,retry_after:int,body:mixed} $response Response.
	 */
	public static function http( array $response ): WP_Error {
		$status = $response['status'];
		$body   = is_array( $response['body'] ) ? $response['body'] : array();
		$code   = isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '';
		$raw    = isset( $body['error'] ) && is_string( $body['error'] ) ? $body['error'] : '';

		$message = self::message_for( $code );
		if ( '' === $message && '' !== $raw ) {
			Logger::debug(
				'The platform sent an error text without a known code.',
				array(
					'status' => $status,
					'code'   => $code,
					'reason' => $raw,
				)
			);
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'The platform reported an error (HTTP %d). Try again later.', 'profotograaf' ), $status );
		}
		if ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'Profotograaf answered with HTTP %d.', 'profotograaf' ), $status );
		}

		return new WP_Error(
			'profotograaf_http',
			$message,
			array(
				'status'      => $status,
				'code'        => $code,
				'retryable'   => 408 === $status || 429 === $status || $status >= 500,
				'retry_after' => $response['retry_after'],
			)
		);
	}

	/**
	 * Logs the transport error that the generic network error replaces.
	 *
	 * @param WP_Error $error  Error from the transport.
	 * @param string   $method HTTP method.
	 * @param string   $path   Path or label of the request.
	 */
	public static function log_transport( WP_Error $error, string $method, string $path ): void {
		Logger::error(
			'The request did not complete.',
			array(
				'method'    => $method,
				'path'      => $path,
				'error'     => $error->get_error_code(),
				'reason'    => $error->get_error_message(),
				'retryable' => true,
			)
		);
	}
}
