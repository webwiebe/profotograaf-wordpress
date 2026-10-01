<?php
/**
 * The lead queue.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Leads waiting for delivery, with retry timing.
 *
 * A job is `id`, `payload` (the leads API body), `status` (`pending` or
 * `failed`), `attempts`, `next_at`, `created_at`, `last_error` and
 * `last_status`. The form submission only calls enqueue(); delivery happens in
 * WP-Cron (see Delivery).
 *
 * Retries back off exponentially: 1 minute after the first failure, then 2, 4,
 * 8 and so on up to 6 hours. A job that keeps failing for 10 attempts or 3
 * days, or that the platform rejects for good (a 4xx other than 408 and 429),
 * becomes `failed`. Lead_Alerts mails the admin once at that moment. A failed
 * job is kept until the photographer exports it and removes it, or until the
 * retention in the settings has passed (30 days by default). Removal after the
 * retention happens only for jobs that were alerted or exported, and sends a
 * summary mail. A lead that does not fit in a full queue is reported to
 * Lead_Alerts as dropped.
 *
 * A job also carries `failed_at`, `alerted_at`, `fallback_at` and
 * `exported_at` once those things happened.
 */
final class Queue {

	public const MAX_ATTEMPTS = 10;
	public const MAX_AGE      = 259200;
	public const KEEP_FAILED  = 2592000;
	public const MAX_PENDING  = 500;
	public const BASE_DELAY   = 60;
	public const MAX_DELAY    = 21600;

	/**
	 * Storage.
	 *
	 * @var Job_Store
	 */
	private Job_Store $store;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Alerts and mail fallback. Without it nothing is mailed, and failed jobs
	 * are never pruned because none of them counts as alerted.
	 *
	 * @var Lead_Alerts|null
	 */
	private ?Lead_Alerts $alerts;

	/**
	 * Constructor.
	 *
	 * @param Job_Store        $store Storage.
	 * @param callable|null    $clock Returns the current unix time, `time` by default.
	 * @param Lead_Alerts|null $alerts Alerts and mail fallback.
	 */
	public function __construct( Job_Store $store, ?callable $clock = null, ?Lead_Alerts $alerts = null ) {
		$this->store  = $store;
		$this->clock  = $clock ?? 'time';
		$this->alerts = $alerts;
	}

	/**
	 * Current unix time.
	 */
	public function now(): int {
		return (int) ( $this->clock )();
	}

	/**
	 * Delay before attempt number $attempts + 1.
	 *
	 * @param int $attempts Attempts made so far, at least 1.
	 */
	public static function backoff( int $attempts ): int {
		$exponent = min( max( $attempts, 1 ) - 1, 20 );
		return (int) min( self::BASE_DELAY * ( 2 ** $exponent ), self::MAX_DELAY );
	}

	/**
	 * Adds a lead to the queue.
	 *
	 * @param string              $id      Job id. The same id is queued once.
	 * @param array<string,mixed> $payload Leads API body.
	 * @return string `queued`, `exists` (this id is already queued) or `full`.
	 */
	public function enqueue( string $id, array $payload ): string {
		if ( $this->counts()['pending'] >= self::MAX_PENDING ) {
			if ( null !== $this->alerts ) {
				$this->alerts->dropped( $payload, $this->now() );
			}
			return 'full';
		}
		$now = $this->now();
		$job = array(
			'id'          => $id,
			'payload'     => $payload,
			'status'      => 'pending',
			'attempts'    => 0,
			'next_at'     => $now,
			'created_at'  => $now,
			'last_error'  => '',
			'last_status' => 0,
		);
		return $this->store->add( $id, $job ) ? 'queued' : 'exists';
	}

	/**
	 * Pending jobs that are due, oldest first.
	 *
	 * @param int $limit Most jobs to return.
	 * @return array<string,array<string,mixed>>
	 */
	public function due( int $limit ): array {
		$now = $this->now();
		$due = array_filter(
			$this->store->all(),
			static fn( array $job ): bool => 'pending' === ( $job['status'] ?? '' ) && (int) ( $job['next_at'] ?? 0 ) <= $now
		);
		uasort( $due, static fn( array $a, array $b ): int => (int) $a['next_at'] <=> (int) $b['next_at'] );
		return array_slice( $due, 0, max( 0, $limit ), true );
	}

