<?php
/**
 * In-memory job store.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Profotograaf\Leads\Job_Store;

/**
 * Keeps jobs in an array. Set $throw to make every write fail.
 */
final class Memory_Job_Store implements Job_Store {

	/**
	 * Jobs by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $jobs = array();

	/**
	 * Whether the lock is taken.
	 *
	 * @var bool
	 */
	public bool $locked = false;

	/**
	 * Fail every add.
	 *
	 * @var bool
	 */
	public bool $throw = false;

	/**
	 * Adds a job unless the id exists.
	 *
	 * @param string              $id  Job id.
	 * @param array<string,mixed> $job Job.
	 * @throws \RuntimeException When $throw is set.
	 */
	public function add( string $id, array $job ): bool {
		if ( $this->throw ) {
			throw new \RuntimeException( 'database is gone' );
		}
		if ( isset( $this->jobs[ $id ] ) ) {
			return false;
		}
		$this->jobs[ $id ] = $job;
		return true;
	}

	/**
	 * All jobs.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		return $this->jobs;
	}

	/**
	 * Replaces a job.
	 *
	 * @param string              $id  Job id.
	 * @param array<string,mixed> $job Job.
	 */
	public function put( string $id, array $job ): void {
		$this->jobs[ $id ] = $job;
	}

	/**
	 * Removes a job.
	 *
	 * @param string $id Job id.
	 */
	public function delete( string $id ): void {
		unset( $this->jobs[ $id ] );
	}

	/**
	 * Takes the lock.
	 *
	 * @param int $now Now.
	 * @param int $ttl Lifetime.
	 */
	public function acquire( int $now, int $ttl ): bool {
		if ( $this->locked ) {
			return false;
		}
		$this->locked = true;
		return true;
	}

	/**
	 * Releases the lock.
	 */
	public function release(): void {
		$this->locked = false;
	}
}
