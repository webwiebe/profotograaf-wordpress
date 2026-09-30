<?php
/**
 * Delivers queued leads from WP-Cron.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

use Profotograaf\Api_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Sends due leads with Api_Client::post_lead(), one at a time.
 *
 * A single event (`profotograaf_deliver_leads`) is scheduled when a lead is
 * queued and again for the next retry, and an hourly sweep
 * (`profotograaf_leads_sweep`) catches anything a missed event left behind.
 * A lock keeps two runs from sending the same lead. The platform answering
 * `duplicate` counts as delivered, so a retry after a lost response is
 * harmless.
 */
final class Delivery {

	public const HOOK  = 'profotograaf_deliver_leads';
	public const SWEEP = 'profotograaf_leads_sweep';

	private const BATCH    = 10;
	private const LOCK_TTL = 300;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Constructor.
	 *
	 * @param Queue      $queue Queue.
	 * @param Api_Client $api   API client.
	 */
	public function __construct( Queue $queue, Api_Client $api ) {
		$this->queue = $queue;
		$this->api   = $api;
	}

	/**
	 * Adds the cron callbacks and the hourly sweep.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::SWEEP, array( $this, 'run' ) );
		if ( ! wp_next_scheduled( self::SWEEP ) ) {
			wp_schedule_event( $this->queue->now() + 300, 'hourly', self::SWEEP );
		}
	}

	/**
	 * Schedules a delivery run and asks WordPress to start cron after this
	 * request, without waiting for it.
	 *
	 * @param int $when Unix time of the run.
	 */
	public static function schedule( int $when ): void {
		wp_schedule_single_event( $when, self::HOOK );
		if ( function_exists( 'spawn_cron' ) ) {
			add_action( 'shutdown', 'spawn_cron' );
		}
	}

	/**
	 * Sends the due leads.
	 *
	 * @return array{sent:int,retry:int,failed:int} What this run did.
	 */
	public function run(): array {
		$result = array(
			'sent'   => 0,
			'retry'  => 0,
			'failed' => 0,
		);
		if ( ! $this->queue->lock( self::LOCK_TTL ) ) {
			return $result;
		}

		try {
			$this->queue->prune();
			foreach ( $this->queue->due( self::BATCH ) as $job ) {
				++$result[ $this->deliver( $job ) ];
			}
		} finally {
			$this->queue->unlock();
		}

		$next = $this->queue->next_due_at();
		if ( null !== $next ) {
			self::schedule( max( $next, $this->queue->now() + 30 ) );
		}
		return $result;
	}

	/**
	 * Sends one job.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return string `sent`, `retry` or `failed`.
	 */
	private function deliver( array $job ): string {
		$response = $this->api->post_lead( (array) $job['payload'] );
		if ( ! is_wp_error( $response ) ) {
			$this->queue->complete( (string) $job['id'] );
			/**
			 * Fires after the platform accepted a lead.
			 *
			 * @param array<string,mixed> $payload  The lead as sent.
			 * @param array<string,mixed> $response `id` and `duplicate`.
			 */
			do_action( 'profotograaf_lead_delivered', (array) $job['payload'], $response );
			return 'sent';
		}

		$data      = $response->get_error_data();
		$data      = is_array( $data ) ? $data : array();
		$status    = (int) ( $data['status'] ?? 0 );
		$message   = $response->get_error_message();
		$temporary = ! empty( $data['retryable'] ) || in_array( $response->get_error_code(), array( 'profotograaf_not_connected', 'profotograaf_refresh_busy' ), true );

		if ( $temporary && 'retry' === $this->queue->retry_later( $job, $message, $status, (int) ( $data['retry_after'] ?? 0 ) ) ) {
			return 'retry';
		}
		if ( ! $temporary ) {
			$this->queue->fail( $job, $message, $status );
		}
		do_action( 'profotograaf_lead_failed', (array) $job['payload'], $response );
		return 'failed';
	}
}
