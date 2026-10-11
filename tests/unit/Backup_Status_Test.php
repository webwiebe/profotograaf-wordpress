<?php
/**
 * Backup_Status and Backup_Labels: counts, heartbeat, cron health, a stuck
 * queue, bytes to send and the words for each state.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Profotograaf\Backup_Eligibility;
use Profotograaf\Backup_Labels;
use Profotograaf\Backup_State;
use Profotograaf\Backup_Status;
use Profotograaf\Backup_Worker;
use Profotograaf\Connection;
use Profotograaf\Cron_Health;
use Profotograaf\Settings;

class Backup_Status_Test extends Wp_Test_Case {

	use Backup_Fixtures;

	/**
	 * Counts the fake counter returns.
	 *
	 * @var array<string,mixed>
	 */
	private array $found = array();

	private bool $cron_disabled = false;

	/**
	 * Previous $wpdb.
	 *
	 * @var mixed
	 */
	private $saved_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->now = 1790000000;
		$this->set_up_library();
		$this->backup( 'all' );
		$this->connect( 100000, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed galleries:write' );
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the global.
		$this->tear_down_library();
		parent::tearDown();
	}

	private function backup_status(): Backup_Status {
		return new Backup_Status(
			new Settings(),
			new Connection(),
			new Backup_State( $this->clock() ),
			$this->clock(),
			fn() => $this->found,
			fn() => $this->cron_disabled
		);
	}

	/**
	 * Stores a heartbeat.
	 *
	 * @param int $at         When the last run was.
	 * @param int $idle_since Since when the runs sent nothing.
	 */
	private function beat( int $at, int $idle_since = 0 ): void {
		$this->options[ Backup_Status::RUN_OPTION ] = array(
			'at'         => $at,
			'sent'       => 0,
			'stop'       => 'backoff',
			'last_sent'  => 0,
			'idle_since' => $idle_since,
		);
	}

	// Counts.

	public function test_counts_cover_every_state_and_ignore_unknown_ones(): void {
		$this->found = array(
			'queued'  => '4',
			'done'    => 10,
			'failed'  => 2,
			'skipped' => 3,
			'bogus'   => 9,
		);
		$status      = $this->backup_status();

		$this->assertSame(
			array(
				'queued'      => 4,
				'uploading'   => 0,
				'done'        => 10,
				'skipped'     => 3,
				'failed'      => 2,
				'needs_check' => 0,
			),
			$status->counts()
		);
		$this->assertSame( 4, $status->remaining() );
		$this->assertSame( 2, $status->waiting_for_you() );
		$this->assertSame( 16, $status->total() );
	}

	public function test_the_counts_are_read_once(): void {
		$calls  = 0;
		$status = new Backup_Status(
			new Settings(),
			new Connection(),
			null,
			$this->clock(),
			function () use ( &$calls ) {
				++$calls;
				return array( 'needs_check' => 1 );
			}
		);

		$status->counts();
		$this->assertSame( 1, $status->waiting_for_you() );
		$this->assertSame( 1, $calls );
	}

	public function test_the_query_groups_the_attachments_by_state(): void {
		$wpdb            = new class() {
			public string $posts    = 'wp_posts';
			public string $postmeta = 'wp_postmeta';
			public string $sql      = '';

			/**
			 * @param string $query SQL.
			 * @param mixed  ...$args Values.
			 */
			public function prepare( string $query, ...$args ): string {
				return str_replace( '%s', "'" . $args[0] . "'", $query );
			}

			/**
			 * @return array<int,mixed>
			 */
			public function get_results( string $sql ): array {
				$this->sql = $sql;
				return array(
					(object) array(
						'state' => 'done',
						'n'     => '7',
					),
					(object) array(
						'state' => 'queued',
						'n'     => '2',
					),
					'junk',
				);
			}
		};
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the query reads the global.

		$this->assertSame(
			array(
				'done'   => 7,
				'queued' => 2,
			),
			Backup_Status::query_counts()
		);
		$this->assertStringContainsString( "m.meta_key = '_profotograaf_backup_state'", $wpdb->sql );
		$this->assertStringContainsString( 'GROUP BY m.meta_value', $wpdb->sql );
	}

	// Heartbeat.

	public function test_a_run_that_sends_records_the_time_and_ends_the_idle_stretch(): void {
		$this->beat( 5, 3 );

		Backup_Status::record_run(
			array(
				'sent' => 2,
				'stop' => 'budget',
			),
			$this->now
		);

		$this->assertSame(
			array(
				'at'         => $this->now,
				'sent'       => 2,
				'stop'       => 'budget',
				'last_sent'  => $this->now,
				'idle_since' => 0,
			),
			$this->backup_status()->last_run()
		);
		$this->assertFalse( $this->autoload[ Backup_Status::RUN_OPTION ] );
	}

	public function test_runs_that_send_nothing_keep_the_start_of_the_idle_stretch(): void {
		Backup_Status::record_run(
			array(
				'sent' => 0,
				'stop' => 'backoff',
			),
			100
		);
		Backup_Status::record_run(
			array(
				'sent' => 0,
				'stop' => 'paused',
			),
			400
		);
		$this->assertSame( 100, $this->backup_status()->last_run()['idle_since'] );
		$this->assertSame( 400, $this->backup_status()->last_run()['at'] );

		Backup_Status::record_run(
			array(
				'sent' => 0,
				'stop' => 'busy',
			),
			700
		);
		$this->assertSame( 100, $this->backup_status()->last_run()['idle_since'] );

		Backup_Status::record_run(
			array(
				'sent' => 0,
				'stop' => 'empty',
			),
			1000
		);
		$this->assertSame( 0, $this->backup_status()->last_run()['idle_since'] );
	}

	public function test_a_busy_first_run_does_not_start_an_idle_stretch(): void {
		Backup_Status::record_run(
			array(
				'sent' => 0,
				'stop' => 'busy',
			),
			100
		);

		$this->assertSame( 0, $this->backup_status()->last_run()['idle_since'] );
	}

	public function test_a_damaged_heartbeat_reads_as_none(): void {
		$this->options[ Backup_Status::RUN_OPTION ] = 'junk';

		$this->assertSame( 0, $this->backup_status()->last_run()['at'] );
	}

	// Active, permission, pause.

	public function test_active_needs_the_switch_and_a_connection(): void {
		$this->assertTrue( $this->backup_status()->active() );
		$this->backup( 'all', false );
		$this->assertFalse( $this->backup_status()->active() );
		$this->backup( 'all' );
		unset( $this->options['profotograaf_connection'] );
		$this->assertFalse( $this->backup_status()->active() );
	}

	public function test_a_connection_without_galleries_write_needs_permission(): void {
		$this->assertFalse( $this->backup_status()->needs_permission() );
		$this->connect( 100000, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed' );
		$this->assertTrue( $this->backup_status()->needs_permission() );
	}

	public function test_the_pause_shows_while_it_lasts(): void {
		$this->assertSame( '', $this->backup_status()->pause()['reason'] );

		$this->options[ Backup_Worker::PAUSE ] = array(
			'reason' => 'storage_full',
			'until'  => $this->now + 60,
		);
		$this->assertSame(
			array(
				'reason' => 'storage_full',
				'until'  => $this->now + 60,
			),
			$this->backup_status()->pause()
		);

		$this->options[ Backup_Worker::PAUSE ]['until'] = $this->now;
		$this->assertSame( '', $this->backup_status()->pause()['reason'] );

		$this->options[ Backup_Worker::PAUSE ] = 'junk';
		$this->assertSame( 0, $this->backup_status()->pause()['until'] );
	}

	// Cron.

	public function test_a_recent_run_means_cron_works(): void {
		$this->beat( $this->now - 600 );

		$this->assertSame(
			array(
				'ok'       => true,
				'disabled' => false,
				'last_run' => $this->now - 600,
			),
			$this->backup_status()->cron()
		);
	}

	public function test_a_run_older_than_an_hour_means_cron_stopped(): void {
		$this->beat( $this->now - Backup_Status::STALE_AFTER - 1 );

		$this->assertFalse( $this->backup_status()->cron()['ok'] );
	}

	public function test_before_the_first_run_an_overdue_event_means_cron_stopped(): void {
		$this->assertTrue( $this->backup_status()->cron()['ok'] );

		$this->events[ Backup_Worker::HOOK ][] = array( $this->now - 60, array(), Backup_Worker::SCHEDULE );
		$this->assertTrue( $this->backup_status()->cron()['ok'] );

		$this->events[ Backup_Worker::HOOK ][0][0] = $this->now - Backup_Status::STALE_AFTER - 1;
		$this->assertFalse( $this->backup_status()->cron()['ok'] );
	}

	public function test_disabled_wp_cron_without_any_heartbeat_means_no_cron(): void {
		$this->cron_disabled = true;
		$this->assertSame(
			array(
				'ok'       => false,
				'disabled' => true,
				'last_run' => 0,
			),
			$this->backup_status()->cron()
		);

		// The lead delivery heartbeat proves a system cron.
		$this->options[ Cron_Health::OPTION ] = $this->now - 100;
		$this->assertTrue( $this->backup_status()->cron()['ok'] );

		$this->options[ Cron_Health::OPTION ] = $this->now - Cron_Health::STALE_AFTER - 1;
		$this->assertFalse( $this->backup_status()->cron()['ok'] );
	}

	public function test_the_default_reads_disable_wp_cron(): void {
		$status = new Backup_Status( new Settings(), new Connection(), null, $this->clock(), fn() => array() );

		$this->assertSame( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, $status->cron()['disabled'] );
	}

	// Stuck.

	public function test_files_waiting_six_hours_without_a_send_are_stuck(): void {
		$this->found = array( 'queued' => 3 );
		$this->beat( $this->now - 60, $this->now - Backup_Status::STUCK_AFTER - 1 );

		$this->assertTrue( $this->backup_status()->stuck() );
	}

	public function test_a_short_idle_stretch_or_an_empty_queue_is_not_stuck(): void {
		$this->found = array( 'queued' => 3 );
		$this->beat( $this->now - 60, $this->now - 600 );
		$this->assertFalse( $this->backup_status()->stuck() );

		$this->beat( $this->now - 60 );
		$this->assertFalse( $this->backup_status()->stuck() );

		$this->found = array();
		$this->beat( $this->now - 60, $this->now - Backup_Status::STUCK_AFTER - 1 );
		$this->assertFalse( $this->backup_status()->stuck() );
	}

	public function test_another_reason_explains_the_wait_and_is_not_stuck(): void {
		$this->found = array( 'queued' => 3 );
		$this->beat( $this->now - 60, $this->now - Backup_Status::STUCK_AFTER - 1 );

		$this->options[ Backup_Worker::PAUSE ] = array(
			'reason' => 'storage_full',
			'until'  => $this->now + 60,
		);
		$this->assertFalse( $this->backup_status()->stuck() );
		unset( $this->options[ Backup_Worker::PAUSE ] );

		$this->connect( 100000, 'access-1', 'refresh-1', 'galleries:read' );
		$this->assertFalse( $this->backup_status()->stuck() );
		$this->connect( 100000, 'access-1', 'refresh-1', 'galleries:read galleries:write' );

		$this->beat( $this->now - Backup_Status::STALE_AFTER - 1, $this->now - Backup_Status::STUCK_AFTER - 1 );
		$this->assertFalse( $this->backup_status()->stuck() );
	}

	// Bytes and failures.

	public function test_bytes_add_up_the_queued_files(): void {
		$this->queued( 1, str_repeat( 'a', 100 ) );
		$this->queued( 2, str_repeat( 'b', 50 ) );
		$this->queued( 3 );
		unset( $this->attached[3] );
		$this->found = array( 'queued' => 3 );

		$this->assertSame(
			array(
				'bytes'   => 150,
				'partial' => false,
			),
			$this->backup_status()->bytes_queued( new Backup_Eligibility( new Settings() ) )
		);
	}

	public function test_bytes_are_partial_when_more_files_wait_than_are_scanned(): void {
		$this->queued( 1, 'abc' );
		$this->found = array( 'queued' => Backup_Status::BYTES_SCAN + 1 );

		$this->assertTrue( $this->backup_status()->bytes_queued( new Backup_Eligibility( new Settings() ) )['partial'] );
	}

	public function test_failures_list_failed_files_before_unconfirmed_ones(): void {
		foreach ( array( 1 => 'needs_check', 2 => 'failed', 3 => 'done', 4 => 'failed', 5 => 'needs_check' ) as $id => $state ) {
			$this->attachment( $id );
			$this->meta[ $id ][ Backup_State::META_STATE ] = $state;
		}

		$this->assertSame( array( 2, 4, 1, 5 ), $this->backup_status()->failures( 10 ) );
		$this->assertSame( array( 2, 4, 1 ), $this->backup_status()->failures( 3 ) );
		$this->assertSame( array( 2 ), $this->backup_status()->failures( 1 ) );
	}

	// Labels.

	public function test_each_state_has_a_label(): void {
		$this->assertSame( 'Waiting to be backed up', Backup_Labels::state( 'queued' ) );
		$this->assertSame( 'Being backed up', Backup_Labels::state( 'uploading' ) );
		$this->assertSame( 'Backed up', Backup_Labels::state( 'done' ) );
		$this->assertSame( 'Not backed up', Backup_Labels::state( 'skipped' ) );
		$this->assertSame( 'Backup failed', Backup_Labels::state( 'failed' ) );
		$this->assertSame( 'Backup not confirmed', Backup_Labels::state( 'needs_check' ) );
		$this->assertSame( 'Marked for the backup', Backup_Labels::state( '', true ) );
		$this->assertSame( '', Backup_Labels::state( '' ) );
	}

	public function test_each_reason_has_a_sentence(): void {
		$reasons = array(
			Backup_Eligibility::REASON_IMPORTED,
			Backup_Eligibility::REASON_MISSING_FILE,
			Backup_Eligibility::REASON_TOO_LARGE,
			Backup_Eligibility::REASON_EXCLUDED,
			Backup_Worker::REASON_REJECTED,
			Backup_Worker::REASON_NO_ANSWER,
			Backup_Worker::REASON_INTERRUPTED,
			Backup_Worker::REASON_HOST_MEMORY,
		);
		foreach ( $reasons as $reason ) {
			$this->assertStringEndsWith( '.', Backup_Labels::reason( $reason ), $reason );
		}
		$this->assertSame( 'Profotograaf answered with error 500.', Backup_Labels::reason( 'http_500' ) );
		$this->assertSame( 'The upload failed (profotograaf_odd).', Backup_Labels::reason( 'profotograaf_odd' ) );
		$this->assertSame( '', Backup_Labels::reason( '' ) );
	}

	public function test_a_file_label_carries_the_reason_only_when_it_was_not_sent(): void {
		$state         = new Backup_State( $this->clock() );
		$this->meta[1] = array(
			Backup_State::META_STATE  => 'failed',
			Backup_State::META_REASON => 'http_502',
		);
		$this->meta[2] = array(
			Backup_State::META_STATE  => 'queued',
			Backup_State::META_REASON => 'stale',
		);
		$this->meta[3] = array( Backup_State::META_MARKED => '1' );

		$this->assertSame(
			array(
				'label'  => 'Backup failed',
				'reason' => 'Profotograaf answered with error 502.',
			),
			Backup_Labels::of( $state, 1 )
		);
		$this->assertSame( '', Backup_Labels::of( $state, 2 )['reason'] );
		$this->assertSame( 'Marked for the backup', Backup_Labels::of( $state, 3 )['label'] );
	}
}
