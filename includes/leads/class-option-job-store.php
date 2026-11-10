<?php
/**
 * Queue storage in the options table.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * One option per job, named `profotograaf_lead_job_<id>`, never autoloaded.
 *
 * A row per job (instead of one option holding a list) means two visitors
 * submitting at the same moment cannot overwrite each other, and add_option()
 * refusing an existing name is the idempotency check. The lock is one more
 * option. Uninstall removes all of them with the `profotograaf_` prefix.
 */
final class Option_Job_Store implements Job_Store {

	private const PREFIX = 'profotograaf_lead_job_';
	private const LOCK   = 'profotograaf_lead_lock';
	private const LIMIT  = 1000;

	/**
	 * Adds a job unless the id exists.
	 *
	 * @param string              $id  Job id.
	 * @param array<string,mixed> $job Job.
	 */
	public function add( string $id, array $job ): bool {
		return (bool) add_option( self::PREFIX . $id, wp_json_encode( $job ), '', false );
	}

	/**
	 * Every job by id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;

		// Every pending job is read (the queue caps them), so a long run of failed
		// jobs cannot push the newest enquiries out of view. Failed jobs are
		// kept for 90 days, so only the newest LIMIT of them are read.
		$like    = $wpdb->esc_like( self::PREFIX ) . '%';
		$pending = '%' . $wpdb->esc_like( '"status":"pending"' ) . '%';
		$rows    = array_merge(
			(array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the queue is a set of options found by prefix.
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s ORDER BY option_id ASC",
					$like,
					$pending
				),
				ARRAY_A
			),
			(array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the queue is a set of options found by prefix.
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value NOT LIKE %s ORDER BY option_id DESC LIMIT %d",
					$like,
					$pending,
					self::LIMIT
				),
				ARRAY_A
			)
		);

		$jobs = array();
		foreach ( $rows as $row ) {
			$job = json_decode( (string) $row['option_value'], true );
			if ( is_array( $job ) ) {
				$jobs[ substr( (string) $row['option_name'], strlen( self::PREFIX ) ) ] = $job;
			}
		}
		return $jobs;
	}

	/**
	 * Replaces a job.
	 *
	 * @param string              $id  Job id.
	 * @param array<string,mixed> $job Job.
	 */
	public function put( string $id, array $job ): void {
		update_option( self::PREFIX . $id, wp_json_encode( $job ), false );
	}

	/**
	 * Removes a job.
	 *
	 * @param string $id Job id.
	 */
	public function delete( string $id ): void {
		delete_option( self::PREFIX . $id );
	}

	/**
	 * Takes the delivery lock.
	 *
	 * @param int $now Current unix time.
	 * @param int $ttl Lifetime of the lock.
	 */
	public function acquire( int $now, int $ttl ): bool {
		if ( add_option( self::LOCK, $now + $ttl, '', false ) ) {
			return true;
		}
		// A run that died leaves its lock behind: take it over once it expired.
		if ( (int) get_option( self::LOCK, 0 ) <= $now ) {
			delete_option( self::LOCK );
			return (bool) add_option( self::LOCK, $now + $ttl, '', false );
		}
		return false;
	}

	/**
	 * Releases the delivery lock.
	 */
	public function release(): void {
		delete_option( self::LOCK );
	}
}
