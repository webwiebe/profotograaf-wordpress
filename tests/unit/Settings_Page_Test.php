<?php
/**
 * Settings page action tests: capability and nonce checks.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Modules\Settings_Page;
use Profotograaf\Plugin;

/**
 * Settings_Page with the redirect-and-exit replaced by a counter.
 */
class Recording_Settings_Page extends Settings_Page {

	/**
	 * Times the page redirected.
	 *
	 * @var int
	 */
	public int $finished = 0;

	protected function finish(): void {
		++$this->finished;
	}
}

class Settings_Page_Test extends Wp_Test_Case {

	private Fake_Transport $http;

	private Plugin $plugin;

	private Recording_Settings_Page $page;

	protected function setUp(): void {
		parent::setUp();
		$this->http   = new Fake_Transport();
		$connection   = new Connection();
		$this->plugin = new Plugin( $connection, new Api_Client( $connection, $this->http, $this->clock() ), $this->clock() );
		$this->page   = new Recording_Settings_Page();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'plugin_basename' )->justReturn( 'profotograaf/profotograaf.php' );
		$this->page->register( $this->plugin );
	}

	private function allow( bool $capable, bool $nonce_ok = true ): void {
		Functions\when( 'current_user_can' )->justReturn( $capable );
		Functions\when( 'check_admin_referer' )->alias(
			function () use ( $nonce_ok ) {
				if ( ! $nonce_ok ) {
					throw new \RuntimeException( 'bad nonce' );
				}
				return 1;
			}
		);
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
	}

	/**
	 * @dataProvider actions
	 */
	public function test_every_action_refuses_a_user_without_the_capability( string $method ): void {
		$this->allow( false );

		try {
			$this->page->$method();
			$this->fail( 'expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
		$this->assertSame( 0, $this->page->finished );
		$this->assertSame( array(), $this->http->requests );
	}

	/**
	 * @dataProvider actions
	 */
	public function test_every_action_refuses_a_bad_nonce( string $method ): void {
		$this->allow( true, false );

		try {
			$this->page->$method();
			$this->fail( 'expected the nonce check to stop the request' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'bad nonce', $e->getMessage() );
		}
		$this->assertSame( 0, $this->page->finished );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_the_reconnect_notice_shows_for_a_connection_without_the_embed_scope(): void {
		$this->allow( true );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		$this->connect();

		ob_start();
		$this->page->reconnect_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Connect this site again', $html );
	}

	public function test_no_reconnect_notice_after_a_current_pairing(): void {
		$this->allow( true );
		$this->connect();
		$this->options['profotograaf_connection']['scope_revision'] = \Profotograaf\Config::SCOPE_REVISION;

		ob_start();
		$this->page->reconnect_notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function actions(): array {
		return array(
			'connect'      => array( 'handle_connect' ),
			'cancel'       => array( 'handle_cancel' ),
			'disconnect'   => array( 'handle_disconnect' ),
			'sync origins' => array( 'handle_sync_origins' ),
		);
	}

	public function test_connect_starts_a_pairing_and_returns_to_the_page(): void {
		$this->allow( true );
		$this->http->reply(
			201,
			array(
				'device_code'               => 'code',
				'user_code'                 => 'ABCD-EFGH',
				'verification_uri_complete' => 'https://profotograaf.nl/app/devices/approve?code=ABCD-EFGH',
				'expires_in'                => 600,
				'interval'                  => 3,
			)
		);

		$this->page->handle_connect();

		$this->assertSame( 1, $this->page->finished );
		$this->assertSame( 'ABCD-EFGH', $this->plugin->connection()->pairing()['user_code'] );
	}

	public function test_connect_leaves_an_error_notice_when_the_platform_is_down(): void {
		$this->allow( true );
		$this->http->fail( new \WP_Error( 'http_request_failed', 'timed out' ) );

		$this->page->handle_connect();

		$this->assertSame( 'error', $this->transients['profotograaf_notice_7']['type'] );
		$this->assertNull( $this->plugin->connection()->pairing() );
	}

	public function test_disconnect_clears_the_connection(): void {
		$this->allow( true );
		$this->connect();
		$this->http->reply( 403, array( 'error' => 'this app is not allowed to use this endpoint' ) );

		$this->page->handle_disconnect();

		$this->assertFalse( $this->plugin->connection()->is_connected() );
		$this->assertSame( 'success', $this->transients['profotograaf_notice_7']['type'] );
	}

	public function test_the_poll_endpoint_refuses_a_user_without_the_capability(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data, $status ) {
				throw new \RuntimeException( 'json_error ' . $status );
			}
		);

		try {
			$this->page->handle_poll();
			$this->fail( 'expected a 403' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error 403', $e->getMessage() );
		}
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_the_poll_endpoint_reports_the_pairing_status(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) {
				throw new \RuntimeException( json_encode( $data ) );
			}
		);
		$this->plugin->connection()->save_pairing(
			array(
				'device_code' => 'code',
				'user_code'   => 'ABCD-EFGH',
				'expires_at'  => $this->now + 100,
				'interval'    => 3,
			)
		);
		$this->http->reply( 200, array( 'status' => 'pending' ) );

		try {
			$this->page->handle_poll();
			$this->fail( 'expected a JSON answer' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( '{"status":"pending","interval":3}', $e->getMessage() );
		}
	}
}