	/**
	 * Removes a delivered job.
	 *
	 * @param string $id Job id.
	 */
	public function complete( string $id ): void {
		$this->store->delete( $id );
	}

	/**
	 * Records a failed attempt of a job that can be tried again.
	 *
	 * @param array<string,mixed> $job         The job.
	 * @param string              $message     Error message.
	 * @param int                 $status      HTTP status, 0 when no response came.
	 * @param int                 $retry_after Seconds the platform asked to wait, 0 for none.
	 * @return string `retry` or `failed` (out of attempts or too old).
	 */
	public function retry_later( array $job, string $message, int $status, int $retry_after = 0 ): string {
		$now                = $this->now();
		$job['attempts']    = (int) ( $job['attempts'] ?? 0 ) + 1;
		$job['last_error']  = $message;
		$job['last_status'] = $status;
		$expired            = $job['attempts'] >= self::MAX_ATTEMPTS || $now - (int) ( $job['created_at'] ?? $now ) >= self::MAX_AGE;
		if ( $expired ) {
			$job['status']    = 'failed';
			$job['next_at']   = 0;
			$job['failed_at'] = $now;
			$this->keep_failed( $job );
			return 'failed';
		}
		$job['next_at'] = $now + max( self::backoff( $job['attempts'] ), min( $retry_after, self::MAX_DELAY ) );
		$this->store->put( (string) $job['id'], $job );
		return 'retry';
	}

	/**
	 * Gives up on a job the platform rejected for good.
	 *
	 * @param array<string,mixed> $job     The job.
	 * @param string              $message Error message.
	 * @param int                 $status  HTTP status.
	 */
	public function fail( array $job, string $message, int $status ): void {
		$job['attempts']    = (int) ( $job['attempts'] ?? 0 ) + 1;
		$job['status']      = 'failed';
		$job['next_at']     = 0;
		$job['last_error']  = $message;
		$job['last_status'] = $status;
		$job['failed_at']   = $this->now();
		$this->keep_failed( $job );
	}

	/**
	 * Stores a failed job and alerts the admin about it.
	 *
	 * @param array<string,mixed> $job The failed job.
	 */
	private function keep_failed( array $job ): void {
		$this->store->put( (string) $job['id'], $job );
		if ( null !== $this->alerts ) {
			$this->store->put( (string) $job['id'], $this->alerts->failed( $job ) );
		}
	}

	/**
	 * Sends the alert again for failed jobs whose mail did not go out.
	 *
	 * @return int Jobs alerted.
	 */
	public function alert_pending(): int {
		if ( null === $this->alerts ) {
			return 0;
		}
		$count = 0;
		foreach ( $this->store->all() as $id => $job ) {
			if ( 'failed' === ( $job['status'] ?? '' ) && empty( $job['alerted_at'] ) ) {
				$job = $this->alerts->failed( $job );
				$this->store->put( (string) $id, $job );
				$count += empty( $job['alerted_at'] ) ? 0 : 1;
			}
		}
		return $count;
	}

	/**
	 * Puts every failed job back in line.
	 *
	 * @return int Jobs requeued.
	 */
	public function retry_failed(): int {
		$now   = $this->now();
		$count = 0;
		foreach ( $this->store->all() as $id => $job ) {
			if ( 'failed' !== ( $job['status'] ?? '' ) ) {
				continue;
			}
			$job['status']     = 'pending';
			$job['attempts']   = 0;
			$job['next_at']    = $now;
			$job['created_at'] = $now;
			unset( $job['failed_at'], $job['alerted_at'], $job['exported_at'] );
			$this->store->put( (string) $id, $job );
			++$count;
		}
		return $count;
	}

