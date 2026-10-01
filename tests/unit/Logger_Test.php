<?php
/**
 * Logger tests: scrubbing, the ring buffer and the failure paths that log.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Leads\Delivery;
use Profotograaf\Leads\Submission;
use Profotograaf\Logger;
use Profotograaf\Tests\Leads\Leads_Test_Case;
use WP_Error;

class Logger_Test extends Leads_Test_Case {

	private const ACCESS  = 'at_0123456789abcdef0123456789abcdef01234567';
	private const REFRESH = 'rt_fedcba9876543210fedcba9876543210fedcba98';

	private Fake_Transport $http;

	private Api_Client $api;

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		$this->http = new Fake_Transport();
		$this->api  = new Api_Client( new Connection(), $this->http, $this->clock() );
	}

	protected function tearDown(): void {
		Logger::configure( null );
		parent::tearDown();
	}

	/**
	 * Everything stored, as one string.
	 */
	private function dump(): string {
		return (string) json_encode( Logger::entries() );
	}

	private function messages(): string {
		return implode( ' | ', array_column( Logger::entries(), 'message' ) );
	}

	private function assertClean( string $text ): void {
		foreach ( array( self::ACCESS, self::REFRESH, 'anna@example.com', 'Anna', 'Hello there', '0612345678' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}
	}

	private function assertWPError( $value ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
	}

	public function test_an_entry_has_time_level_message_and_context(): void {
		Logger::warning( 'Something odd.', array( 'status' => 502 ) );

		$entries = Logger::entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'warning', $entries[0]['level'] );
		$this->assertSame( 'Something odd.', $entries[0]['message'] );
		$this->assertSame( array( 'status' => 502 ), $entries[0]['context'] );
		$this->assertIsInt( $entries[0]['time'] );
	}

	public function test_each_level_helper_sets_its_level(): void {
		Logger::error( 'a' );
		Logger::warning( 'b' );
		Logger::info( 'c' );
		Logger::debug( 'd' );

		$this->assertSame( array( 'error', 'warning', 'info', 'debug' ), array_column( Logger::entries(), 'level' ) );
	}

	public function test_the_log_action_receives_the_scrubbed_entry(): void {
		Actions\expectDone( 'profotograaf_log' )->once()->with( 'error', 'Failed for [redacted]', array( 'status' => 0 ) );

		Logger::error( 'Failed for anna@example.com', array( 'status' => 0 ) );
	}

	public function test_the_buffer_keeps_the_last_hundred_entries_and_is_not_autoloaded(): void {
		for ( $i = 1; $i <= 130; ++$i ) {
			Logger::info( 'entry ' . $i );
		}

		$entries = Logger::entries();
		$this->assertCount( Logger::CAPACITY, $entries );
		$this->assertSame( 'entry 31', $entries[0]['message'] );
		$this->assertSame( 'entry 130', $entries[99]['message'] );
		$this->assertFalse( $this->autoload[ Logger::OPTION ] );
	}

	public function test_clear_empties_the_buffer(): void {
		Logger::info( 'x' );
		Logger::clear();

		$this->assertSame( array(), Logger::entries() );
	}

	public function test_a_damaged_buffer_reads_as_empty(): void {
		$this->options[ Logger::OPTION ] = 'garbage';

		$this->assertSame( array(), Logger::entries() );
	}

	public function test_lines_go_to_the_debug_log_only_when_enabled(): void {
		$lines = array();
		$sink  = function ( string $line ) use ( &$lines ): void {
			$lines[] = $line;
		};

		Logger::configure( false, $sink );
		Logger::error( 'quiet' );
		$this->assertSame( array(), $lines );

		Logger::configure( true, $sink );
		Logger::error( 'loud', array( 'status' => 500 ) );
		$this->assertSame( array( '[profotograaf] ERROR: loud {"status":500}' ), $lines );
	}

	public function test_the_debug_log_follows_wp_debug_log_by_default(): void {
		Logger::configure( null );

		Logger::error( 'WP_DEBUG_LOG is not defined in the unit tests, so nothing is written' );

		$this->assertCount( 1, Logger::entries() );
	}

	public function test_a_failing_option_store_never_throws(): void {
		$fail = function () {
			throw new \RuntimeException( 'db down' );
		};
		Functions\when( 'update_option' )->alias( $fail );
		Functions\when( 'add_option' )->alias( $fail );

		Logger::error( 'survives' );

		$this->assertSame( array(), Logger::entries() );
	}

	public function test_tokens_emails_and_numbers_are_scrubbed_from_text(): void {
		$text = Logger::scrub_string( 'Bearer ' . self::ACCESS . ' for anna@example.com, call 06 12345678 or +31 6 1234 5678, key ' . self::REFRESH );

		$this->assertClean( $text );
		$this->assertStringNotContainsString( '1234 5678', $text );
		$this->assertStringContainsString( '[redacted]', $text );
	}

	public function test_plain_diagnostics_survive_scrubbing(): void {
		$this->assertSame(
			'GET /api/v1/embed/galleries/abc-123/embeddable answered 503',
			Logger::scrub_string( 'GET /api/v1/embed/galleries/abc-123/embeddable answered 503' )
		);
	}

	public function test_sensitive_keys_and_nested_values_are_replaced(): void {
		$context = Logger::scrub_context(
			array(
				'access_token'  => self::ACCESS,
				'refresh_token' => self::REFRESH,
				'email'         => 'anna@example.com',
				'name'          => 'Anna',
				'phone'         => '0612345678',
				'message'       => 'Hello there',
				'payload'       => array( 'email' => 'anna@example.com' ),
				'extra_fields'  => array( 'a' => 'b' ),
				'nested'        => array( 'x' => 'anna@example.com' ),
				'status'        => 400,
				'retryable'     => false,
				'note'          => 'sent to anna@example.com',
			)
		);

		$this->assertClean( (string) json_encode( $context ) );
		$this->assertSame( 400, $context['status'] );
		$this->assertFalse( $context['retryable'] );
		$this->assertSame( '[redacted]', $context['nested'] );
		$this->assertSame( 'sent to [redacted]', $context['note'] );
	}

	public function test_a_throwable_is_logged_by_class_without_its_trace(): void {
		Logger::exception( 'Caught.', new \RuntimeException( 'failed for anna@example.com' ), array( 'form' => 'cf7:1' ) );

		$entry = Logger::entries()[0];
		$this->assertSame( 'error', $entry['level'] );
		$this->assertSame( 'RuntimeException', $entry['context']['exception'] );
		$this->assertSame( 'failed for [redacted]', $entry['context']['reason'] );
		$this->assertSame( 'cf7:1', $entry['context']['form'] );
	}

	public function test_the_buffer_option_is_removed_by_uninstall(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertStringContainsString( "delete_option( '" . Logger::OPTION . "' )", $source );
		$this->assertStringStartsWith( 'profotograaf_', Logger::OPTION );
	}

	public function test_a_transport_error_is_logged_with_its_reason(): void {
		$this->connect( 900, self::ACCESS, self::REFRESH );
		$this->http->fail( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$this->assertWPError( $this->api->list_galleries() );

		$entry = Logger::entries()[0];
		$this->assertSame( 'error', $entry['level'] );
		$this->assertSame( 'http_request_failed', $entry['context']['error'] );
		$this->assertStringContainsString( 'timed out', $entry['context']['reason'] );
		$this->assertSame( '/api/v1/embed/galleries', $entry['context']['path'] );
		$this->assertClean( $this->dump() );
	}

	public function test_a_transport_exception_is_logged(): void {
		$this->connect( 900, self::ACCESS, self::REFRESH );
		$this->http->fail( new \RuntimeException( 'socket closed' ) );

		$this->assertWPError( $this->api->list_galleries() );

		$this->assertSame( 'RuntimeException', Logger::entries()[0]['context']['exception'] );
	}

	public function test_a_framing_check_failure_is_logged(): void {
		$this->http->fail( new WP_Error( 'http_request_failed', 'no route' ) )->fail( new \RuntimeException( 'boom' ) )->reply( 500 );

		foreach ( array( 1, 2, 3 ) as $unused ) {
			$this->assertWPError( $this->api->framing_allows( 'https://profotograaf.nl/share/g/spring', 'https://photos.example.com' ) );
		}

		$entries = Logger::entries();
		$this->assertGreaterThanOrEqual( 3, count( $entries ) );
		$this->assertSame( 500, $entries[ count( $entries ) - 1 ]['context']['status'] );
	}

	public function test_an_http_error_is_logged_with_status_and_code(): void {
		$this->connect( 900, self::ACCESS, self::REFRESH );
		$this->http->reply(
			422,
			array(
				'error' => 'bad for anna@example.com',
				'code'  => 'lead.invalid',
			)
		);

		$this->api->post_lead(
			array(
				'name'  => 'Anna',
				'email' => 'anna@example.com',
			)
		);

		$entry = Logger::entries()[0];
		$this->assertSame( 422, $entry['context']['status'] );
		$this->assertSame( 'lead.invalid', $entry['context']['code'] );
		$this->assertClean( $this->dump() );
	}

	public function test_refresh_failures_are_logged_without_tokens(): void {
		$this->connect( 10, self::ACCESS, self::REFRESH );
		$this->http->reply( 503, array( 'error' => 'down' ) );
		$this->assertWPError( $this->api->list_galleries() );

		$this->http->reply( 401, array( 'error' => 'gone' ) );
		$this->assertWPError( $this->api->list_galleries() );

		$this->connect( 10, self::ACCESS, self::REFRESH );
		$this->http->fail( new WP_Error( 'http_request_failed', 'down' ) );
		$this->assertWPError( $this->api->list_galleries() );

		$messages = $this->messages();
		$this->assertStringContainsString( 'Token refresh failed.', $messages );
		$this->assertStringContainsString( 'the connection was ended', $messages );
		$this->assertStringContainsString( 'could not be reached', $messages );
		$this->assertClean( $this->dump() );
	}

	public function test_a_busy_lock_is_logged(): void {
		$this->connect( 10, self::ACCESS, self::REFRESH );
		$this->options['profotograaf_refresh_lock'] = $this->now - 5;
		$this->assertWPError( $this->api->list_galleries() );

		$this->assertStringContainsString( 'holds the lock', $this->messages() );
	}

	public function test_a_swallowed_throwable_in_the_dispatcher_is_logged(): void {
		$this->enable( 'cf7:12' );
		$this->store->throw = true;

		$result = $this->dispatcher->submit( new Submission( 'cf7:12', 'Contact', array( array( 'id' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => 'anna@example.com' ) ) ) );

		$this->assertSame( 'error', $result );
		$entry = Logger::entries()[0];
		$this->assertSame( 'error', $entry['level'] );
		$this->assertSame( 'cf7:12', $entry['context']['form'] );
		$this->assertClean( $this->dump() );
	}

	public function test_a_lead_dropped_for_a_full_queue_is_logged(): void {
		$this->enable( 'cf7:12' );
		for ( $i = 0; $i < \Profotograaf\Leads\Queue::MAX_PENDING; ++$i ) {
			$this->queue->enqueue( 'fill-' . $i, array( 'email' => 'x@example.com' ) );
		}

		$result = $this->dispatcher->submit( new Submission( 'cf7:12', 'Contact', array( array( 'id' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => 'anna@example.com' ) ) ) );

		$this->assertSame( 'full', $result );
		$this->assertStringContainsString( 'queue is full', $this->messages() );
	}

	public function test_delivery_failures_are_logged_without_lead_data(): void {
		$this->connect( 100000, self::ACCESS, self::REFRESH );
		$delivery = new Delivery( $this->queue, $this->api );
		$this->queue->enqueue(
			'job-1234567890',
			array(
				'name'    => 'Anna',
				'email'   => 'anna@example.com',
				'phone'   => '0612345678',
				'message' => 'Hello there',
			)
		);
		$this->http->reply( 503, array( 'error' => 'down' ) )->reply( 400, array( 'error' => 'invalid anna@example.com' ) );

		$delivery->run();
		$this->store->jobs['job-1234567890']['next_at'] = 0;
		$delivery->run();

		$levels = array_column( Logger::entries(), 'level' );
		$this->assertContains( 'warning', $levels );
		$this->assertContains( 'error', $levels );
		$messages = $this->messages();
		$this->assertStringContainsString( 'will be retried', $messages );
		$this->assertStringContainsString( 'failed for good', $messages );
		$this->assertClean( $this->dump() );
	}
}
