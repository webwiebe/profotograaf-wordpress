<?php
/**
 * Storage for queued leads.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Where queued lead jobs live. A job is an array (see Queue).
 */
interface Job_Store {

	/**
	 * Adds a job. Returns false and stores nothing when the id exists, which is
	 * what makes enqueueing the same submission twice harmless.
	 *
	 * @param string              $id  Job id.
	 * @param array<string,mixed> $job Job.
	 */
	public function add( string $id, array $job ): bool;

	/**
	 * Every job by id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array;

	/**
	 * Replaces a job.
	 *
	 * @param string              $id  Job id.
	 * @param array<string,mixed> $job Job.
	 */
	public function put( string $id, array $job ): void;

	/**
	 * Removes a job.
	 *
	 * @param string $id Job id.
	 */
	public function delete( string $id ): void;

	/**
	 * Takes the delivery lock, so two cron runs never send at the same time.
	 *
	 * @param int $now Current unix time.
	 * @param int $ttl Seconds after which a crashed run's lock is ignored.
	 */
	public function acquire( int $now, int $ttl ): bool;

	/**
	 * Releases the delivery lock.
	 */
	public function release(): void;
}
