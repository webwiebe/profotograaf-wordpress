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
		/* translators: %d: HTTP status code. */
		$message = isset( $body['error'] ) && is_string( $body['error'] ) && '' !== $body['error'] ? $body['error'] : sprintf( __( 'Profotograaf answered with HTTP %d.', 'profotograaf' ), $status );

		return new WP_Error(
			'profotograaf_http',
			$message,
			array(
				'status'      => $status,
				'code'        => isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '',
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
