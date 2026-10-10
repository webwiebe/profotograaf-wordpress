<?php
/**
 * Platform status call tests, with a faked HTTP layer.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Logger;
use Profotograaf\Modules\Platform_Status_Sync;
use Profotograaf\Platform_Status;

class Platform_Status_Test extends Gallery_Test_Case {

	private Platform_Status $status;

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		$this->connect();
		Functions\when( 'get_bloginfo' )->alias( fn( ...$args ) => array( 'version' ) === $args ? '6.8.1' : 'Example Photography' );
		$this->status = new Platform_Status( $this->api, $this->clock() );
	}

	protected function tearDown(): void {
		Logger::configure( null );
		parent::tearDown();
	}

	/**
	 * A status answer as the platform sends it.
	 *
	 * @return array<string,mixed>
	 */
	private function answer(): array {
		return array(
			'device_id'       => 'device-1',
			'client_id'       => 'wordpress',
			'site_url'        => 'https://photos.example.com',
			'review_prompt'   => array(
				'eligible' => true,
				'reason'   => 'first_client_download',
				'at'       => '2026-10-09T10:00:00Z',
			),
			'error_reporting' => array(
				'endpoint'    => 'https://bb.profotograaf.nl',
				'project'     => 'wordpress-plugin',
				'key'         => 'ingest-key',
				'environment' => 'production',
			),
		);
	}

	/**
	 * Stubs wp_schedule_single_event and collects the hooks queued.
	 *
	 * @return \ArrayObject<int,string>
	 */
	private function capture_single_events(): \ArrayObject {
		$queued = new \ArrayObject();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $time, $hook ) use ( $queued ) {
				$queued[] = $hook;
				return true;
			}
		);
		return $queued;
	}

	/**
	 * Levels of the log entries written so far.
	 *
	 * @return string[]
	 */
	private function logged_levels(): array {
		return array_column( Logger::entries(), 'level' );
	}

	public function test_the_call_sends_the_plugin_stats_with_the_access_token(): void {
		$this->http->reply( 200, $this->answer() );

		$this->assertTrue( $this->status->refresh() );

		$request = $this->http->requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/auth/devices/status', $request['url'] );
		$this->assertSame( 'Bearer access-1', $request['headers']['Authorization'] );
		$this->assertSame(
			array(
				'plugin_version' => '0.0.0-test',
				'wp_version'     => '6.8.1',
				'php_version'    => PHP_VERSION,
				'site_url'       => 'https://photos.example.com',
			),
			$this->http->body( 0 )
		);
	}

	public function test_a_review_event_is_added_to_the_body(): void {
		$this->http->reply( 200, $this->answer() );

		$this->status->refresh( 'shown' );

		$this->assertSame( 'shown', $this->http->body( 0 )['review_event'] );
	}

	public function test_an_unknown_review_event_is_left_out(): void {
		$this->http->reply( 200, $this->answer() );

		$this->status->refresh( 'bogus' );

		$this->assertArrayNotHasKey( 'review_event', $this->http->body( 0 ) );
	}

	public function test_the_response_is_stored_without_autoload(): void {
		$this->http->reply( 200, $this->answer() );

		$this->status->refresh();

		$stored = $this->status->stored();
		$this->assertTrue( $stored['review_prompt']['eligible'] );
		$this->assertSame( 'first_client_download', $stored['review_prompt']['reason'] );
		$this->assertSame( '2026-10-09T10:00:00Z', $stored['review_prompt']['at'] );
		$this->assertSame( 'https://bb.profotograaf.nl', $stored['error_reporting']['endpoint'] );
		$this->assertSame( 'wordpress-plugin', $stored['error_reporting']['project'] );
		$this->assertSame( 'ingest-key', $stored['error_reporting']['key'] );
		$this->assertSame( 'production', $stored['error_reporting']['environment'] );
		$this->assertSame( $this->now, $stored['fetched_at'] );
		$this->assertFalse( $this->autoload[ Platform_Status::OPTION ] );
	}

	public function test_a_response_without_error_reporting_stores_none(): void {
		$answer = $this->answer();
		unset( $answer['error_reporting'] );
		$this->http->reply( 200, $answer );

		$this->status->refresh();

		$this->assertNull( $this->status->stored()['error_reporting'] );
	}

	public function test_a_404_keeps_the_last_stored_value_and_logs_a_warning(): void {
		$this->http->reply( 200, $this->answer() );
		$this->status->refresh();
		$before = $this->status->stored();
		Logger::clear();

		$this->now += 100;
		$this->http->reply( 404, array( 'error' => 'not a wordpress device' ) );
		$result = $this->status->refresh();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$after = $this->status->stored();
		$this->assertSame( $before['review_prompt'], $after['review_prompt'] );
		$this->assertSame( $before['error_reporting'], $after['error_reporting'] );
		$this->assertSame( $before['fetched_at'], $after['fetched_at'] );
		$this->assertContains( 'warning', $this->logged_levels() );
	}

	public function test_a_network_failure_keeps_the_last_stored_value_and_logs_a_warning(): void {
		$this->http->reply( 200, $this->answer() );
		$this->status->refresh();
		$before = $this->status->stored();
		Logger::clear();

		$this->http->fail( new \WP_Error( 'http_request_failed', 'timeout' ) );
		$result = $this->status->refresh();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$after = $this->status->stored();
		$this->assertSame( $before['review_prompt'], $after['review_prompt'] );
		$this->assertSame( $before['error_reporting'], $after['error_reporting'] );
		$this->assertContains( 'warning', $this->logged_levels() );
	}

	public function test_nothing_is_sent_while_disconnected(): void {
		unset( $this->options['profotograaf_connection'] );

		$result = $this->status->refresh();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_a_malformed_block_is_stored_as_not_eligible_and_no_reporting(): void {
		$this->http->reply(
			200,
			array(
				'review_prompt'   => 'yes',
				'error_reporting' => array( 'endpoint' => 'ftp://x' ),
			)
		);

		$this->status->refresh();

		$stored = $this->status->stored();
		$this->assertFalse( $stored['review_prompt']['eligible'] );
		$this->assertNull( $stored['error_reporting'] );
	}

	public function test_stored_is_empty_before_the_first_call(): void {
		$stored = $this->status->stored();

		$this->assertSame( 0, $stored['fetched_at'] );
		$this->assertFalse( $stored['review_prompt']['eligible'] );
		$this->assertNull( $stored['error_reporting'] );
	}

	public function test_the_daily_event_runs_the_call(): void {
		$module = new Platform_Status_Sync();
		$module->register( $this->plugin );
		$this->http->reply( 200, $this->answer() );

		$module->run();

		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'first_client_download', $this->status->stored()['review_prompt']['reason'] );
	}

	public function test_the_daily_event_does_nothing_while_disconnected(): void {
		unset( $this->options['profotograaf_connection'] );
		$module = new Platform_Status_Sync();
		$module->register( $this->plugin );

		$module->run();

		$this->assertSame( array(), $this->http->requests );
	}

	public function test_the_schedule_follows_the_connection(): void {
		$scheduled = array();
		$cleared   = array();
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $time, $recurrence, $hook ) use ( &$scheduled ) {
				$scheduled[] = array( $recurrence, $hook );
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
				return 1;
			}
		);
		$module = new Platform_Status_Sync();
		$module->register( $this->plugin );

		$module->ensure_scheduled();
		$this->assertSame( array( array( 'daily', Platform_Status_Sync::HOOK ) ), $scheduled );

		$this->http->reply( 200, $this->answer() );
		$module->run();
		$this->assertArrayHasKey( Platform_Status::OPTION, $this->options );

		$module->on_disconnected();
		$this->assertContains( Platform_Status_Sync::HOOK, $cleared );
		$this->assertContains( Platform_Status_Sync::SOON_HOOK, $cleared );
		$this->assertArrayNotHasKey( Platform_Status::OPTION, $this->options );
	}

	public function test_opening_the_settings_page_queues_a_background_call_without_waiting(): void {
		$queued = $this->capture_single_events();
		$module = new Platform_Status_Sync();
		$module->register( $this->plugin );

		$module->on_settings_page();

		$this->assertSame( array( Platform_Status_Sync::SOON_HOOK ), $queued->getArrayCopy() );
		$this->assertSame( array(), $this->http->requests, 'the page render must not call the platform' );
	}

	public function test_opening_the_settings_page_again_within_an_hour_queues_nothing(): void {
		$queued = $this->capture_single_events();
		$module = new Platform_Status_Sync();
		$module->register( $this->plugin );

		$module->on_settings_page();
		$this->now += HOUR_IN_SECONDS - 1;
		$module->on_settings_page();
		$this->assertCount( 1, $queued );

		$this->now += 2;
		$module->on_settings_page();
		$this->assertCount( 2, $queued );
	}

	public function test_opening_the_settings_page_while_disconnected_queues_nothing(): void {
		unset( $this->options['profotograaf_connection'] );
		$queued = $this->capture_single_events();
		$module = new Platform_Status_Sync();
		$module->register( $this->plugin );

		$module->on_settings_page();

		$this->assertCount( 0, $queued );
	}
}
