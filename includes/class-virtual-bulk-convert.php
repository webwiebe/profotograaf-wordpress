<?php
/**
 * Converts every virtual attachment to local in batches (issue #158).
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use Profotograaf\Modules\Virtual_Convert;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk "Convert to local" before the plugin is deactivated or deleted.
 *
 * A virtual attachment has no file on this site, so it shows no image once the
 * plugin is off. This class converts them all with the conversion of issue
 * #152 (Virtual_Convert::convert_many()), a batch at a time:
 *
 * - In the background: start() stores the job in OPTION and schedules a
 *   single WP-Cron event. Each run converts BATCH attachments as the user who
 *   started the job and schedules the next run, until none is left.
 * - From WP-CLI: `wp profotograaf virtual convert` calls convert_batch() in a
 *   loop (Cli\Virtual_Command).
 *
 * An attachment that fails stays virtual and is skipped for the rest of the
 * job, so a photo Profotograaf no longer serves never blocks the others.
 */
final class Virtual_Bulk_Convert {

	/** Job state, autoload off. Uninstall removes it with the other `profotograaf_` options. */
	public const OPTION = 'profotograaf_virtual_bulk';

	/** WP-Cron hook of one batch. */
	public const HOOK = 'profotograaf_virtual_bulk_convert';

	/** Attachments per background run. Each one is a download plus the image sizes. */
	public const BATCH = 10;

	/** Failure messages kept for the Plugins screen. */
	private const FAILURES_KEPT = 20;

	/**
	 * The conversion of issue #152.
	 *
	 * @var Virtual_Convert
	 */
	private Virtual_Convert $convert;

	/**
	 * Constructor.
	 *
	 * @param Virtual_Convert|null $convert Conversion, built on demand when null.
	 */
	public function __construct( ?Virtual_Convert $convert = null ) {
		$this->convert = $convert ?? new Virtual_Convert();
	}

	/**
	 * Number of attachments that carry the virtual mark, whatever the setting.
	 */
	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", Virtual_Attachments::META_VIRTUAL, '1' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one count on the Plugins screen and in WP-CLI.
	}

	/**
	 * The stored job.
	 *
	 * @return array{status:string,user:int,started:int,finished:int,converted:int,failed_ids:array<int,int>,failures:array<int,array{title:string,message:string}>}
	 */
	public function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'status'     => isset( $stored['status'] ) && is_string( $stored['status'] ) ? $stored['status'] : '',
			'user'       => (int) ( $stored['user'] ?? 0 ),
			'started'    => (int) ( $stored['started'] ?? 0 ),
			'finished'   => (int) ( $stored['finished'] ?? 0 ),
			'converted'  => (int) ( $stored['converted'] ?? 0 ),
			'failed_ids' => isset( $stored['failed_ids'] ) && is_array( $stored['failed_ids'] ) ? array_map( 'intval', $stored['failed_ids'] ) : array(),
			'failures'   => $this->failures( $stored['failures'] ?? array() ),
		);
	}

	/**
	 * The stored failure messages, as plain strings.
	 *
	 * @param mixed $stored Stored list.
	 * @return array<int,array{title:string,message:string}>
	 */
	private function failures( $stored ): array {
		$rows = array();
		foreach ( is_array( $stored ) ? $stored : array() as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = array(
					'title'   => is_scalar( $row['title'] ?? null ) ? (string) $row['title'] : '',
					'message' => is_scalar( $row['message'] ?? null ) ? (string) $row['message'] : '',
				);
			}
		}
		return $rows;
	}

	/**
	 * Whether a background job is running.
	 */
	public function running(): bool {
		return 'running' === $this->state()['status'];
	}

	/**
	 * Starts a background job for the given user. A running job keeps going.
	 *
	 * @param int $user_id User whose capabilities the conversion uses.
	 * @return bool Whether a new job started.
	 */
	public function start( int $user_id ): bool {
		if ( $this->running() ) {
			$this->resume();
			return false;
		}
		$this->save(
			array(
				'status'     => 'running',
				'user'       => $user_id,
				'started'    => time(),
				'finished'   => 0,
				'converted'  => 0,
				'failed_ids' => array(),
				'failures'   => array(),
			)
		);
		wp_schedule_single_event( time(), self::HOOK );
		return true;
	}

	/**
	 * Schedules the next run of a running job that lost its event, for example
	 * because the plugin was deactivated (which clears every event) and
	 * activated again, or because a run ended in a fatal error.
	 */
	public function resume(): void {
		if ( $this->running() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * WP-Cron callback: converts one batch and schedules the next.
	 */
	public function run(): void {
		$state = $this->state();
		if ( 'running' !== $state['status'] ) {
			return;
		}
		wp_set_current_user( $state['user'] );
		if ( $state['user'] <= 0 || ! current_user_can( 'upload_files' ) ) {
			$state['status']   = 'stopped';
			$state['finished'] = time();
			$this->save( $state );
			Logger::error( 'The bulk conversion to local stopped: the user who started it may no longer upload files.' );
			return;
		}
		$batch = $this->convert_batch( $state['failed_ids'], self::BATCH );
		if ( array() === $batch['ids'] ) {
			$state['status']   = 'done';
			$state['finished'] = time();
			$this->save( $state );
			return;
		}
		$state['converted'] += $batch['converted'];
		$state['failed_ids'] = array_values( array_unique( array_merge( $state['failed_ids'], $batch['failed_ids'] ) ) );
		$state['failures']   = array_slice( array_merge( $state['failures'], $batch['failed'] ), 0, self::FAILURES_KEPT );
		$this->save( $state );
		wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * Converts up to $size virtual attachments, leaving out the ids in $skip.
	 *
	 * @param array<int,int> $skip Ids that failed earlier in the same job.
	 * @param int            $size Batch size.
	 * @return array{ids:array<int,int>,converted:int,failed:array<int,array{title:string,message:string}>,failed_ids:array<int,int>}
	 */
	public function convert_batch( array $skip, int $size ): array {
		$size   = max( 1, $size );
		$marked = $this->convert->marked_ids( $size + count( $skip ), false );
		$ids    = array_slice( array_values( array_diff( $marked, $skip ) ), 0, $size );
		if ( array() === $ids ) {
			return array(
				'ids'        => array(),
				'converted'  => 0,
				'failed'     => array(),
				'failed_ids' => array(),
			);
		}
		$result = $this->convert->convert_many( $ids );
		return array(
			'ids'        => $ids,
			'converted'  => $result['converted'],
			'failed'     => $result['failed'],
			'failed_ids' => array_values( array_filter( $ids, array( Virtual_Attachments::class, 'is_marked' ) ) ),
		);
	}

	/**
	 * Stores the job.
	 *
	 * @param array<string,mixed> $state Job state.
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
