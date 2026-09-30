<?php
/**
 * Transport backed by the WordPress HTTP API.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Sends requests with wp_remote_request(). Redirects are not followed: the API
 * does not redirect, and a redirect would carry the bearer token elsewhere.
 */
final class Wp_Transport implements Transport {

	/**
	 * Sends one HTTP request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Raw request body.
	 * @param int                  $timeout Timeout in seconds.
	 * @return array{status:int,headers:array<string,string>,body:string}|\WP_Error
	 */
	public function send( string $method, string $url, array $headers, ?string $body, int $timeout ) {
		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => $headers,
			'user-agent'  => 'ProfotograafWordPress/' . ( defined( 'PROFOTOGRAAF_VERSION' ) ? PROFOTOGRAAF_VERSION : '0' ),
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => array(
				'retry-after' => (string) wp_remote_retrieve_header( $response, 'retry-after' ),
			),
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
