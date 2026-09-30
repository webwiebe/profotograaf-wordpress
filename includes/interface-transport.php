<?php
/**
 * HTTP transport contract.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * The one place the plugin touches the network. Tests replace it with a fake.
 */
interface Transport {

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
	public function send( string $method, string $url, array $headers, ?string $body, int $timeout );
}
