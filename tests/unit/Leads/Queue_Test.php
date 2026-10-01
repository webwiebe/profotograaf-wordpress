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

	private const SEVEN_DAYS = 7 * 86400;

	public function test_a_failed_job_is_kept_for_the_retention_and_then_removed_with_a_warning(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD + array( 'source_form' => 'CF7: Wedding' ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );
		$this->mails = array();

		$this->now += self::SEVEN_DAYS - 1;
		$this->assertSame( 0, $this->queue->prune() );
		$this->assertCount( 1, $this->store->jobs );

		$this->now += 1;
		$this->assertSame( 1, $this->queue->prune() );
		$this->assertSame( array(), $this->store->jobs );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'CF7: Wedding', $this->mails[0][2] );
		$this->assertStringNotContainsString( 'anna@example.com', $this->mails[0][2] );
	}

	/**
	 * Retention days stored in the settings and whether a job failed 'days' ago is removed.
	 *
	 * @return array<string,array{0:mixed,1:int,2:int}> Stored value, days of age, jobs removed.
	 */
	public static function retention_cases(): array {
		return array(
			'one day, just reached'           => array( 1, 1, 1 ),
			'one day, one second short'       => array( 1, 0, 0 ),
			'ten days, kept at nine'          => array( 10, 9, 0 ),
			'ten days, removed at ten'        => array( 10, 10, 1 ),
			'ninety days, kept at 89'         => array( 90, 89, 0 ),
			'a stored year counts as 90 days' => array( '365', 90, 1 ),
			'a stored zero counts as one day' => array( 0, 1, 1 ),
			'junk falls back to seven days'   => array( 'soon', 6, 0 ),
			'junk, seven days reached'        => array( 'soon', 7, 1 ),
		);
	}

	/**
	 * @dataProvider retention_cases
	 *
	 * @param mixed $stored  Stored setting.
	 * @param int   $days    Days since the job failed.
	 * @param int   $removed Jobs the prune removes.
	 */
	public function test_the_retention_follows_the_setting( $stored, int $days, int $removed ): void {
		$this->options['profotograaf_settings']['leads_failed_retention'] = $stored;
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );

		$this->now += 0 === $days ? 86399 : $days * 86400;

		$this->assertSame( $removed, $this->queue->prune() );
	}

	public function test_the_retention_defaults_to_seven_days(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );

		$this->now += 7 * 86400 - 1;
		$this->assertSame( 0, $this->queue->prune() );
		$this->now += 1;
		$this->assertSame( 1, $this->queue->prune() );
	}

	public function test_a_job_without_a_sent_alert_is_never_pruned(): void {
		$this->mail_works = false;
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );

		$this->now += 10 * self::SEVEN_DAYS;

		$this->assertSame( 0, $this->queue->prune() );
		$this->assertCount( 1, $this->store->jobs );
	}

	public function test_nothing_is_removed_when_the_expiry_warning_cannot_be_mailed(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );
		$this->mail_works = false;

		$this->now += self::SEVEN_DAYS;

		$this->assertSame( 0, $this->queue->prune() );
		$this->assertCount( 1, $this->store->jobs );

		$this->mail_works = true;
		$this->assertSame( 1, $this->queue->prune() );
	}

	public function test_an_exported_job_is_removed_without_a_warning(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );
		$this->queue->mark_exported( array( 'a' ) );
		$this->mails = array();

		$this->now += self::SEVEN_DAYS;

		$this->assertSame( 1, $this->queue->prune() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_failed_job_is_alerted_once(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD + array( 'source_form' => 'CF7: Wedding' ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'admin@example.com', $this->mails[0][0] );
		$this->assertStringContainsString( 'CF7: Wedding', $this->mails[0][2] );
		$this->assertStringNotContainsString( 'Anna', $this->mails[0][2] );
		$this->assertStringNotContainsString( 'anna@example.com', $this->mails[0][2] );

		$this->assertSame( 0, $this->queue->alert_pending() );
		$this->assertCount( 1, $this->mails );
	}

	public function test_running_out_of_attempts_alerts_too(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		for ( $i = 0; $i < Queue::MAX_ATTEMPTS; $i++ ) {
			$this->queue->retry_later( $this->store->jobs['a'], 'down', 503 );
		}

		$this->assertCount( 1, $this->mails );
	}

	public function test_a_retry_that_is_not_final_sends_no_alert(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->retry_later( $this->store->jobs['a'], 'down', 503 );

		$this->assertSame( array(), $this->mails );
	}

	public function test_an_alert_that_failed_is_sent_again_later(): void {
		$this->mail_works = false;
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );
		$this->assertArrayNotHasKey( 'alerted_at', $this->store->jobs['a'] );

		$this->mail_works = true;
		$this->assertSame( 1, $this->queue->alert_pending() );
		$this->assertArrayHasKey( 'alerted_at', $this->store->jobs['a'] );
		$this->assertSame( 0, $this->queue->alert_pending() );
	}

	public function test_a_dropped_lead_alerts_the_admin_once_an_hour(): void {
		for ( $i = 0; $i < Queue::MAX_PENDING; $i++ ) {
			$this->store->jobs[ 'j' . $i ] = array( 'status' => 'pending' );
		}

		$this->assertSame( 'full', $this->queue->enqueue( 'late', self::PAYLOAD + array( 'source_form' => 'CF7: Wedding' ) ) );
		$this->assertSame( 'full', $this->queue->enqueue( 'later', self::PAYLOAD ) );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'CF7: Wedding', $this->mails[0][2] );
		$this->assertStringNotContainsString( 'anna@example.com', $this->mails[0][2] );

		$this->transients = array();
		$this->queue->enqueue( 'latest', self::PAYLOAD );
		$this->assertCount( 2, $this->mails );
	}

	public function test_the_fallback_mails_a_failed_lead_once(): void {
		$this->options['profotograaf_settings']['leads_fallback_email'] = 'studio@example.com';
		$this->queue->enqueue( 'a', self::PAYLOAD + array( 'extra_fields' => array( array( 'label' => 'Venue', 'value' => 'Barn' ) ) ) );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );

		$this->assertCount( 2, $this->mails );
		$this->assertSame( 'studio@example.com', $this->mails[1][0] );
		$this->assertStringContainsString( 'anna@example.com', $this->mails[1][2] );
		$this->assertStringContainsString( 'Venue: Barn', $this->mails[1][2] );

		$this->queue->alert_pending();
		$this->assertCount( 2, $this->mails );
	}

	public function test_the_fallback_mails_a_dropped_lead(): void {
		$this->options['profotograaf_settings']['leads_fallback_email'] = 'studio@example.com';
		for ( $i = 0; $i < Queue::MAX_PENDING; $i++ ) {
			$this->store->jobs[ 'j' . $i ] = array( 'status' => 'pending' );
		}

		$this->queue->enqueue( 'late', self::PAYLOAD );
		$this->queue->enqueue( 'later', self::PAYLOAD );

		$to = array_column( $this->mails, 0 );
		$this->assertSame( array( 'studio@example.com', 'admin@example.com', 'studio@example.com' ), $to );
	}

	public function test_alerts_go_to_the_configured_address(): void {
		$this->options['profotograaf_settings']['leads_alert_email'] = 'owner@example.com';
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );

		$this->assertSame( 'owner@example.com', $this->mails[0][0] );
	}

	public function test_no_recipient_means_no_alert_and_the_job_is_kept(): void {
		unset( $this->options['admin_email'] );
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );

		$this->assertSame( array(), $this->mails );
		$this->assertArrayNotHasKey( 'alerted_at', $this->store->jobs['a'] );
	}

	public function test_retrying_a_failed_job_clears_its_alert_so_a_new_failure_alerts_again(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );
		$this->queue->retry_failed();
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );

		$this->assertCount( 2, $this->mails );
	}

	public function test_failed_jobs_can_be_exported_and_the_exported_ones_dismissed(): void {
		$this->queue->enqueue( 'a', self::PAYLOAD );
		$this->queue->enqueue( 'b', self::PAYLOAD );
		$this->queue->fail( $this->store->jobs['a'], 'bad input', 400 );
		$this->queue->fail( $this->store->jobs['b'], 'bad input', 400 );

		$this->assertSame( array( 'a', 'b' ), array_keys( $this->queue->failed_jobs() ) );

		$this->queue->mark_exported( array( 'a', 'missing' ) );
		$this->assertSame( 1, $this->queue->dismiss_exported() );
		$this->assertSame( array( 'b' ), array_keys( $this->store->jobs ) );
	}

	public function test_a_queue_without_alerts_never_prunes_unexported_jobs(): void {
		$queue = new Queue( $this->store, fn() => $this->now );
		$queue->enqueue( 'a', self::PAYLOAD );
		$queue->fail( $this->store->jobs['a'], 'bad input', 400 );

		$this->now += 10 * self::SEVEN_DAYS;

		$this->assertSame( 0, $queue->prune() );
		$this->assertSame( 0, $queue->alert_pending() );
		$this->assertSame( array(), $this->mails );
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
