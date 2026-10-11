<?php
/**
 * The backfill: walks the existing Media Library once for the backup.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Walks every attachment in id order, BATCH ids per WP-Cron run, and lets
 * Backup_Eligibility queue or skip each one. The highest id checked is the
 * cursor (option `profotograaf_backup_cursor`), so a batch never repeats work
 * and an interrupted walk continues where it stopped. The walk starts again
 * from the first id when the backup is switched on or its scope changes,
 * because either can select other files. Items that are done or wait for the
 * photographer keep their state (see Backup_Eligibility::apply()).
 *
 * Nothing is uploaded here; Backup_Worker does that.
 */
final class Backup_Backfill {

	public const HOOK   = 'profotograaf_backup_backfill';
	public const OPTION = 'profotograaf_backup_cursor';

	/** Attachments checked per run. */
	public const BATCH = 200;

	/** Seconds between two batches. */
	public const GAP = 30;

	/**
	 * Eligibility rules.
	 *
	 * @var Backup_Eligibility
	 */
	private Backup_Eligibility $eligibility;

	/**
	 * Current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Backup_Eligibility $eligibility Eligibility rules.
	 * @param callable|null      $clock       Returns the current unix time.
	 */
	public function __construct( Backup_Eligibility $eligibility, ?callable $clock = null ) {
		$this->eligibility = $eligibility;
		$this->clock       = $clock ?? static fn(): int => time();
	}

	/**
	 * Starts the walk from the first attachment and schedules the first batch.
	 */
	public function restart(): void {
		update_option(
			self::OPTION,
			array(
				'cursor'   => 0,
				'complete' => false,
			),
			false
		);
		Backup_Usage::forget();
		$this->schedule( $this->now() );
	}

	/**
	 * Schedules the next batch when the walk is not complete, for example
	 * after the site was connected again.
	 */
	public function resume(): void {
		if ( ! $this->status()['complete'] ) {
			$this->schedule( $this->now() );
		}
	}

	/**
	 * The cursor and whether the walk reached the last attachment.
	 *
	 * @return array{cursor:int,complete:bool}
	 */
	public function status(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'cursor'   => max( 0, (int) ( $stored['cursor'] ?? 0 ) ),
			'complete' => ! empty( $stored['complete'] ),
		);
	}

	/**
	 * Checks the next batch. Schedules the batch after it unless this one
	 * reached the end. Called by WP-Cron and by `wp profotograaf backup run`.
	 *
	 * @return int How many attachments this batch checked.
	 */
	public function run(): int {
		$status = $this->status();
		if ( $status['complete'] ) {
			return 0;
		}
		$ids = $this->next_ids( $status['cursor'] );
		foreach ( $ids as $id ) {
			$this->eligibility->apply( $id );
		}
		$complete = count( $ids ) < self::BATCH;
		update_option(
			self::OPTION,
			array(
				'cursor'   => array() === $ids ? $status['cursor'] : (int) end( $ids ),
				'complete' => $complete,
			),
			false
		);
		if ( ! $complete ) {
			$this->schedule( $this->now() + self::GAP );
		}
		return count( $ids );
	}

	/**
	 * Attachment ids above the cursor, lowest first.
	 *
	 * @param int $cursor Highest id checked so far.
	 * @return array<int,int>
	 */
	private function next_ids( int $cursor ): array {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a keyset walk in WP-Cron, which WP_Query cannot express.
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$cursor,
				self::BATCH
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Schedules a batch unless one is waiting already.
	 *
	 * @param int $at Unix time.
	 */
	private function schedule( int $at ): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( $at, self::HOOK );
		}
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->clock )();
	}
}
