<?php
/**
 * Telemetry payload and delivery tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Modules\Telemetry_Consent;
use Profotograaf\Plugin;
use Profotograaf\Settings;
use Profotograaf\Telemetry_Delivery;
use Profotograaf\Telemetry_Payload;
use Profotograaf\Telemetry_Sender;

class Telemetry_Sender_Test extends Wp_Test_Case {

	private Settings $settings;

	private Connection $connection;

	private Plugin $plugin;

	/**
	 * Requests passed to wp_remote_post.
	 *
	 * @var array<int,array{url:string,args:array<string,mixed>}>
	 */
	private array $posts = array();

	/**
	 * Response code the endpoint answers with, or a WP_Error.
	 *
	 * @var mixed
	 */
	private $answer = 202;

	/**
	 * Response headers the endpoint answers with.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();

	/**
	 * Scheduled single events.
	 *
	 * @var array<int,array{int,string}>
	 */
	private array $scheduled = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_bloginfo' )->justReturn( '6.9.3' );
		Functions\when( 'wp_unslash' )->alias( fn( $value ) => $value );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'get_locale' )->justReturn( 'nl_NL' );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $time, $hook ) {
				$this->scheduled[] = array( $time, $hook );
				return true;
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->answer;
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_remote_retrieve_header' )->alias( fn( $response, $name ) => $this->headers[ $name ] ?? '' );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $response ) => is_int( $response ) ? $response : 0 );

		$this->connection = new Connection();
		$this->plugin     = new Plugin( $this->connection, new Api_Client( $this->connection, new Fake_Transport(), $this->clock() ), $this->clock() );
		$this->settings   = $this->plugin->settings();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_DNT'], $_SERVER['HTTP_SEC_GPC'] );
		parent::tearDown();
	}

	private function opt_in(): Telemetry_Sender {
		$this->options[ Settings::OPTION ] = array( 'telemetry_enabled' => true );
		return $this->sender();
	}

	private function sender(): Telemetry_Sender {
		return new Telemetry_Sender( $this->settings, $this->connection );
	}

	public function test_the_payload_contains_only_allow_listed_fields(): void {
		$payload = Telemetry_Payload::build(
			array(
				'install_id'        => 'abc-123',
				'plugin_version'    => '0.1.0',
				'wordpress_version' => '6.9.3',
				'php_version'       => '8.3.12',
				'locale'            => 'en_US',
				'active_modules'    => array( 'gallery_embed' ),
				'refresh_failed'    => 2,
				'site_url'          => 'https://photos.example.com',
				'admin_email'       => 'owner@example.com',
				'access_token'      => 'secret',
				'lead'              => array( 'email' => 'client@example.com' ),
			)
		);

		$this->assertSame( array(), array_diff( array_keys( $payload ), Telemetry_Payload::FIELDS ) );
		$this->assertSame( Telemetry_Payload::FIELDS, array_keys( $payload ) );
		$this->assertSame( '6.9', $payload['wordpress_version'] );
		$this->assertSame( '8.3', $payload['php_version'] );
		$this->assertSame( 2, $payload['refresh_failed'] );
		$this->assertSame( 0, $payload['delivery_success'] );
		$this->assertStringNotContainsString( 'example.com', (string) json_encode( $payload ) );
		$this->assertStringNotContainsString( 'secret', (string) json_encode( $payload ) );
	}

	public function test_error_events_are_allow_listed_and_capped(): void {
		$events = array(
			'junk' => 'x',
			array(
				'error_code'        => 'Api Timeout',
				'http_status'       => 999,
				'error_location'    => 'includes/x.php:9 jane@example.com',
				'plugin_version'    => '0.1.0',
				'wordpress_version' => '6.9.1',
				'php_version'       => '8.3.1',
				'timestamp'         => '2024-10-01T09:30:00Z',
				'email'             => 'jane@example.com',
			),
			array(
				'error_code'     => '',
				'error_location' => 5,
			),
			array(
				'error_code' => 'b',
				'timestamp'  => 'yesterday',
			),
		);
		$errors = Telemetry_Payload::build( array( 'errors' => $events ) )['errors'];

		$this->assertCount( 2, $errors );
		$this->assertSame( Telemetry_Payload::EVENT_FIELDS, array_keys( $errors[0] ) );
		$this->assertSame( 'apitimeout', $errors[0]['error_code'] );
		$this->assertSame( 599, $errors[0]['http_status'] );
		$this->assertSame( 'includes/x.php:9janeexample.com', $errors[0]['error_location'] );
		$this->assertSame( '6.9', $errors[0]['wordpress_version'] );
		$this->assertSame( 'unknown', $errors[1]['error_location'] );
		$this->assertSame( '', $errors[1]['timestamp'] );
		$this->assertStringNotContainsString( '@', (string) json_encode( $errors ) );
	}

	public function test_each_error_is_one_bugbarn_event_and_needs_consent(): void {
		$this->assertFalse( $this->sender()->add_error( array( 'error_code' => 'a' ) ) );

		$sender = $this->opt_in();
		$this->assertTrue(
			$sender->add_error(
				array(
					'error_code'     => 'a_code',
					'http_status'    => 500,
					'error_location' => 'includes/x.php:9',
					'plugin_version' => '0.1.0',
					'timestamp'      => '2024-10-01T09:30:00Z',
				)
			)
		);
		$sender->add_error( array( 'error_code' => 'b_code' ) );

		$this->assertSame( 3, $sender->run() );

		$this->assertCount( 3, $this->posts );
		$errors = array_values( array_filter( $this->posts, fn( $post ) => str_starts_with( $post['url'], 'https://bb.profotograaf.nl' ) ) );
		$this->assertCount( 2, $errors );
		$this->assertSame( 'https://bb.profotograaf.nl/api/v1/events', $errors[0]['url'] );
		$headers = $errors[0]['args']['headers'];
		$this->assertSame( Telemetry_Delivery::BUGBARN_KEY, $headers['X-BugBarn-Api-Key'] );
		$this->assertSame( 'profotograaf-wordpress', $headers['X-BugBarn-Project'] );
		$this->assertArrayNotHasKey( 'Authorization', $headers );
		$this->assertArrayNotHasKey( 'x-api-key', $headers );

		$body = json_decode( (string) $errors[0]['args']['body'], true );
		$this->assertSame( 'a_code', $body['body'] );
		$this->assertSame( 'error', $body['severityText'] );
		$this->assertSame( '2024-10-01T09:30:00Z', $body['timestamp'] );
		$this->assertSame( 'a_code', $body['exception']['type'] );
		$this->assertSame(
			array(
				array(
					'function' => 'unknown',
					'filename' => 'includes/x.php',
					'lineno'   => 9,
				),
			),
			$body['exception']['stacktrace']
		);
		$this->assertSame( '11111111-2222-4333-8444-555555555555', $body['attributes']['install_id'] );
		$this->assertSame( 500, $body['attributes']['http_status'] );
		$this->assertSame( '0.1.0', $body['attributes']['plugin_version'] );
		$second = json_decode( (string) $errors[1]['args']['body'], true );
		$this->assertSame( array(), $second['exception']['stacktrace'] );
		$this->assertArrayNotHasKey( 'timestamp', $second );
		$this->assertSame( array(), $sender->pending_errors() );
		$this->assertSame( array(), $sender->queue() );
	}

	public function test_free_text_in_module_and_error_fields_is_stripped(): void {
		$payload = Telemetry_Payload::build(
			array(
				'active_modules' => array( 'Lead Forms', 'https://x.test/a b', 7 ),
				'error_codes'    => array(
					'api timeout'  => 2,
					'bad@mail.com' => 1,
					'zero'         => 0,
				),
			)
		);

		$this->assertSame( array( 'httpsx.testab', 'leadforms' ), $payload['active_modules'] );
		$this->assertSame( array( 'apitimeout' => 2, 'badmail.com' => 1 ), $payload['error_codes'] );
	}

	public function test_the_site_payload_has_the_install_id_versions_and_modules(): void {
		$sender  = $this->opt_in();
		$payload = $sender->payload();

		$this->assertSame( '11111111-2222-4333-8444-555555555555', $payload['install_id'] );
		$this->assertSame( '0.0.0-test', $payload['plugin_version'] );
		$this->assertSame( '6.9', $payload['wordpress_version'] );
		$this->assertSame( 'nl_NL', $payload['locale'] );
		$this->assertContains( 'telemetry_consent', $payload['active_modules'] );
		$this->assertSame( Telemetry_Payload::FIELDS, array_keys( $payload ) );
	}

	public function test_outcomes_are_counted_only_after_opt_in(): void {
		$sender = $this->sender();
		$sender->on_lead_delivered();
		$this->assertArrayNotHasKey( Telemetry_Sender::COUNTER_OPTION, $this->options );

		$sender = $this->opt_in();
		$sender->on_lead_delivered();
		$sender->on_lead_failed( array( 'email' => 'a@b.test' ), new \WP_Error( 'lead_delivery_timeout', 'Slow for a@b.test' ) );
		$sender->on_refresh_failed( new \WP_Error( 'invalid_token', 'x' ) );

		$payload = $sender->payload();
		$this->assertSame( 1, $payload['delivery_success'] );
		$this->assertSame( 1, $payload['delivery_failed'] );
		$this->assertSame( 1, $payload['refresh_failed'] );
		$this->assertSame(
			array(
				'invalid_token'         => 1,
				'lead_delivery_timeout' => 1,
			),
			$payload['error_codes']
		);
	}

	public function test_nothing_is_posted_without_consent(): void {
		$sender = $this->sender();
		$sender->enqueue( array( 'type' => 'usage' ) );

		$this->assertSame( 0, $sender->run() );
		$this->assertSame( 0, $sender->send() );
		$this->assertSame( array(), $this->posts );
		$this->assertArrayNotHasKey( Telemetry_Sender::QUEUE_OPTION, $this->options );
	}

	public function test_the_daily_run_posts_a_funnelbarn_usage_event(): void {
		$sender = $this->opt_in();
		$sender->on_lead_delivered();
		$sender->on_lead_failed( null, new \WP_Error( 'lead_delivery_timeout', 'x' ) );

		$this->assertSame( 1, $sender->run() );

		$this->assertCount( 1, $this->posts );
		$this->assertSame( 'https://f.profotograaf.nl/api/v1/events', $this->posts[0]['url'] );
		$headers = $this->posts[0]['args']['headers'];
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( Telemetry_Delivery::FUNNELBARN_KEY, $headers['X-FunnelBarn-Api-Key'] );
		$this->assertSame( 'profotograaf-wordpress', $headers['X-FunnelBarn-Project'] );
		$this->assertArrayNotHasKey( 'Authorization', $headers );
		$this->assertArrayNotHasKey( 'x-api-key', $headers );

		$body = json_decode( (string) $this->posts[0]['args']['body'], true );
		$this->assertSame( 'daily_usage', $body['name'] );
		$this->assertSame( '11111111-2222-4333-8444-555555555555', $body['session_id'] );
		$this->assertSame( 'production', $body['environment'] );
		$this->assertSame( 1, $body['properties']['delivery_success'] );
		$this->assertSame( 1, $body['properties']['delivery_failed'] );
		$this->assertSame( 1, $body['properties']['error_code_lead_delivery_timeout'] );
		$this->assertSame( '6.9', $body['properties']['wordpress_version'] );
		$this->assertSame( 'nl_NL', $body['properties']['locale'] );
		$this->assertIsString( $body['properties']['active_modules'] );
		$this->assertStringContainsString( 'telemetry_consent', $body['properties']['active_modules'] );
		$this->assertArrayNotHasKey( 'type', $body );
		$this->assertArrayNotHasKey( 'errors', $body['properties'] );
		$this->assertSame( array(), $sender->queue() );
		$this->assertArrayNotHasKey( Telemetry_Sender::COUNTER_OPTION, $this->options );
	}

	public function test_the_endpoints_come_from_filters(): void {
		Filters\expectApplied( 'profotograaf_telemetry_endpoint' )->andReturn( 'https://errors.example.org/' );
		Filters\expectApplied( 'profotograaf_telemetry_usage_endpoint' )->andReturn( 'https://usage.example.org' );
		$sender = $this->opt_in();
		$sender->add_error( array( 'error_code' => 'a_code' ) );

		$sender->run();

		$this->assertSame(
			array( 'https://usage.example.org/api/v1/events', 'https://errors.example.org/api/v1/events' ),
			array_column( $this->posts, 'url' )
		);
	}

	public function test_an_empty_or_invalid_endpoint_sends_nothing(): void {
		Filters\expectApplied( 'profotograaf_telemetry_endpoint' )->andReturn( 'javascript:alert(1)' );
		Filters\expectApplied( 'profotograaf_telemetry_usage_endpoint' )->andReturn( '' );
		$sender = $this->opt_in();

		$this->assertSame( '', $sender->endpoint() );
		$this->assertSame( '', $sender->usage_endpoint() );
		$this->assertSame( 0, $sender->run() );
		$this->assertSame( array(), $this->posts );
	}

	public function test_a_disabled_usage_endpoint_still_sends_errors(): void {
		Filters\expectApplied( 'profotograaf_telemetry_usage_endpoint' )->andReturn( '' );
		$sender = $this->opt_in();
		$sender->add_error( array( 'error_code' => 'a_code' ) );

		$sender->run();

		$this->assertSame( array( 'https://bb.profotograaf.nl/api/v1/events' ), array_column( $this->posts, 'url' ) );
	}

	public function test_a_429_pauses_sending_until_retry_after_and_keeps_the_queue(): void {
		$this->answer  = 429;
		$this->headers = array( 'retry-after' => '120' );
		$sender        = $this->opt_in();
		$sender->enqueue( array( 'type' => 'usage' ) );

		$this->assertSame( 0, $sender->send() );
		$this->assertSame( 0, $sender->send() );
		$this->assertSame( 0, $sender->send() );
		$this->assertSame( 0, $sender->send() );

		$this->assertCount( 1, $this->posts );
		$this->assertCount( 1, $sender->queue() );
		$this->assertArrayNotHasKey( Telemetry_Sender::ATTEMPT_OPTION, $this->options );
		$this->assertEqualsWithDelta( 120, $this->options[ Telemetry_Delivery::PAUSE_OPTION ] - time(), 2 );
		$this->assertEqualsWithDelta( 120, $this->scheduled[0][0] - time(), 2 );
		$this->assertSame( Telemetry_Sender::RETRY_HOOK, $this->scheduled[0][1] );
	}

	public function test_a_503_without_retry_after_pauses_for_five_minutes_and_resumes_afterwards(): void {
		$this->answer = 503;
		$sender       = $this->opt_in();
		$sender->enqueue( array( 'type' => 'usage' ) );

		$sender->send();
		$this->assertEqualsWithDelta( 300, $this->options[ Telemetry_Delivery::PAUSE_OPTION ] - time(), 2 );

		$this->options[ Telemetry_Delivery::PAUSE_OPTION ] = time() - 1;
		$this->answer                                      = 202;
		$this->assertSame( 1, $sender->send() );
		$this->assertCount( 2, $this->posts );
		$this->assertSame( array(), $sender->queue() );
	}

	public function test_retry_after_is_capped_at_one_day(): void {
		$this->answer  = 429;
		$this->headers = array( 'retry-after' => '9999999' );
		$sender        = $this->opt_in();
		$sender->enqueue( array( 'type' => 'usage' ) );

		$sender->send();

		$this->assertEqualsWithDelta( 86400, $this->options[ Telemetry_Delivery::PAUSE_OPTION ] - time(), 2 );
	}

	public function test_do_not_track_and_global_privacy_control_keep_the_queue_unsent(): void {
		foreach ( array( 'HTTP_DNT', 'HTTP_SEC_GPC' ) as $header ) {
			$_SERVER[ $header ] = '1';
			$sender             = $this->opt_in();
			$sender->enqueue( array( 'type' => 'usage' ) );

			$this->assertSame( 0, $sender->send(), $header );
			$this->assertSame( array(), $this->posts );
			$this->assertCount( 1, $sender->queue() );
			unset( $_SERVER[ $header ] );
			$this->options = array();
		}
	}

	public function test_a_failed_batch_stays_queued_and_retries_with_backoff_then_is_dropped(): void {
		$this->answer = 500;
		$sender       = $this->opt_in();
		$sender->enqueue( array( 'type' => 'usage' ) );

		$this->assertSame( 0, $sender->send() );
		$this->assertSame( 0, $sender->send() );
		$this->assertCount( 1, $sender->queue() );
		$this->assertSame( array( Telemetry_Sender::RETRY_HOOK, Telemetry_Sender::RETRY_HOOK ), array_column( $this->scheduled, 1 ) );
		$this->assertEqualsWithDelta( 5, $this->scheduled[0][0] - time(), 2 );
		$this->assertEqualsWithDelta( 10, $this->scheduled[1][0] - time(), 2 );

		$this->assertSame( 0, $sender->send() );
		$this->assertSame( array(), $sender->queue() );
		$this->assertCount( 3, $this->posts );
	}

	public function test_a_transport_error_counts_as_a_failure(): void {
		$this->answer = new \WP_Error( 'http_request_failed', 'down' );
		$sender       = $this->opt_in();
		$sender->enqueue( array( 'type' => 'usage' ) );

		$this->assertSame( 0, $sender->send() );
		$this->assertCount( 1, $sender->queue() );
	}

	public function test_a_disconnect_rotates_the_install_id_and_clears_what_waits(): void {
		$this->options[ Connection::INSTALL_ID ] = 'old-id';
		$sender                                  = $this->opt_in();
		$sender->on_lead_delivered();
		$sender->enqueue( array( 'type' => 'usage' ) );
		$sender->add_error( array( 'error_code' => 'a_code' ) );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'new-id' );

		$consent = new Telemetry_Consent();
		$consent->register( $this->plugin );
		$consent->on_disconnected();

		$this->assertSame( 'new-id', $this->options[ Connection::INSTALL_ID ] );
		$this->assertSame( array(), $sender->queue() );
		$this->assertArrayNotHasKey( Telemetry_Sender::COUNTER_OPTION, $this->options );
		$this->assertArrayNotHasKey( Telemetry_Sender::ERRORS_OPTION, $this->options );
	}

	public function test_the_daily_event_is_scheduled_only_while_opted_in(): void {
		$events = array();
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook ) use ( &$events ) {
				return $events[ $hook ] ?? false;
			}
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $time, $recurrence, $hook ) use ( &$events ) {
				$events[ $hook ] = $time;
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( &$events ) {
				unset( $events[ $hook ] );
				return 1;
			}
		);
		$consent = new Telemetry_Consent();
		$consent->register( $this->plugin );

		$consent->ensure_scheduled();
		$this->assertSame( array(), $events );

		$this->opt_in();
		$consent->ensure_scheduled();
		$this->assertArrayHasKey( Telemetry_Sender::BATCH_HOOK, $events );

		$this->options = array();
		$consent->ensure_scheduled();
		$this->assertSame( array(), $events );
	}
}
