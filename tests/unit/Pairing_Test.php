<?php
/**
 * Connect flow tests, with a faked HTTP layer.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Pairing;

class Pairing_Test extends Wp_Test_Case {

	private Fake_Transport $http;

	private Connection $connection;

	private Pairing $pairing;

	protected function setUp(): void {
		parent::setUp();
		$this->http       = new Fake_Transport();
		$this->connection = new Connection();
		$this->pairing    = new Pairing( $this->connection, new Api_Client( $this->connection, $this->http, $this->clock() ), $this->clock() );
	}

	private function start(): void {
		$this->http->reply(
			201,
			array(
				'device_code'               => 'secret-device-code',
				'user_code'                 => 'ABCD-EFGH',
				'verification_uri'          => 'https://profotograaf.nl/app/devices/approve',
				'verification_uri_complete' => 'https://profotograaf.nl/app/devices/approve?code=ABCD-EFGH',
				'expires_in'                => 600,
				'interval'                  => 3,
			)
		);
		$result = $this->pairing->start();
		$this->assertIsArray( $result );
	}

	public function test_start_identifies_the_site_and_keeps_the_pairing(): void {
		$this->http->reply(
			201,
			array(
				'device_code'               => 'secret-device-code',
				'user_code'                 => 'ABCD-EFGH',
				'verification_uri'          => 'https://profotograaf.nl/app/devices/approve',
				'verification_uri_complete' => 'https://profotograaf.nl/app/devices/approve?code=ABCD-EFGH',
				'expires_in'                => 600,
				'interval'                  => 3,
			)
		);

		$result = $this->pairing->start();

		$this->assertSame( 'ABCD-EFGH', $result['user_code'] );
		$this->assertSame( 'https://profotograaf.nl/app/devices/approve?code=ABCD-EFGH', $result['verification_uri'] );
		$this->assertSame( 3, $result['interval'] );
		$this->assertSame( $this->now + 600, $result['expires_at'] );

		$request = $this->http->requests[0];
		$this->assertSame( 'https://profotograaf.nl/api/v1/auth/devices/initiate', $request['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
		$this->assertSame(
			array(
				'client_id'   => 'wordpress',
				'device_id'   => '11111111-2222-4333-8444-555555555555',
				'name'        => 'Example Photography',
				'platform'    => 'wordpress',
				'app_version' => '0.0.0-test',
				'hostname'    => 'photos.example.com',
			),
			$this->http->body( 0 )
		);
		$this->assertSame( 'secret-device-code', $this->connection->pairing()['device_code'] );
		$this->assertFalse( $this->autoload['profotograaf_pairing'], 'the pairing must not autoload' );
	}

	public function test_start_reports_a_platform_failure(): void {
		$this->http->reply( 429, array( 'error' => 'slow down' ) );

		$result = $this->pairing->start();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_pairing_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertNull( $this->connection->pairing() );
	}

	public function test_start_survives_an_unreachable_platform(): void {
		$this->http->fail( new \WP_Error( 'http_request_failed', 'timed out' ) );

		$result = $this->pairing->start();

		$this->assertSame( 'profotograaf_network', $result->get_error_code() );
	}

	public function test_poll_without_a_pairing_says_none(): void {
		$this->assertSame( 'none', $this->pairing->poll()['status'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_poll_stays_pending_and_repeats_the_server_interval(): void {
		$this->start();
		$this->http->reply( 200, array( 'status' => 'pending' ) );

		$result = $this->pairing->poll();

		$this->assertSame( array( 'status' => 'pending', 'interval' => 3 ), $result );
		$this->assertSame( 'https://profotograaf.nl/api/v1/auth/devices/token', $this->http->requests[1]['url'] );
		$this->assertSame( array( 'device_code' => 'secret-device-code' ), $this->http->body( 1 ) );
		$this->assertFalse( $this->connection->is_connected() );
	}

	public function test_poll_backs_off_when_the_server_says_slow_down(): void {
		$this->start();
		$this->http->reply( 400, array( 'error' => 'polling too fast', 'code' => 'device.slow_down' ) );

		$result = $this->pairing->poll();

		$this->assertSame( array( 'status' => 'pending', 'interval' => 8 ), $result );
		$this->assertSame( 8, $this->connection->pairing()['interval'] );
	}

	public function test_approval_stores_the_tokens_and_announces_the_connection(): void {
		$this->start();
		Actions\expectDone( 'profotograaf_connected' )->once();
		$this->http->reply(
			200,
			array(
				'status'        => 'approved',
				'access_token'  => 'access-1',
				'refresh_token' => 'refresh-1',
				'token_type'    => 'Bearer',
				'expires_in'    => 900,
				'device_id'     => 'device-9',
			)
		);

		$result = $this->pairing->poll();

		$this->assertSame( 'approved', $result['status'] );
		$this->assertTrue( $this->connection->is_connected() );
		$this->assertSame( 'access-1', $this->connection->access_token() );
		$this->assertSame( 'refresh-1', $this->connection->refresh_token() );
		$this->assertSame( $this->now + 900, $this->connection->access_expires_at() );
		$this->assertNull( $this->connection->pairing() );
		$this->assertFalse( $this->autoload['profotograaf_connection'], 'tokens must not autoload' );
		$this->assertSame( 'connected', $this->connection->status()['state'] );
	}

	public function test_approval_without_tokens_is_an_error_not_a_connection(): void {
		$this->start();
		$this->http->reply( 200, array( 'status' => 'approved' ) );

		$this->assertInstanceOf( \WP_Error::class, $this->pairing->poll() );
		$this->assertFalse( $this->connection->is_connected() );
	}

	public function test_denial_ends_the_pairing(): void {
		$this->start();
		$this->http->reply( 200, array( 'status' => 'denied' ) );

		$this->assertSame( 'denied', $this->pairing->poll()['status'] );
		$this->assertNull( $this->connection->pairing() );
	}

	public function test_an_expired_code_ends_the_pairing_without_asking(): void {
		$this->start();
		$this->now += 601;

		$this->assertSame( 'expired', $this->pairing->poll()['status'] );
		$this->assertCount( 1, $this->http->requests, 'only the initiate call was made' );
		$this->assertNull( $this->connection->pairing() );
	}

	public function test_an_unknown_code_counts_as_expired(): void {
		$this->start();
		$this->http->reply( 404, array( 'error' => 'unknown device code', 'code' => 'device.unknown_code' ) );

		$this->assertSame( 'expired', $this->pairing->poll()['status'] );
	}

	public function test_a_second_tab_polling_after_the_first_collected_the_tokens_sees_approved(): void {
		$this->start();
		$this->connect();
		$this->http->reply( 409, array( 'error' => 'device code already used' ) );

		$this->assertSame( 'approved', $this->pairing->poll()['status'] );
	}

	public function test_a_failed_poll_is_an_error_the_page_can_retry(): void {
		$this->start();
		$this->http->reply( 502 );

		$result = $this->pairing->poll();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertNotNull( $this->connection->pairing(), 'the pairing survives a failed poll' );
	}

	public function test_disconnect_asks_the_platform_then_clears_everything(): void {
		$this->connect();
		$this->connection->save_pairing( array( 'device_code' => 'x' ) );
		Actions\expectDone( 'profotograaf_disconnected' )->once()->with( 'user' );
		$this->http->reply( 204 );

		$this->assertTrue( $this->pairing->disconnect() );

		$this->assertSame( 'https://profotograaf.nl/api/v1/auth/devices/signout', $this->http->requests[0]['url'] );
		$this->assertSame( 'Bearer access-1', $this->http->requests[0]['headers']['Authorization'] );
		$this->assertFalse( $this->connection->is_connected() );
		$this->assertNull( $this->connection->pairing() );
		$this->assertArrayNotHasKey( 'profotograaf_connection', $this->options );
		$this->assertSame( 'disconnected', $this->connection->status()['state'] );
	}

	public function test_disconnect_clears_locally_even_when_the_platform_refuses(): void {
		$this->connect();
		$this->http->reply( 403, array( 'error' => 'this app is not allowed to use this endpoint' ) );

		$this->assertFalse( $this->pairing->disconnect() );
		$this->assertFalse( $this->connection->is_connected() );
	}

	public function test_disconnect_clears_locally_when_the_platform_is_unreachable(): void {
		$this->connect();
		$this->http->fail( new \WP_Error( 'http_request_failed', 'timed out' ) );

		$this->assertFalse( $this->pairing->disconnect() );
		$this->assertFalse( $this->connection->is_connected() );
	}
}
