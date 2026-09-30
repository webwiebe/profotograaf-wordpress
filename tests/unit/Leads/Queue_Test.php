<?php
/**
 * Queue tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Profotograaf\Leads\Queue;

class Queue_Test extends Leads_Test_Case {

	private const PAYLOAD = array(
		'name'  => 'Anna',
		'email' => 'anna@example.com',
	);

	public function test_backoff_doubles_from_a_minute_up_to_six_hours(): void {
		$this->assertSame( 60, Queue::backoff( 1 ) );
		$this->assertSame( 120, Queue::backoff( 2 ) );
		$this->assertSame( 240, Queue::backoff( 3 ) );
		$this->assertSame( 480, Queue::backoff( 4 ) );
		$this->assertSame( 21600, Queue::backoff( 10 ) );
		$this->assertSame( 21600, Queue::backoff( 500 ) );
	}

	public function test_the_same_id_is_queued_once(): void {
		$this->assertSame( 'queued', $this->queue->enqueue( 'a', self::PAYLOAD ) );
		$this->assertSame( 'exists', $this->queue->enqueue( 'a', self::PAYLOAD ) );
		$this->assertCount( 1, $this->store->jobs );
	}

	public function test_a_new_job_is_due_at_once(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );

		$this->assertSame( array( 'a' ), array_keys( $this->queue->due( 10 ) ) );
	}

	public function test_a_full_queue_refuses_new_leads(): void {
		for ( $i = 0; $i < Queue::MAX_PENDING; $i++ ) {
			$this->store->jobs[ 'j' . $i ] = array( 'status' => 'pending' );
		}

		$this->assertSame( 'full', $this->queue->enqueue( 'late', self::PAYLOAD ) );
	}

	public function test_retry_sets_the_next_attempt_with_exponential_delay(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );

		$job = $this->store->jobs['a'];
		$this->assertSame( 'retry', $this->queue->retry_later( $job, 'down', 503 ) );
		$this->assertSame( $this->now + 60, $this->store->jobs['a']['next_at'] );
		$this->assertSame( array(), $this->queue->due( 10 ) );

		$this->now += 60;
		$this->assertSame( 'retry', $this->queue->retry_later( $this->store->jobs['a'], 'down', 503 ) );
		$this->assertSame( $this->now + 120, $this->store->jobs['a']['next_at'] );
		$this->assertSame( 2, $this->store->jobs['a']['attempts'] );
	}

	public function test_retry_after_from_the_platform_extends_the_delay(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );

		$this->queue->retry_later( $this->store->jobs['a'], 'slow down', 429, 600 );

		$this->assertSame( $this->now + 600, $this->store->jobs['a']['next_at'] );
	}

	public function test_a_job_fails_after_ten_attempts(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );

		$result = 'retry';
		for ( $i = 0; $i < Queue::MAX_ATTEMPTS; $i++ ) {
			$result = $this->queue->retry_later( $this->store->jobs['a'], 'down', 503 );
		}

		$this->assertSame( 'failed', $result );
		$this->assertSame( 'failed', $this->store->jobs['a']['status'] );
		$this->assertSame( array(), $this->queue->due( 10 ) );
	}

	public function test_a_job_fails_after_three_days(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->now += Queue::MAX_AGE;

		$this->assertSame( 'failed', $this->queue->retry_later( $this->store->jobs['a'], 'down', 503 ) );
	}

	public function test_failed_jobs_are_counted_listed_and_retried(): void {
		$this->queue->enqueue( 'a', array( 'source_form' => 'Contact Form 7: Wedding' ) + self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );

		$this->assertSame(
			array(
				'pending' => 0,
				'failed'  => 1,
			),
			$this->queue->counts()
		);
		$this->assertSame(
			array(
				array(
					'form'     => 'Contact Form 7: Wedding',
					'error'    => 'bad input',
					'status'   => 400,
					'attempts' => 1,
				),
			),
			$this->queue->failures()
		);

		$this->assertSame( 1, $this->queue->retry_failed() );
		$this->assertSame( 'pending', $this->store->jobs['a']['status'] );
		$this->assertSame( 0, $this->store->jobs['a']['attempts'] );
		$this->assertSame( array( 'a' ), array_keys( $this->queue->due( 10 ) ) );
	}

	public function test_failed_jobs_and_their_personal_data_are_removed_after_a_week(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );

		$this->now += Queue::KEEP_FAILED - 1;
		$this->assertSame( 0, $this->queue->prune() );

		$this->now += 1;
		$this->assertSame( 1, $this->queue->prune() );
		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_next_due_at_is_the_earliest_pending_job(): void {
		$this->assertNull( $this->queue->next_due_at() );

		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->retry_later( $this->store->jobs['a'], 'down', 503 );
		$this->queue->enqueue( 'b', self::PAYLOAD );
		$this->queue->retry_later( $this->store->jobs['b'], 'down', 503, 900 );

		$this->assertSame( $this->now + 60, $this->queue->next_due_at() );
	}

	public function test_due_returns_the_oldest_first_and_honours_the_limit(): void {
		$this->store->jobs = array(
			'late'  => array(
				'status'  => 'pending',
				'next_at' => $this->now - 5,
			),
			'early' => array(
				'status'  => 'pending',
				'next_at' => $this->now - 50,
			),
			'later' => array(
				'status'  => 'pending',
				'next_at' => $this->now + 50,
			),
		);

		$this->assertSame( array( 'early' ), array_keys( $this->queue->due( 1 ) ) );
		$this->assertSame( array( 'early', 'late' ), array_keys( $this->queue->due( 10 ) ) );
	}
}
