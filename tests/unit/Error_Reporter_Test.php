<?php
/**
 * Error reporter tests: consent, scrubbing, rate limit and deduplication.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Error_Reporter;
use Profotograaf\Logger;
use Profotograaf\Modules\Error_Reporting;
use Profotograaf\Plugin;
use Profotograaf\Settings;
use Profotograaf\Telemetry_Sender;

class Located_Error_Reporter extends Error_Reporter {

	public int $time = 1000000;

	protected function now(): int {
		return $this->time;
	}

	protected function location(): string {
		return 'includes/leads/class-delivery.php:154';
	}
}

class Error_Reporter_Test extends Wp_Test_Case {

	private Settings $settings;

	private Telemetry_Sender $sender;

	private Located_Error_Reporter $reporter;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_bloginfo' )->justReturn( '6.9.1' );
		$this->settings = new Settings();
		$this->sender   = new Telemetry_Sender( $this->settings );
		$this->reporter = new Located_Error_Reporter( $this->sender );
	}

	private function opt_in(): void {
		$this->options[ Settings::OPTION ] = array( 'telemetry_enabled' => true );
	}

	/**
	 * Events queued so far.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function events(): array {
		$events = array();
		foreach ( $this->sender->queue() as $batch ) {
			foreach ( $batch['errors'] as $event ) {
				$events[] = $event;
			}
		}
		return $events;
	}

	private function error( string $code = 'profotograaf_http_error', int $status = 502 ): \WP_Error {
		return new \WP_Error( $code, 'Failed for jane@example.com at https://photos.example.com', array( 'status' => $status ) );
	}

	/**
	 * Fires every consumer once.
	 */
	private function fire_all(): void {
		$this->reporter->on_refresh_failed( $this->error( 'refresh_a', 401 ) );
		$this->reporter->on_lead_failed( array( 'email' => 'jane@example.com' ), $this->error( 'lead_b', 500 ) );
		$this->reporter->on_lead_delivered( array( 'email' => 'jane@example.com' ), array( 'id' => '1' ) );
		$this->reporter->on_lead_skipped( new \stdClass() );
		$this->reporter->on_lead_not_queued( 'full', new \stdClass() );
		$this->reporter->on_disconnected( 'revoked' );
	}

	public function test_nothing_is_queued_without_opt_in(): void {
		$this->fire_all();
		$this->reporter->on_log( Logger::ERROR, 'x', array( 'error' => 'e' ) );

		$this->assertSame( array(), $this->sender->queue() );
		$this->assertArrayNotHasKey( Error_Reporter::STATE_OPTION, $this->options );
	}

	public function test_a_host_filter_that_turns_telemetry_off_stops_reporting(): void {
		$this->opt_in();
		Filters\expectApplied( 'profotograaf_telemetry_enabled' )->andReturn( false );
		$this->reporter->on_lead_failed( array(), $this->error() );

		$this->assertSame( array(), $this->sender->queue() );
	}

	public function test_each_hook_produces_one_event_after_opt_in(): void {
		$this->opt_in();
		$this->fire_all();

		$this->assertSame(
			array( 'refresh_a', 'lead_b', 'lead_delivered', 'lead_skipped', 'lead_not_queued_full', 'disconnected_revoked' ),
			array_column( $this->events(), 'error_code' )
		);
	}

	public function test_an_event_holds_only_the_documented_fields(): void {
		$this->opt_in();
		$this->reporter->on_lead_failed( array( 'email' => 'jane@example.com' ), $this->error() );

		$this->assertSame(
			array(
				'error_code'        => 'profotograaf_http_error',
				'http_status'       => 502,
				'error_location'    => 'includes/leads/class-delivery.php:154',
				'plugin_version'    => '0.0.0-test',
				'wordpress_version' => '6.9',
				'php_version'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
				'timestamp'         => '1970-01-12T13:46:40Z',
			),
			$this->events()[0]
		);
		$this->assertSame( 'error', $this->sender->queue()[0]['type'] );
	}

	public function test_no_token_email_or_url_reaches_the_queue(): void {
		$this->opt_in();
		$this->reporter->on_refresh_failed( new \WP_Error( 'bad jane@example.com https://x.example.com Bearer abc123', 'm', array( 'status' => 401 ) ) );
		$this->reporter->on_log( Logger::ERROR, 'm', array( 'error' => 'pft_9f8a7b6c5d4e3f2a1b0c9d8e7f' ) );

		$json = (string) wp_json_encode( $this->sender->queue() );
		$this->assertStringNotContainsString( '@', $json );
		$this->assertStringNotContainsString( 'example.com', $json );
		$this->assertStringNotContainsString( 'pft_9f8a', $json );
		$this->assertStringNotContainsString( 'abc123', $json );
	}

	public function test_a_repeated_fingerprint_is_sent_once_per_day(): void {
		$this->opt_in();
		$this->reporter->on_lead_failed( array(), $this->error() );
		$this->reporter->on_lead_failed( array(), $this->error() );
		$this->assertCount( 1, $this->events() );

		$this->reporter->on_lead_failed( array(), $this->error( 'profotograaf_http_error', 503 ) );
		$this->assertCount( 2, $this->events(), 'another status is another fingerprint' );

		$this->reporter->time += Error_Reporter::WINDOW + 1;
		$this->reporter->on_lead_failed( array(), $this->error() );
		$this->assertCount( 3, $this->events(), 'a new window allows it again' );
	}

	public function test_new_fingerprints_are_capped_per_hour(): void {
		$this->opt_in();
		for ( $i = 0; $i < Error_Reporter::MAX_PER_HOUR + 5; $i++ ) {
			$this->reporter->on_lead_failed( array(), $this->error( 'code_' . $i ) );
		}
		$this->assertCount( Error_Reporter::MAX_PER_HOUR, $this->events() );

		$this->reporter->time += HOUR_IN_SECONDS + 1;
		$this->reporter->on_lead_failed( array(), $this->error( 'later' ) );
		$this->assertCount( Error_Reporter::MAX_PER_HOUR + 1, $this->events() );
	}

	public function test_logger_errors_are_reported_and_lower_levels_are_not(): void {
		$this->opt_in();
		$this->reporter->on_log( Logger::WARNING, 'w', array( 'error' => 'warn_code' ) );
		$this->reporter->on_log(
			Logger::ERROR,
			'e',
			array(
				'error'  => 'api_timeout',
				'status' => 504,
			)
		);
		$this->reporter->on_log( Logger::ERROR, 'e', array() );

		$events = $this->events();
		$this->assertCount( 2, $events );
		$this->assertSame( 'api_timeout', $events[0]['error_code'] );
		$this->assertSame( 504, $events[0]['http_status'] );
		$this->assertSame( 'logged_error', $events[1]['error_code'] );
	}

	public function test_a_hook_and_the_log_line_for_one_failure_make_one_event(): void {
		$this->opt_in();
		$this->reporter->on_log(
			Logger::ERROR,
			'A lead delivery failed for good.',
			array(
				'error'  => 'lead_b',
				'status' => 500,
			)
		);
		$this->reporter->on_lead_failed( array(), $this->error( 'lead_b', 500 ) );

		$this->assertCount( 1, $this->events() );
	}

	public function test_a_plugin_path_becomes_a_relative_location(): void {
		$this->assertSame( 'includes/x.php:9', Error_Reporter::relative_location( PROFOTOGRAAF_DIR . 'includes/x.php', 9 ) );
		$this->assertSame( 'unknown', Error_Reporter::relative_location( '/var/www/other/x.php', 9 ) );
	}

	public function test_the_module_subscribes_the_consumers(): void {
		foreach ( array( 'profotograaf_refresh_failed', 'profotograaf_lead_failed', 'profotograaf_lead_delivered', 'profotograaf_lead_skipped', 'profotograaf_lead_not_queued', 'profotograaf_disconnected', 'profotograaf_log' ) as $hook ) {
			Actions\expectAdded( $hook )->once();
		}
		( new Error_Reporting() )->register( new Plugin() );
	}
}
