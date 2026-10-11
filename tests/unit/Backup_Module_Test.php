<?php
/**
 * Backup producers, the schedule, the backfill and `wp profotograaf backup run`.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Backup_Backfill;
use Profotograaf\Backup_Galleries;
use Profotograaf\Backup_State;
use Profotograaf\Backup_Status;
use Profotograaf\Backup_Usage;
use Profotograaf\Backup_Worker;
use Profotograaf\Cli\Backup_Command;
use Profotograaf\Connection;
use Profotograaf\Logger;
use Profotograaf\Modules\Backup;
use Profotograaf\Plugin;
use Profotograaf\Settings;

require_once dirname( __DIR__ ) . '/wp-cli-stubs.php';

class Recording_Backup extends Backup {

	public int $finished = 0;

	protected function finish(): void {
		++$this->finished;
	}
}

class Backup_Module_Test extends Wp_Test_Case {

	use Backup_Fixtures;

	private Fake_Transport $http;

	private Backup $module;

	/**
	 * Previous $wpdb.
	 *
	 * @var mixed
	 */
	private $saved_wpdb;

	/**
	 * Queries the fake $wpdb answered.
	 *
	 * @var array<int,string>
	 */
	public array $sql = array();

	protected function setUp(): void {
		parent::setUp();
		$this->now = 1790000000;
		Logger::configure( false );
		\WP_CLI::reset();
		$this->set_up_library();
		$this->backup( 'all' );
		$this->connect( 100000, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed galleries:write' );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( -1 );
		Functions\when( '_n' )->alias( fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		$this->http       = new Fake_Transport();
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']  = $this->fake_wpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the backfill reads the global.
		$connection       = new Connection();
		$plugin           = new Plugin( $connection, new Api_Client( $connection, $this->http, $this->clock() ), $this->clock() );
		$this->module     = new Backup();
		$this->module->register( $plugin );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the global.
		$this->tear_down_library();
		Logger::configure( null );
		parent::tearDown();
	}

	/**
	 * A $wpdb that answers the backfill's keyset query from the fake library.
	 */
	private function fake_wpdb(): object {
		$test = $this;
		return new class( $test ) {

			public string $posts = 'wp_posts';

			private Backup_Module_Test $test;

			public function __construct( Backup_Module_Test $test ) {
				$this->test = $test;
			}

			/**
			 * @param string $query SQL.
			 * @param mixed  ...$args Values.
			 */
			public function prepare( string $query, ...$args ): string {
				return vsprintf( $query, $args );
			}

			/**
			 * @return array<int,string>
			 */
			public function get_col( string $sql ): array {
				$this->test->sql[] = $sql;
				preg_match( '/ID > (\d+) ORDER BY ID ASC LIMIT (\d+)/', $sql, $match );
				return array_map( 'strval', $this->test->attachment_ids_above( (int) $match[1], (int) $match[2] ) );
			}
		};
	}

	/**
	 * Attachment ids in the fake library above a cursor.
	 *
	 * @param int $cursor Cursor.
	 * @param int $limit  Limit.
	 * @return array<int,int>
	 */
	public function attachment_ids_above( int $cursor, int $limit ): array {
		$ids = array();
		foreach ( $this->posts as $id => $post ) {
			if ( 'attachment' === $post->post_type && $id > $cursor ) {
				$ids[] = $id;
			}
		}
		sort( $ids );
		return array_slice( $ids, 0, $limit );
	}

	/**
	 * Lines printed by WP_CLI at one level.
	 *
	 * @param string $level Level.
	 * @return array<int,string>
	 */
	private function printed( string $level ): array {
		$lines = array();
		foreach ( \WP_CLI::$output as $row ) {
			if ( $level === $row[0] ) {
				$lines[] = $row[1];
			}
		}
		return $lines;
	}

	private function command(): Backup_Command {
		return new Backup_Command( new Settings(), $this->module->backfill(), $this->module->worker() );
	}

	private function uploaded( string $photo ): void {
		$this->http->reply( 202, array( 'id' => $photo ) );
	}

	// The schedule.

	public function test_the_five_minute_schedule_is_added(): void {
		$schedules = $this->module->add_schedule( array( 'hourly' => array( 'interval' => 3600 ) ) );

		$this->assertSame( 300, $schedules[ Backup_Worker::SCHEDULE ]['interval'] );
		$this->assertSame( 'Every five minutes (Profotograaf backup)', $schedules[ Backup_Worker::SCHEDULE ]['display'] );
		$this->assertArrayHasKey( 'hourly', $schedules );
		$this->assertArrayHasKey( Backup_Worker::SCHEDULE, $this->module->add_schedule( null ) );
	}

	public function test_the_upload_run_is_scheduled_once_while_the_backup_is_on_and_connected(): void {
		$this->module->sync_schedule();
		$this->module->sync_schedule();

		$this->assertSame( array( array( $this->now + Backup::FIRST_RUN, array(), Backup_Worker::SCHEDULE ) ), $this->events[ Backup_Worker::HOOK ] );
	}

	public function test_every_backup_event_is_removed_while_the_backup_is_off_or_disconnected(): void {
		$this->events = array(
			Backup_Worker::HOOK   => array( array( 1, array(), Backup_Worker::SCHEDULE ) ),
			Backup_Backfill::HOOK => array( array( 1, array(), '' ) ),
			Backup::SCAN_HOOK     => array( array( 1, array( 5 ), '' ) ),
		);
		$this->backup( 'all', false );
		$this->module->sync_schedule();
		$this->assertSame( array(), $this->events );

		$this->backup( 'all' );
		unset( $this->options['profotograaf_connection'] );
		$this->module->sync_schedule();
		$this->assertSame( array(), $this->events );
	}

	public function test_switching_the_backup_on_starts_the_walk_and_lifts_a_pause(): void {
		$this->options[ Backup_Worker::PAUSE ]    = array(
			'reason' => 'storage_full',
			'until'  => $this->now + 3600,
			'count'  => 1,
		);
		$this->options[ Backup_Backfill::OPTION ] = array(
			'cursor'   => 90,
			'complete' => true,
		);
		$this->transients[ Backup_Usage::CACHE ]  = array( 1 );

		$this->module->settings_changed( array( 'backup' => false ), array( 'backup' => true ) );

		$this->assertArrayNotHasKey( Backup_Worker::PAUSE, $this->options );
		$this->assertSame(
			array(
				'cursor'   => 0,
				'complete' => false,
			),
			$this->options[ Backup_Backfill::OPTION ]
		);
		$this->assertArrayNotHasKey( Backup_Usage::CACHE, $this->transients );
		$this->assertSame( $this->now, $this->events[ Backup_Backfill::HOOK ][0][0] );
		$this->assertArrayHasKey( Backup_Worker::HOOK, $this->events );
	}

	public function test_a_new_scope_starts_the_walk_again_and_other_changes_do_not(): void {
		$done = array(
			'cursor'   => 90,
			'complete' => true,
		);

		$this->options[ Backup_Backfill::OPTION ] = $done;
		$this->module->settings_changed(
			array(
				'backup'       => true,
				'backup_scope' => 'used',
			),
			array(
				'backup'       => true,
				'backup_scope' => 'used',
				'media_upload' => true,
			)
		);
		$this->assertSame( $done, $this->options[ Backup_Backfill::OPTION ] );
		$this->assertArrayNotHasKey( Backup_Backfill::HOOK, $this->events );

		$this->module->settings_changed(
			array(
				'backup'       => true,
				'backup_scope' => 'used',
			),
			array(
				'backup'       => true,
				'backup_scope' => 'all',
			)
		);
		$this->assertSame( 0, $this->options[ Backup_Backfill::OPTION ]['cursor'] );
	}

	public function test_switching_the_backup_off_removes_its_events(): void {
		$this->module->sync_schedule();
		$this->backup( 'all', false );

		$this->module->settings_changed( array( 'backup' => true ), array( 'backup' => false ) );

		$this->assertSame( array(), $this->events );
	}

	public function test_a_new_pairing_resumes_an_unfinished_walk_and_the_schedule(): void {
		$this->options[ Backup_Worker::PAUSE ]    = array(
			'reason' => 'backoff',
			'until'  => $this->now + 900,
			'count'  => 2,
		);
		$this->options[ Backup_Backfill::OPTION ] = array(
			'cursor'   => 40,
			'complete' => false,
		);

		$this->module->on_connected();

		$this->assertArrayNotHasKey( Backup_Worker::PAUSE, $this->options );
		$this->assertSame( 40, $this->options[ Backup_Backfill::OPTION ]['cursor'] );
		$this->assertArrayHasKey( Backup_Backfill::HOOK, $this->events );
		$this->assertArrayHasKey( Backup_Worker::HOOK, $this->events );
	}

	public function test_a_new_pairing_leaves_a_finished_walk_and_a_switched_off_backup_alone(): void {
		$this->options[ Backup_Backfill::OPTION ] = array(
			'cursor'   => 40,
			'complete' => true,
		);
		$this->module->on_connected();
		$this->assertArrayNotHasKey( Backup_Backfill::HOOK, $this->events );

		$this->events = array();
		$this->backup( 'all', false );
		$this->module->on_connected();
		$this->assertSame( array(), $this->events );
	}

	// Producers.

	public function test_a_new_upload_is_queued_under_scope_all_only(): void {
		$this->attachment( 11 );
		$this->module->on_new_attachment( '11' );
		$this->assertSame( 'queued', $this->state_of( 11 ) );

		$this->backup( 'used' );
		$this->attachment( 12 );
		$this->module->on_new_attachment( 12 );
		$this->assertSame( '', $this->state_of( 12 ) );

		$this->backup( 'all', false );
		$this->attachment( 13 );
		$this->module->on_new_attachment( 13 );
		$this->assertSame( '', $this->state_of( 13 ) );
		$this->assertSame( array(), $this->http->requests, 'A producer never uploads.' );
	}

	public function test_publishing_a_post_schedules_one_background_check_under_scope_used(): void {
		$this->backup( 'used' );
		$this->content( 5, '<img class="wp-image-11" />' );

		$this->module->on_save_post( 5, $this->posts[5] );
		$this->module->on_save_post( 5, $this->posts[5] );

		$this->assertSame( array( array( $this->now + Backup::FIRST_RUN, array( 5 ), '' ) ), $this->events[ Backup::SCAN_HOOK ] );
	}

	public function test_saving_a_draft_an_attachment_or_under_another_scope_schedules_nothing(): void {
		$this->backup( 'used' );
		$this->content( 5, '', 'draft' );
		$this->content( 6, '', 'publish', 'revision' );
		$this->attachment( 7 );
		$this->posts[7]->post_status = 'publish';

		$this->module->on_save_post( 5, $this->posts[5] );
		$this->module->on_save_post( 6, $this->posts[6] );
		$this->module->on_save_post( 7, $this->posts[7] );
		$this->module->on_save_post( 8, null );

		$this->backup( 'all' );
		$this->content( 9, '' );
		$this->module->on_save_post( 9, $this->posts[9] );

		$this->assertSame( array(), $this->events );
	}

	public function test_the_background_check_queues_the_files_a_published_post_uses(): void {
		$this->backup( 'used' );
		$this->attachment( 11 );
		$this->attachment( 12 );
		$this->attachment( 13 );
		$this->content( 5, '<img class="wp-image-11" />' );
		$this->meta[5]['_thumbnail_id']          = '12';
		$this->transients[ Backup_Usage::CACHE ] = array();

		$this->module->scan_post( 5 );

		$this->assertSame( 'queued', $this->state_of( 11 ) );
		$this->assertSame( 'queued', $this->state_of( 12 ) );
		$this->assertSame( '', $this->state_of( 13 ) );
	}

	public function test_the_background_check_ignores_unpublished_posts_and_other_scopes(): void {
		$this->backup( 'used' );
		$this->attachment( 11 );
		$this->content( 5, '<img class="wp-image-11" />', 'draft' );
		$this->module->scan_post( 5 );
		$this->module->scan_post( 99 );
		$this->assertSame( '', $this->state_of( 11 ) );

		$this->posts[5]->post_status = 'publish';
		$this->backup( 'marked' );
		$this->module->scan_post( 5 );
		$this->assertSame( '', $this->state_of( 11 ) );
	}

	public function test_cron_runs_the_worker_and_the_backfill(): void {
		$this->queued( 11 );
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->uploaded( 'p-11' );
		$this->module->run_worker();
		$this->assertSame( 'done', $this->state_of( 11 ) );
		$this->assertSame( $this->now, $this->options[ Backup_Status::RUN_OPTION ]['last_sent'] );

		$this->attachment( 12 );
		$this->module->run_backfill();
		$this->assertSame( 'queued', $this->state_of( 12 ) );
	}

	public function test_the_backfill_does_not_run_while_disconnected(): void {
		$this->attachment( 12 );
		unset( $this->options['profotograaf_connection'] );

		$this->module->run_backfill();

		$this->assertSame( '', $this->state_of( 12 ) );
		$this->assertSame( array(), $this->sql );
	}

	// The backfill.

	public function test_the_backfill_walks_the_library_in_batches_with_a_cursor(): void {
		for ( $id = 1; $id <= Backup_Backfill::BATCH + 3; $id++ ) {
			$this->attachment( $id );
		}
		$this->content( 900, '' );
		$backfill = $this->module->backfill();
		$backfill->restart();
		$this->assertSame( $this->now, $this->events[ Backup_Backfill::HOOK ][0][0] );
		$this->events = array();

		$this->assertSame( Backup_Backfill::BATCH, $backfill->run() );
		$this->assertSame(
			array(
				'cursor'   => Backup_Backfill::BATCH,
				'complete' => false,
			),
			$backfill->status()
		);
		$this->assertSame( $this->now + Backup_Backfill::GAP, $this->events[ Backup_Backfill::HOOK ][0][0] );
		$this->assertStringContainsString( "post_type = 'attachment' AND ID > 0 ORDER BY ID ASC LIMIT 200", $this->sql[0] );
		$this->events = array();

		$this->assertSame( 3, $backfill->run() );
		$this->assertSame(
			array(
				'cursor'   => Backup_Backfill::BATCH + 3,
				'complete' => true,
			),
			$backfill->status()
		);
		$this->assertArrayNotHasKey( Backup_Backfill::HOOK, $this->events, 'The walk ends.' );
		$this->assertStringContainsString( 'ID > 200 ', $this->sql[1] );
		$this->assertSame( 'queued', $this->state_of( Backup_Backfill::BATCH + 3 ) );

		$this->assertSame( 0, $backfill->run() );
		$this->assertCount( 2, $this->sql );
		$this->assertSame( array(), $this->http->requests, 'The backfill never uploads.' );
	}

	public function test_an_empty_batch_completes_the_walk_and_keeps_the_cursor(): void {
		$this->options[ Backup_Backfill::OPTION ] = array(
			'cursor'   => 70,
			'complete' => false,
		);

		$this->assertSame( 0, $this->module->backfill()->run() );
		$this->assertSame(
			array(
				'cursor'   => 70,
				'complete' => true,
			),
			$this->module->backfill()->status()
		);
	}

	public function test_the_backfill_keeps_files_that_were_sent_or_wait_for_a_check(): void {
		$this->attachment( 11 );
		$this->meta[11]['_profotograaf_backup_state'] = 'done';
		$this->attachment( 12 );
		$this->meta[12]['_profotograaf_backup_state'] = 'needs_check';
		$this->attachment( 13 );
		$this->meta[13]['_profotograaf_backup_state'] = 'uploading';

		$this->module->backfill()->restart();
		$this->module->backfill()->run();

		$this->assertSame( 'done', $this->state_of( 11 ) );
		$this->assertSame( 'needs_check', $this->state_of( 12 ) );
		$this->assertSame( 'uploading', $this->state_of( 13 ) );
	}

	public function test_a_broken_cursor_option_starts_at_the_beginning(): void {
		$this->options[ Backup_Backfill::OPTION ] = 'broken';

		$this->assertSame(
			array(
				'cursor'   => 0,
				'complete' => false,
			),
			$this->module->backfill()->status()
		);
	}

	// wp profotograaf backup run.

	public function test_the_command_finishes_the_walk_and_sends_the_queue(): void {
		$this->attachment( 11 );
		$this->attachment( 12 );
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->uploaded( 'p-11' );
		$this->uploaded( 'p-12' );
		$this->module->backfill()->restart();

		$this->command()->run();

		$this->assertSame( array( 'Checked 2 Media Library items.' ), $this->printed( 'log' ) );
		$this->assertSame( array( 'Sent 2 files to Profotograaf.' ), $this->printed( 'success' ) );
		$this->assertSame( 'done', $this->state_of( 12 ) );
	}

	public function test_the_command_keeps_going_past_the_file_limit_of_one_run(): void {
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->options[ Backup_Backfill::OPTION ]  = array(
			'cursor'   => 0,
			'complete' => true,
		);
		for ( $id = 1; $id <= Backup_Worker::MAX_FILES + 2; $id++ ) {
			$this->queued( $id );
			$this->uploaded( 'p-' . $id );
		}

		$this->command()->run();

		$this->assertSame( array( 'Checked 0 Media Library items.' ), $this->printed( 'log' ) );
		$this->assertSame( array( 'Sent 52 files to Profotograaf.' ), $this->printed( 'success' ) );
	}

	public function test_the_command_replaces_a_deleted_gallery_once(): void {
		$this->options[ Backup_Backfill::OPTION ]  = array(
			'cursor'   => 0,
			'complete' => true,
		);
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-old' );
		$this->queued( 11 );
		$this->http->reply( 404 );
		$this->http->reply( 201, array( 'id' => 'bg-new' ) );
		$this->uploaded( 'p-11' );

		$this->command()->run();

		$this->assertSame( array( 'Sent 1 file to Profotograaf.' ), $this->printed( 'success' ) );
		$this->assertSame( 'bg-new', $this->meta[11][ Backup_State::META_GALLERY_ID ] );
	}

	public function test_the_command_explains_a_full_storage_and_resume_lifts_the_pause(): void {
		$this->options[ Backup_Backfill::OPTION ]  = array(
			'cursor'   => 0,
			'complete' => true,
		);
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->queued( 11 );
		$this->http->reply( 413 );

		$this->command()->run();
		$this->assertSame(
			array( 'Sent 0 files to Profotograaf. Your Profotograaf storage is full, so the backup is paused. Free up space or upgrade your plan, then run this command with --resume.' ),
			$this->printed( 'warning' )
		);

		\WP_CLI::reset();
		$this->command()->run();
		$this->assertStringEndsWith( 'Run this command with --resume to try again now.', $this->printed( 'warning' )[0] );

		\WP_CLI::reset();
		$this->uploaded( 'p-11' );
		$this->command()->run( array(), array( 'resume' => true ) );
		$this->assertSame( array( 'Sent 1 file to Profotograaf.' ), $this->printed( 'success' ) );
	}

	/**
	 * @dataProvider stops
	 */
	public function test_the_command_explains_why_it_stopped( string $setup, string $explanation ): void {
		$this->options[ Backup_Backfill::OPTION ]  = array(
			'cursor'   => 0,
			'complete' => true,
		);
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->queued( 11 );
		switch ( $setup ) {
			case 'not_connected':
				unset( $this->options['profotograaf_connection'] );
				break;
			case 'reconnect':
				$this->http->reply( 403 );
				break;
			case 'backoff':
				$this->http->reply( 503 );
				break;
			case 'busy':
				$this->options[ Backup_Worker::LOCK ] = $this->now;
				break;
			case 'gallery_missing':
				$this->http->reply( 404 );
				$this->http->reply( 201, array( 'id' => 'bg-new' ) );
				$this->http->reply( 404 );
				break;
		}

		$this->command()->run();

		$this->assertSame( array(), $this->printed( 'success' ) );
		$this->assertStringEndsWith( $explanation, $this->printed( 'warning' )[0] );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function stops(): array {
		return array(
			'not connected' => array( 'not_connected', 'This site is not connected to Profotograaf.' ),
			'reconnect'     => array( 'reconnect', 'The backup needs a new permission. Connect this site again under Settings > Profotograaf.' ),
			'backoff'       => array( 'backoff', 'Profotograaf did not accept more files for now. The backup tries again later on its own.' ),
			'busy'          => array( 'busy', 'Another backup run is in progress.' ),
			'other'         => array( 'gallery_missing', 'The backup stopped early. Run the command again to send the rest.' ),
		);
	}

	public function test_the_command_explains_low_memory(): void {
		$calls                                     = 0;
		$state                                     = new Backup_State( $this->clock() );
		$api                                       = new Api_Client( new Connection(), $this->http, $this->clock() );
		$command                                   = new Backup_Command(
			new Settings(),
			$this->module->backfill(),
			new Backup_Worker(
				new Settings(),
				new Connection(),
				$api,
				new \Profotograaf\Backup_Eligibility( new Settings(), $state ),
				$state,
				new Backup_Galleries( $api ),
				$this->clock(),
				null,
				function () use ( &$calls ): int {
					return 0 === $calls++ ? PHP_INT_MAX : 0;
				}
			)
		);
		$this->options[ Backup_Backfill::OPTION ]  = array(
			'cursor'   => 0,
			'complete' => true,
		);
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->queued( 11 );
		$this->queued( 12 );
		$this->uploaded( 'p-11' );

		$command->run();

		$this->assertSame(
			array( 'Sent 1 file to Profotograaf. This site ran low on memory. Run the command again to send the rest.' ),
			$this->printed( 'warning' )
		);
		$this->assertSame( 'queued', $this->state_of( 12 ) );
	}

	public function test_the_command_does_nothing_while_the_backup_is_off(): void {
		$this->backup( 'all', false );
		$this->queued( 11 );

		$this->command()->run();

		$this->assertSame( array( 'The backup is switched off. Switch it on under Settings > Profotograaf > Backup.' ), $this->printed( 'warning' ) );
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( array(), $this->sql );
	}

	// Kept state.

	public function test_the_copy_record_survives_the_keys_uninstall_removes(): void {
		foreach ( array( Backup_State::META_COPY_SHA256, Backup_State::META_COPY_PHOTO, Backup_State::META_COPY_GALLERY ) as $key ) {
			$this->assertStringStartsNotWith( '_profotograaf_backup_', $key );
			$this->assertStringStartsWith( '_profotograaf_copy_', $key );
		}
	}

	public function test_find_copy_looks_at_the_attachment_first_and_then_the_library(): void {
		$state = new Backup_State( $this->clock() );
		$this->attachment( 11 );
		$this->attachment( 12 );
		$state->record_copy( 11, 'abc', 'p-11', 'bg-1' );

		$this->assertSame(
			array(
				'photo_id'   => 'p-11',
				'gallery_id' => 'bg-1',
			),
			$state->find_copy( 11, 'abc' )
		);
		$this->assertSame( 'p-11', $state->find_copy( 12, 'abc' )['photo_id'] ?? null );
		$this->assertNull( $state->find_copy( 12, 'def' ) );
		$this->assertNull( $state->find_copy( 12, '' ) );
	}

	// Back up now and Retry failed.

	private function recording(): Recording_Backup {
		$connection = new Connection();
		$module     = new Recording_Backup();
		$module->register( new Plugin( $connection, new Api_Client( $connection, $this->http, $this->clock() ), $this->clock() ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		return $module;
	}

	public function test_back_up_now_lifts_the_pause_and_moves_the_run_to_now_without_sending(): void {
		$this->queued( 11 );
		$this->options[ Backup_Worker::PAUSE ]    = array(
			'reason' => 'storage_full',
			'until'  => $this->now + 3600,
		);
		$this->options[ Backup_Backfill::OPTION ] = array(
			'cursor'   => 5,
			'complete' => false,
		);
		$this->events[ Backup_Worker::HOOK ]      = array( array( $this->now + 200, array(), Backup_Worker::SCHEDULE ) );

		$this->assertTrue( $this->module->back_up_now() );

		$this->assertArrayNotHasKey( Backup_Worker::PAUSE, $this->options );
		$this->assertSame( array( array( $this->now, array(), Backup_Worker::SCHEDULE ) ), $this->events[ Backup_Worker::HOOK ] );
		$this->assertSame( $this->now, $this->events[ Backup_Backfill::HOOK ][0][0] );
		$this->assertSame( 'queued', $this->state_of( 11 ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_back_up_now_does_nothing_while_off_or_disconnected(): void {
		$this->backup( 'all', false );
		$this->assertFalse( $this->module->back_up_now() );
		$this->assertSame( array(), $this->events );
	}

	public function test_retry_failed_queues_failed_and_unconfirmed_files_again(): void {
		foreach ( array( 11 => 'failed', 12 => 'needs_check', 13 => 'done', 14 => 'skipped' ) as $id => $state ) {
			$this->attachment( $id );
			$this->meta[ $id ][ Backup_State::META_STATE ]    = $state;
			$this->meta[ $id ][ Backup_State::META_REASON ]   = 'http_500';
			$this->meta[ $id ][ Backup_State::META_FAILURES ] = 2;
		}

		$this->assertSame( 2, $this->module->retry_failed() );

		$this->assertSame( 'queued', $this->state_of( 11 ) );
		$this->assertSame( 'queued', $this->state_of( 12 ) );
		$this->assertSame( 'done', $this->state_of( 13 ) );
		$this->assertSame( 'skipped', $this->state_of( 14 ) );
		$this->assertArrayNotHasKey( Backup_State::META_REASON, $this->meta[11] );
		$this->assertSame( 2, $this->meta[11][ Backup_State::META_FAILURES ] );
		$this->assertArrayHasKey( Backup_Worker::HOOK, $this->events );
	}

	public function test_the_handlers_leave_a_notice_and_go_back_to_the_backup_tab(): void {
		$this->attachment( 11 );
		$this->meta[11][ Backup_State::META_STATE ] = 'failed';
		$module                                     = $this->recording();

		$module->handle_retry();
		$this->assertSame( '1 file will be sent again in the background.', $this->transients['profotograaf_notice_7']['message'] );

		$module->handle_now();
		$this->assertSame( 'The backup starts within a minute, in the background. You can leave this page.', $this->transients['profotograaf_notice_7']['message'] );

		$this->backup( 'all', false );
		$module->handle_now();
		$this->assertStringContainsString( 'cannot start', $this->transients['profotograaf_notice_7']['message'] );
		$this->assertSame( 3, $module->finished );
		$this->assertNotFalse( has_action( 'admin_post_' . Backup::NOW, array( $module, 'handle_now' ) ) );
	}

	public function test_the_handlers_refuse_users_without_manage_options(): void {
		$module = $this->recording();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$module->handle_retry();
	}
}