	/**
	 * Removes failed jobs kept longer than the retention, when the admin was
	 * alerted about them or exported them. Mails a summary of the ones the admin
	 * never exported.
	 *
	 * @return int Jobs removed.
	 */
	public function prune(): int {
		$now        = $this->now();
		$keep       = null !== $this->alerts ? $this->alerts->retention() : self::KEEP_FAILED;
		$remove     = array();
		$unexported = array();
		foreach ( $this->store->all() as $id => $job ) {
			if ( 'failed' !== ( $job['status'] ?? '' ) || ! ( isset( $job['exported_at'] ) || isset( $job['alerted_at'] ) ) ) {
				continue;
			}
			$since = (int) ( $job['failed_at'] ?? $job['created_at'] ?? 0 );
			if ( $now - $since >= $keep ) {
				$remove[] = (string) $id;
				if ( ! isset( $job['exported_at'] ) ) {
					$unexported[] = (string) ( $job['payload']['source_form'] ?? '' );
				}
			}
		}
		if ( array() !== $unexported && null !== $this->alerts && ! $this->alerts->expired( $unexported ) ) {
			// The warning did not go out: keep the leads and try again on the next run.
			return 0;
		}
		foreach ( $remove as $id ) {
			$this->store->delete( $id );
		}
		return count( $remove );
	}

	/**
	 * The failed jobs, oldest first, with their personal data. For the export.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function failed_jobs(): array {
		$jobs = array_filter(
			$this->store->all(),
			static fn( array $job ): bool => 'failed' === ( $job['status'] ?? '' )
		);
		uasort( $jobs, static fn( array $a, array $b ): int => (int) ( $a['failed_at'] ?? 0 ) <=> (int) ( $b['failed_at'] ?? 0 ) );
		return $jobs;
	}

	/**
	 * Records that the failed jobs were exported.
	 *
	 * @param string[] $ids Job ids.
	 */
	public function mark_exported( array $ids ): void {
		$now  = $this->now();
		$jobs = $this->store->all();
		foreach ( $ids as $id ) {
			$job = $jobs[ $id ] ?? null;
			if ( null !== $job && 'failed' === ( $job['status'] ?? '' ) ) {
				$job['exported_at'] = $now;
				$this->store->put( $id, $job );
			}
		}
	}

	/**
	 * Removes the failed jobs that were exported.
	 *
	 * @return int Jobs removed.
	 */
	public function dismiss_exported(): int {
		$count = 0;
		foreach ( $this->store->all() as $id => $job ) {
			if ( 'failed' === ( $job['status'] ?? '' ) && isset( $job['exported_at'] ) ) {
				$this->store->delete( (string) $id );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Number of pending and failed jobs.
	 *
	 * @return array{pending:int,failed:int}
	 */
	public function counts(): array {
		$counts = array(
			'pending' => 0,
			'failed'  => 0,
		);
		foreach ( $this->store->all() as $job ) {
			$status = (string) ( $job['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}
		return $counts;
	}

	/**
	 * The last error of the failed jobs, newest first, without personal data.
	 *
	 * @param int $limit Most rows.
	 * @return array<int,array{form:string,error:string,status:int,attempts:int}>
	 */
	public function failures( int $limit = 5 ): array {
		$rows = array();
		foreach ( $this->store->all() as $job ) {
			if ( 'failed' === ( $job['status'] ?? '' ) ) {
				$rows[] = array(
					'form'     => (string) ( $job['payload']['source_form'] ?? '' ),
					'error'    => (string) ( $job['last_error'] ?? '' ),
					'status'   => (int) ( $job['last_status'] ?? 0 ),
					'attempts' => (int) ( $job['attempts'] ?? 0 ),
				);
			}
		}
		return array_slice( array_reverse( $rows ), 0, $limit );
	}

	/**
	 * When the next pending job is due.
	 */
	public function next_due_at(): ?int {
		$next = null;
		foreach ( $this->store->all() as $job ) {
			if ( 'pending' === ( $job['status'] ?? '' ) ) {
				$at   = (int) ( $job['next_at'] ?? 0 );
				$next = null === $next ? $at : min( $next, $at );
			}
		}
		return $next;
	}

	/**
	 * Takes the delivery lock.
	 *
	 * @param int $ttl Lifetime of the lock in seconds.
	 */
	public function lock( int $ttl ): bool {
		return $this->store->acquire( $this->now(), $ttl );
	}

	/**
	 * Releases the delivery lock.
	 */
	public function unlock(): void {
		$this->store->release();
	}
}
