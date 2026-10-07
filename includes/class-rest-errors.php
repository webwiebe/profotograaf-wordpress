<?php
/**
 * REST error responses for platform failures.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an Api_Client error into the REST response the editor routes return.
 */
final class Rest_Errors {

	/**
	 * Seconds to wait when the platform rate limits without saying for how long.
	 */
	private const DEFAULT_RETRY_AFTER = 60;

	/**
	 * Gives an API error an HTTP status for the REST response.
	 *
	 * A platform rate limit stays a 429 and carries a Retry-After header, which
	 * a WP_Error cannot, so that case is a WP_REST_Response with the same body
	 * shape the REST server builds for an error. Timeouts and unreachable
	 * platforms are 504. Other platform failures are 502.
	 *
	 * @param \WP_Error $error Error from the API client.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public static function from( \WP_Error $error ) {
		$statuses = array(
			'profotograaf_not_connected' => 409,
			'profotograaf_reconnect'     => 403,
			'profotograaf_invalid'       => 400,
			'profotograaf_network'       => 504,
		);
		$code     = (string) $error->get_error_code();
		$source   = $error->get_error_data();
		$source   = is_array( $source ) ? $source : array();
		$upstream = (int) ( $source['status'] ?? 0 );
		$status   = $statuses[ $code ] ?? 502;
		if ( 'profotograaf_http' === $code ) {
			if ( 429 === $upstream ) {
				$status = 429;
			} elseif ( 408 === $upstream || 504 === $upstream ) {
				$status = 504;
			}
		}

		$data = array(
			'status'    => $status,
			'retryable' => (bool) ( $source['retryable'] ?? false ),
		);
		if ( 429 === $status ) {
			$retry_after         = (int) ( $source['retry_after'] ?? 0 );
			$data['retry_after'] = $retry_after > 0 ? $retry_after : self::DEFAULT_RETRY_AFTER;
			return new \WP_REST_Response(
				array(
					'code'    => $code,
					'message' => $error->get_error_message(),
					'data'    => $data,
				),
				429,
				array( 'Retry-After' => (string) $data['retry_after'] )
			);
		}
		return new \WP_Error( $code, $error->get_error_message(), $data );
	}
}
