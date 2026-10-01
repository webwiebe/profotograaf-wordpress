<?php
/**
 * WP-CLI command tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Cli\Leads_Command;
use Profotograaf\Cli\Registrar;
use Profotograaf\Cli\Status_Command;
use Profotograaf\Connection;
use Profotograaf\Cron_Health;
use Profotograaf\Leads\Delivery;
use Profotograaf\Plugin;
use Profotograaf\Tests\Leads\Leads_Test_Case;

require_once dirname( __DIR__ ) . '/wp-cli-stubs.php';
require_once dirname( __DIR__ ) . '/wp-cli-utils-stubs.php';

class Cron_Commands_Test extends Leads_Test_Case {

	private const PAYLOAD = array(
		'name'        => 'Anna',
		'email'       => 'anna@example.com',
		'source_form' => 'Contact Form 7: Wedding',
	);

	private Fake_Transport $http;

	private Delivery $delivery;

	private Leads_Command $leads;

	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset();
		Functions\when( '_n' )->alias( fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		$this->connect( 100000 );
		$this->http     = new Fake_Transport();
		$api            = new Api_Client( new Connection(), $this->http, $this->clock() );
		$this->delivery = new Delivery( $this->queue, $api );
		$this->leads    = new Leads_Command( $this->delivery, $this->queue, $this->store );
	}

	/**
	 * Lines printed at one level.
	 *
	 * @param string $level Level.
	 * @return string[]
	 */
	private function printed( string $level ): array {
		$lines = array();
		foreach ( \WP_CLI::$output as $row ) {
			if ( $level === $row[0] ) {
				$lines[] = $row[1];
			}
		}
		return $lines;
	}

	public function test_flush_delivers_every_due_lead_and_prints_a_summary(): void {
		for ( $i = 0; $i < 12; ++$i ) {
			$this->queue->enqueue( 'job-' . $i, self::PAYLOAD );
			$this->http->reply( 201, array( 'id' => 'lead-' . $i ) );
		}

		$this->leads->flush();

		$this->assertSame( array(), $this->store->jobs );
		$this->assertCount( 12, $this->http->requests );
		$this->assertSame( array( 'Delivered 12, retrying 0, failed 0. 0 pending.' ), $this->printed( 'success' ) );
	}

	public function test_flush_reports_leads_that_are_retried_and_leads_that_fail(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->enqueue( 'b', self::PAYLOAD );
		$this->http->reply( 503 )->reply( 422, array( 'error' => 'bad' ) );

		$this->leads->flush();

		$this->assertSame( array( 'Delivered 0, retrying 1, failed 1. 1 pending.' ), $this->printed( 'success' ) );
		$this->assertSame( 'failed', $this->store->jobs['b']['status'] );
	}

	public function test_flush_with_an_empty_queue_says_so(): void {
		$this->leads->flush();

		$this->assertSame( array( 'Delivered 0, retrying 0, failed 0. 0 pending.' ), $this->printed( 'success' ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_flush_warns_and_stops_when_delivery_is_locked(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->store->locked = true;

		$this->leads->flush();

		$this->assertCount( 1, $this->printed( 'warning' ) );
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( array( 'Delivered 0, retrying 0, failed 0. 1 pending.' ), $this->printed( 'success' ) );
	}

	public function test_flush_can_requeue_failed_leads_first(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'nope', 422 );
		$this->http->reply( 201, array( 'id' => 'lead-1' ) );

		$this->leads->flush( array(), array( 'retry-failed' => true ) );

		$this->assertSame( array( 'Requeued 1 failed lead.' ), $this->printed( 'log' ) );
		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_list_prints_the_jobs_without_personal_data(): void {
		$this->queue->enqueue( 'abcdef123456', self::PAYLOAD );
		$this->queue->enqueue( 'failed-job-1', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['failed-job-1'], 'rejected', 422 );

		$this->leads->list_( array(), array( 'status' => 'pending' ) );

		$this->assertCount( 1, $this->printed( 'items' ) );
		list( $format, $rows, $fields ) = json_decode( $this->printed( 'items' )[0], true );
		$this->assertSame( 'table', $format );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'abcdef12', $rows[0]['id'] );
		$this->assertSame( 'Contact Form 7: Wedding', $rows[0]['form'] );
		$this->assertContains( 'last_error', $fields );
		$this->assertStringNotContainsString( 'anna@example.com', $this->printed( 'items' )[0] );

		\WP_CLI::reset();
		$this->leads->list_( array(), array( 'format' => 'json' ) );
		$this->assertCount( 2, json_decode( $this->printed( 'items' )[0], true )[1] );
	}

	public function test_status_prints_the_state_and_warns_about_a_dead_cron(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$command = new Status_Command( new Connection(), $this->queue, new Cron_Health( $this->queue, fn() => true ) );

		$command();

		list( , $rows ) = json_decode( $this->printed( 'items' )[0], true );
		$by_check       = array_column( $rows, 'value', 'check' );
		$this->assertSame( 'yes', $by_check['connected'] );
		$this->assertSame( '1', $by_check['leads_pending'] );
		$this->assertSame( 'yes', $by_check['wp_cron_disabled'] );
		$this->assertSame( 'never', $by_check['last_delivery_run'] );
		$this->assertSame( 'no', $by_check['cron_healthy'] );
		$this->assertCount( 1, $this->printed( 'warning' ) );
	}

	public function test_status_is_quiet_when_cron_runs(): void {
		$cron = new Cron_Health( $this->queue, fn() => false );
		$cron->beat();
		( new Status_Command( new Connection(), $this->queue, $cron ) )();

		$this->assertSame( array(), $this->printed( 'warning' ) );
		list( , $rows ) = json_decode( $this->printed( 'items' )[0], true );
		$this->assertSame( 'yes', array_column( $rows, 'value', 'check' )['cron_healthy'] );
	}

	public function test_the_registrar_adds_the_commands(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}
		$plugin = new Plugin( new Connection(), new Api_Client( new Connection(), $this->http, $this->clock() ), $this->clock() );

		Registrar::register( $plugin, $this->queue, $this->store, $this->delivery, new Cron_Health( $this->queue ) );

		$this->assertSame( array( 'profotograaf', 'profotograaf status', 'profotograaf leads' ), array_keys( \WP_CLI::$commands ) );
		$this->assertInstanceOf( Leads_Command::class, \WP_CLI::$commands['profotograaf leads'] );
		$this->assertInstanceOf( Status_Command::class, \WP_CLI::$commands['profotograaf status'] );
	}
}
