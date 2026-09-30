<?php
/**
 * Scripted HTTP layer.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Profotograaf\Transport;

/**
 * Answers requests from a queue and records what was sent. No network.
 */
final class Fake_Transport implements Transport {

	/**
	 * Requests seen so far.
	 *
	 * @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}>
	 */
	public array $requests = array();

	/**
	 * Queued answers. A Throwable in the queue is thrown, a WP_Error returned.
	 *
	 * @var array<int,mixed>
	 */
	private array $queue = array();

	/**
	 * Queues a JSON answer.
	 *
	 * @param int                 $status  HTTP status.
	 * @param array<mixed>|null   $body    Body, encoded as JSON. Null for an empty body.
	 * @param array<string,string> $headers Response headers.
	 */
	public function reply( int $status, ?array $body = null, array $headers = array() ): self {
		$this->queue[] = array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => null === $body ? '' : json_encode( $body ),
		);
		return $this;
	}

	/**
	 * Queues a failure: a WP_Error or a Throwable.
	 *
	 * @param \WP_Error|\Throwable $failure What to do.
	 */
	public function fail( $failure ): self {
		$this->queue[] = $failure;
		return $this;
	}

	/**
	 * Decoded JSON body of request $index.
	 *
	 * @param int $index Request position.
	 * @return array<mixed>
	 */
	public function body( int $index ): array {
		return json_decode( (string) $this->requests[ $index ]['body'], true );
	}

	/**
	 * Sends one request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Headers.
	 * @param string|null          $body    Body.
	 * @param int                  $timeout Timeout.
	 * @return array{status:int,headers:array<string,string>,body:string}|\WP_Error
	 * @throws \Throwable When the queued answer is a Throwable.
	 */
	public function send( string $method, string $url, array $headers, ?string $body, int $timeout ) {
		$this->requests[] = compact( 'method', 'url', 'headers', 'body', 'timeout' );
		if ( array() === $this->queue ) {
			throw new \LogicException( 'Unexpected request: ' . $method . ' ' . $url );
		}
		$next = array_shift( $this->queue );
		if ( $next instanceof \Throwable ) {
			throw $next;
		}
		return $next;
	}
}
