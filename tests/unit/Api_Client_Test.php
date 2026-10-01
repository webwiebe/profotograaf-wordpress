<?php
/**
 * Api_Client tests, with a faked HTTP layer.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Profotograaf\Api_Client;
use Profotograaf\Connection;

class Api_Client_Test extends Wp_Test_Case {

	private Fake_Transport $http;

	private Api_Client $api;

	protected function setUp(): void {
		parent::setUp();
		$this->http = new Fake_Transport();
		$this->api  = new Api_Client( new Connection(), $this->http, $this->clock() );
	}

	private function refresh_reply(): void {
		$this->http->reply(
			200,
			array(
				'status'        => 'approved',
				'access_token'  => 'access-2',
				'refresh_token' => 'refresh-2',
				'token_type'    => 'Bearer',
				'expires_in'    => 900,
			)
		);
	}

	public function test_requests_without_a_connection_fail_without_touching_the_network(): void {
		$result = $this->api->list_galleries();

		$this->assertWPError( $result, 'profotograaf_not_connected' );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_list_galleries_sends_the_bearer_and_normalises_rows(): void {
		$this->connect();
		$this->http->reply(
			200,
			array(
				array(
					'id'          => 'g1',
					'slug'        => 'spring',
					'title'       => 'Spring wedding',
					'url'         => 'https://profotograaf.nl/share/g/spring',
					'embeddable'  => true,
					'available'   => false,
					'photo_count' => 12,
					'cover_url'   => 'https://cdn.example/cover.jpg',
					'cover_alt'   => 'The couple at the altar',
					'updated_at'  => '2026-09-01T10:00:00Z',
				),
				array( 'title' => 'row without an id is dropped' ),
			)
		);

		$rows = $this->api->list_galleries();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'g1', $rows[0]['id'] );
		$this->assertTrue( $rows[0]['embeddable'] );
		$this->assertFalse( $rows[0]['available'] );
		$this->assertSame( 12, $rows[0]['photo_count'] );
		$this->assertSame( 'The couple at the altar', $rows[0]['cover_alt'] );

		$request = $this->http->requests[0];
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries', $request['url'] );
		$this->assertSame( 'Bearer access-1', $request['headers']['Authorization'] );
		$this->assertGreaterThan( 0, $request['timeout'] );
	}

	public function test_an_expiring_token_is_refreshed_before_the_request(): void {
		$this->connect( 10 );
		$this->refresh_reply();
		$this->http->reply( 200, array() );

		$this->assertSame( array(), $this->api->list_galleries() );

		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( 'https://profotograaf.nl/api/v1/auth/devices/refresh', $this->http->requests[0]['url'] );
		$this->assertSame( array( 'refresh_token' => 'refresh-1' ), $this->http->body( 0 ) );
		$this->assertArrayNotHasKey( 'Authorization', $this->http->requests[0]['headers'] );
		$this->assertSame( 'Bearer access-2', $this->http->requests[1]['headers']['Authorization'] );
		$this->assertSame( 'refresh-2', $this->options['profotograaf_connection']['refresh_token'] );
		$this->assertArrayNotHasKey( 'profotograaf_refresh_lock', $this->options );
	}

	public function test_a_401_refreshes_once_and_retries(): void {
		$this->connect();
		$this->http->reply( 401, array( 'error' => 'expired' ) );
		$this->refresh_reply();
		$this->http->reply( 200, array() );

		$this->assertSame( array(), $this->api->list_galleries() );

		$this->assertCount( 3, $this->http->requests );
		$this->assertSame( 'Bearer access-2', $this->http->requests[2]['headers']['Authorization'] );
	}

	public function test_a_second_401_is_returned_and_does_not_loop(): void {
		$this->connect();
		$this->http->reply( 401, array( 'error' => 'no' ) );
		$this->refresh_reply();
		$this->http->reply( 401, array( 'error' => 'no' ) );

		$result = $this->api->list_galleries();

		$this->assertWPError( $result, 'profotograaf_http' );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertCount( 3, $this->http->requests );
	}

	public function test_a_revoked_connection_clears_the_tokens_and_says_so(): void {
		$this->connect( 10 );
		Actions\expectDone( 'profotograaf_disconnected' )->once()->with( 'revoked' );
		$this->http->reply( 401, array( 'error' => 'invalid or expired refresh token', 'code' => 'device.expired' ) );

		$result = $this->api->list_galleries();

		$this->assertWPError( $result, 'profotograaf_not_connected' );
		$connection = new Connection();
		$this->assertFalse( $connection->is_connected() );
		$this->assertSame( 'revoked', $connection->status()['state'] );
	}

	public function test_a_failed_refresh_keeps_the_tokens_for_a_later_attempt(): void {
		$this->connect( 10 );
		$this->http->reply( 503, array( 'error' => 'down' ) );

		$result = $this->api->list_galleries();

		$this->assertWPError( $result, 'profotograaf_http' );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertTrue( ( new Connection() )->is_connected() );
	}

	public function test_a_refresh_in_flight_elsewhere_is_not_repeated(): void {
		$this->connect( 10 );
		$this->options['profotograaf_refresh_lock'] = $this->now - 5;

		$result = $this->api->list_galleries();

		$this->assertWPError( $result, 'profotograaf_refresh_busy' );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_a_stale_lock_is_taken_over(): void {
		$this->connect( 10 );
		$this->options['profotograaf_refresh_lock'] = $this->now - 600;
		$this->refresh_reply();
		$this->http->reply( 200, array() );

		$this->assertSame( array(), $this->api->list_galleries() );
	}

	public function test_a_transport_error_becomes_a_retryable_wp_error(): void {
		$this->connect();
		$this->http->fail( new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$result = $this->api->list_galleries();

		$this->assertWPError( $result, 'profotograaf_network' );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertStringNotContainsString( 'cURL', $result->get_error_message() );
	}

	public function test_an_exception_in_the_transport_never_escapes(): void {
		$this->connect();
		$this->http->fail( new \RuntimeException( 'boom' ) );

		$this->assertWPError( $this->api->list_galleries(), 'profotograaf_network' );
	}

	public function test_post_lead_sends_only_known_fields_and_forces_the_source(): void {
		$this->connect();
		$this->http->reply( 201, array( 'id' => 'lead-1', 'duplicate' => false ) );

		$result = $this->api->post_lead(
			array(
				'name'         => 'Sam',
				'email'        => 'sam@example.com',
				'message'      => 'Hello',
				'phone'        => '',
				'source'       => 'somewhere-else',
				'source_form'  => 'Contact Form 7: Wedding',
				'extra_fields' => array( array( 'label' => 'Guests', 'value' => '80' ) ),
				'is_admin'     => true,
			)
		);

		$this->assertSame( array( 'id' => 'lead-1', 'duplicate' => false ), $result );
		$this->assertSame( 'POST', $this->http->requests[0]['method'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/leads', $this->http->requests[0]['url'] );
		$this->assertSame( 'application/json', $this->http->requests[0]['headers']['Content-Type'] );
		$body = $this->http->body( 0 );
		$this->assertSame( 'wordpress', $body['source'] );
		$this->assertArrayNotHasKey( 'phone', $body );
		$this->assertArrayNotHasKey( 'is_admin', $body );
		$this->assertSame( 'Guests', $body['extra_fields'][0]['label'] );
	}

	public function test_post_lead_treats_a_duplicate_as_success(): void {
		$this->connect();
		$this->http->reply( 200, array( 'id' => 'lead-1', 'duplicate' => true ) );

		$result = $this->api->post_lead( array( 'name' => 'Sam', 'email' => 'sam@example.com' ) );

		$this->assertSame( array( 'id' => 'lead-1', 'duplicate' => true ), $result );
	}

	public function test_post_lead_requires_name_and_email_before_sending(): void {
		$this->connect();

		$result = $this->api->post_lead( array( 'name' => 'Sam' ) );

		$this->assertWPError( $result, 'profotograaf_invalid' );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( array(), $this->http->requests );
	}

	/**
	 * @dataProvider lead_failures
	 */
	public function test_post_lead_reports_whether_a_retry_can_help( int $status, bool $retryable ): void {
		$this->connect();
		$this->http->reply( $status, array( 'error' => 'x' ), array( 'retry-after' => '7' ) );

		$result = $this->api->post_lead( array( 'name' => 'Sam', 'email' => 'sam@example.com' ) );

		$this->assertWPError( $result, 'profotograaf_http' );
		$this->assertSame( $status, $result->get_error_data()['status'] );
		$this->assertSame( $retryable, $result->get_error_data()['retryable'] );
		$this->assertSame( 7, $result->get_error_data()['retry_after'] );
	}

	/**
	 * @return array<string,array{int,bool}>
	 */
	public static function lead_failures(): array {
		return array(
			'invalid input'       => array( 400, false ),
			'not allowed'         => array( 403, false ),
			'too large'           => array( 413, false ),
			'no portfolio yet'    => array( 404, false ),
			'rate limited'        => array( 429, true ),
			'server error'        => array( 500, true ),
			'service unavailable' => array( 503, true ),
		);
	}

	public function test_mark_embeddable_puts_the_flag_and_returns_availability(): void {
		$this->connect();
		$this->http->reply(
			200,
			array(
				'id'         => 'g1',
				'embeddable' => true,
				'available'  => false,
			)
		);

		$result = $this->api->mark_embeddable( 'g1' );

		$this->assertSame(
			array(
				'id'         => 'g1',
				'embeddable' => true,
				'available'  => false,
			),
			$result
		);
		$this->assertSame( 'PUT', $this->http->requests[0]['method'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries/g1/embeddable', $this->http->requests[0]['url'] );
		$this->assertSame( array( 'embeddable' => true ), $this->http->body( 0 ) );
	}

	public function test_mark_embeddable_403_asks_the_photographer_to_reconnect(): void {
		$this->connect();
		$this->http->reply( 403, array( 'error' => 'this app is not allowed to use this endpoint' ) );

		$result = $this->api->mark_embeddable( 'g1' );

		$this->assertWPError( $result, 'profotograaf_reconnect' );
		$this->assertStringContainsString( 'Connect this site again', $result->get_error_message() );
		$this->assertTrue( ( new Connection() )->needs_reconnect() );
	}

	public function test_mark_embeddable_other_failures_stay_http_errors(): void {
		$this->connect();
		$this->http->reply( 404, array( 'error' => 'gallery not found' ) );

		$this->assertWPError( $this->api->mark_embeddable( 'g1' ), 'profotograaf_http' );
	}

	public function test_mark_embeddable_request_can_be_overridden_by_a_filter(): void {
		$this->connect();
		Filters\expectApplied( 'profotograaf_mark_embeddable_request' )->once()->andReturn(
			array(
				'method' => 'PUT',
				'path'   => '/api/v1/galleries/g1',
				'body'   => array( 'embeddable' => true ),
			)
		);
		$this->http->reply( 200, array( 'embeddable' => true ) );

		$result = $this->api->mark_embeddable( 'g1' );

		$this->assertTrue( $result['embeddable'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/galleries/g1', $this->http->requests[0]['url'] );
	}

	public function test_sign_out_reports_whether_the_platform_confirmed(): void {
		$this->connect();
		$this->http->reply( 403, array( 'error' => 'this app is not allowed to use this endpoint' ) );

		$this->assertFalse( $this->api->sign_out() );

		$this->http->reply( 204 );
		$this->assertTrue( $this->api->sign_out() );
	}

	public function test_the_platform_url_can_be_overridden_by_a_filter(): void {
		$this->connect();
		Filters\expectApplied( 'profotograaf_platform_url' )->andReturn( 'http://mock-platform:8090/' );
		$this->http->reply( 200, array() );

		$this->api->list_galleries();

		$this->assertSame( 'http://mock-platform:8090/api/v1/embed/galleries', $this->http->requests[0]['url'] );
	}

	/**
	 * Asserts a WP_Error with a code.
	 *
	 * @param mixed  $value Value.
	 * @param string $code  Expected code.
	 */
	private function assertWPError( $value, string $code ): void {
		$this->assertInstanceOf( \WP_Error::class, $value );
		$this->assertSame( $code, $value->get_error_code() );
	}
}
