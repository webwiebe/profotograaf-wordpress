<?php
/**
 * Delivery tests: WP-Cron delivery through the API client.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Leads\Delivery;
use Profotograaf\Leads\Queue;
use Profotograaf\Tests\Fake_Transport;

class Delivery_Test extends Leads_Test_Case {

	private Fake_Transport $http;

	private Delivery $delivery;

	private const PAYLOAD = array(
		'name'        => 'Anna',
		'email'       => 'anna@example.com',
		'message'     => 'Hello',
		'source'      => 'wordpress',
		'source_form' => 'Contact Form 7: Wedding',
	);

	protected function setUp(): void {
		parent::setUp();
		$this->connect( 100000 );
		$this->http     = new Fake_Transport();
		$api            = new Api_Client( new Connection(), $this->http, $this->clock() );
		$this->delivery = new Delivery( $this->queue, $api );
		$this->queue->enqueue( 'a', self::PAYLOAD );
	}

	public function test_a_lead_is_posted_and_removed_from_the_queue(): void {
		$this->http->reply( 201, array( 'id' => 'lead-1', 'duplicate' => false ) );

		$result = $this->delivery->run();

		$this->assertSame( 1, $result['sent'] );
		$this->assertSame( array(), $this->store->jobs );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'POST', $this->http->requests[0]['method'] );
		$this->assertStringEndsWith( '/api/v1/leads', $this->http->requests[0]['url'] );
		$this->assertSame( 'Bearer access-1', $this->http->requests[0]['headers']['Authorization'] );
		$this->assertSame( self::PAYLOAD, $this->http->body( 0 ) );
	}

	public function test_a_duplicate_answer_counts_as_delivered(): void {
		$this->http->reply( 200, array( 'id' => 'lead-1', 'duplicate' => true ) );

		$this->assertSame( 1, $this->delivery->run()['sent'] );
		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_a_server_error_is_retried_with_growing_delays(): void {
		$this->http->reply( 503, array( 'error' => 'down' ) )->reply( 502 )->reply( 201, array( 'id' => 'lead-1' ) );

		$this->assertSame( 1, $this->delivery->run()['retry'] );
		$this->assertSame( $this->now + 60, $this->store->jobs['a']['next_at'] );

		// Nothing is due yet, so nothing is sent.
		$this->assertSame( 0, $this->delivery->run()['retry'] );
		$this->assertCount( 1, $this->http->requests );

		$this->now += 60;
		$this->assertSame( 1, $this->delivery->run()['retry'] );
		$this->assertSame( $this->now + 120, $this->store->jobs['a']['next_at'] );

		$this->now += 120;
		$this->assertSame( 1, $this->delivery->run()['sent'] );
		$this->assertSame( array(), $this->store->jobs );
		$this->assertCount( 3, $this->http->requests );
	}

	public function test_every_retry_sends_the_same_body(): void {
		$this->http->reply( 503 )->reply( 201, array( 'id' => 'lead-1' ) );

		$this->delivery->run();
		$this->now += 60;
		$this->delivery->run();

		$this->assertSame( $this->http->body( 0 ), $this->http->body( 1 ) );
	}

	public function test_a_rate_limit_waits_for_retry_after(): void {
		$this->http->reply( 429, array( 'error' => 'slow down' ), array( 'retry-after' => '600' ) );

		$this->delivery->run();

		$this->assertSame( $this->now + 600, $this->store->jobs['a']['next_at'] );
	}

	public function test_a_network_error_is_retried(): void {
		$this->http->fail( new \RuntimeException( 'timeout' ) );

		$this->assertSame( 1, $this->delivery->run()['retry'] );
		$this->assertSame( 'pending', $this->store->jobs['a']['status'] );
	}

	public function test_a_rejected_lead_is_not_retried(): void {
		$this->http->reply( 400, array( 'error' => 'invalid email' ) );

		$this->assertSame( 1, $this->delivery->run()['failed'] );
		$this->assertSame( 'failed', $this->store->jobs['a']['status'] );
		$this->assertSame( 400, $this->store->jobs['a']['last_status'] );

		$this->now += 100000;
		$this->assertSame( 0, $this->delivery->run()['sent'] );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_a_lead_is_kept_while_the_site_is_not_connected(): void {
		unset( $this->options['profotograaf_connection'] );

		$this->assertSame( 1, $this->delivery->run()['retry'] );
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( 'pending', $this->store->jobs['a']['status'] );
	}

	public function test_a_lead_fails_after_the_last_attempt(): void {
		$this->store->jobs['a']['attempts'] = Queue::MAX_ATTEMPTS - 1;
		$this->http->reply( 503 );

		$this->assertSame( 1, $this->delivery->run()['failed'] );
		$this->assertSame( 'failed', $this->store->jobs['a']['status'] );
	}

	public function test_two_runs_never_overlap(): void {
		$this->store->locked = true;
		$this->http->reply( 201, array( 'id' => 'lead-1' ) );

		$result = $this->delivery->run();

		$this->assertSame( 0, $result['sent'] );
		$this->assertSame( array(), $this->http->requests );
		$this->assertTrue( $this->store->locked, 'The other run keeps its lock.' );
	}

	public function test_the_lock_is_released_after_a_run(): void {
		$this->http->reply( 201, array( 'id' => 'lead-1' ) );

		$this->delivery->run();

		$this->assertFalse( $this->store->locked );
	}

	public function test_the_next_retry_is_scheduled_in_cron(): void {
		$this->http->reply( 503 );

		$this->delivery->run();

		$this->assertContains( array( $this->now + 60, Delivery::HOOK ), $this->scheduled );
	}

	public function test_register_hooks_cron_and_adds_the_hourly_sweep(): void {
		Functions\expect( 'wp_schedule_event' )->once()->with( $this->now + 300, 'hourly', Delivery::SWEEP );

		$this->delivery->register();

		$this->assertNotFalse( has_action( Delivery::HOOK, array( $this->delivery, 'run' ) ) );
		$this->assertNotFalse( has_action( Delivery::SWEEP, array( $this->delivery, 'run' ) ) );
	}

	public function test_delivery_fires_actions_for_other_code(): void {
		\Brain\Monkey\Actions\expectDone( 'profotograaf_lead_delivered' )->once();
		$this->http->reply( 201, array( 'id' => 'lead-1' ) );

		$this->delivery->run();
	}
}
