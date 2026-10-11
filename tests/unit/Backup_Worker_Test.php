<?php
/**
 * Backup worker: sending, galleries, pause and backoff, lost answers,
 * duplicates, the lock and the budgets. Faked HTTP, real logic.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Backup_Eligibility;
use Profotograaf\Backup_Galleries;
use Profotograaf\Backup_State;
use Profotograaf\Backup_Worker;
use Profotograaf\Connection;
use Profotograaf\Logger;
use Profotograaf\Settings;

class Backup_Worker_Test extends Wp_Test_Case {

	use Backup_Fixtures;

	private Fake_Transport $http;

	private bool $cron = true;

	private float $elapsed = 0.0;

	private float $tick = 0.0;

	private int $free = PHP_INT_MAX;

	protected function setUp(): void {
		parent::setUp();
		$this->now = 1790000000;
		Logger::configure( false );
		$this->set_up_library();
		$this->backup( 'all' );
		$this->connect( 900, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed galleries:write' );
		Functions\when( 'wp_doing_cron' )->alias( fn() => $this->cron );
		$this->http = new Fake_Transport();
	}

	protected function tearDown(): void {
		$this->tear_down_library();
		Logger::configure( null );
		parent::tearDown();
	}

	private function worker(): Backup_Worker {
		$connection = new Connection();
		$api        = new Api_Client( $connection, $this->http, $this->clock() );
		$state      = new Backup_State( $this->clock() );
		return new Backup_Worker(
			new Settings(),
			$connection,
			$api,
			new Backup_Eligibility( new Settings(), $state ),
			$state,
			new Backup_Galleries( $api ),
			$this->clock(),
			function (): float {
				$this->elapsed += $this->tick;
				return $this->elapsed;
			},
			fn(): int => $this->free
		);
	}

	private function gallery_created( string $id = 'bg-1' ): void {
		$this->http->reply(
			201,
			array(
				'id'    => $id,
				'slug'  => $id,
				'title' => 'Example Photography backup 2026-10',
			)
		);
	}

	private function uploaded( string $photo, string $gallery = 'bg-1' ): void {
		$this->http->reply(
			202,
			array(
				'id'         => $photo,
				'gallery_id' => $gallery,
				'status'     => 'uploaded',
			)
		);
	}

	/**
	 * Request URLs, without the host.
	 *
	 * @return array<int,string>
	 */
	private function paths(): array {
		return array_map( fn( $request ) => str_replace( 'https://profotograaf.nl', '', $request['url'] ), $this->http->requests );
	}

	/**
	 * Log entries at one level.
	 *
	 * @param string $level Level.
	 * @return array<int,string>
	 */
	private function logged( string $level ): array {
		$entries = array_filter( Logger::entries(), fn( $entry ) => $level === $entry['level'] );
		return array_values( array_map( fn( $entry ) => $entry['message'], $entries ) );
	}

	public function test_a_run_creates_each_month_gallery_once_and_sends_every_queued_file(): void {
		$this->queued( 11 );
		$this->queued( 12 );
		$this->queued( 13, '', '2026-09-30 23:00:00' );
		$this->gallery_created( 'bg-oct' );
		$this->uploaded( 'p-11', 'bg-oct' );
		$this->uploaded( 'p-12', 'bg-oct' );
		$this->gallery_created( 'bg-sep' );
		$this->uploaded( 'p-13', 'bg-sep' );

		$result = $this->worker()->run();

		$this->assertSame(
			array(
				'sent' => 3,
				'stop' => 'empty',
			),
			$result
		);
		$this->assertSame(
			array(
				'/api/v1/embed/galleries',
				'/api/v1/embed/galleries/bg-oct/photos',
				'/api/v1/embed/galleries/bg-oct/photos',
				'/api/v1/embed/galleries',
				'/api/v1/embed/galleries/bg-sep/photos',
			),
			$this->paths()
		);
		$this->assertSame( array( 'title' => 'Example Photography backup 2026-10' ), $this->http->body( 0 ) );
		$this->assertSame( array( 'title' => 'Example Photography backup 2026-09' ), $this->http->body( 3 ) );
		$this->assertStringContainsString( 'filename="photo-11.jpg"', (string) $this->http->requests[1]['body'] );
		$this->assertStringContainsString( 'bytes of 11', (string) $this->http->requests[1]['body'], 'The file is sent as stored.' );
		$this->assertSame(
			array(
				'2026-10' => 'bg-oct',
				'2026-09' => 'bg-sep',
			),
			$this->options[ Backup_Galleries::OPTION ]
		);

		$sha = hash( 'sha256', 'bytes of 12' );
		$this->assertSame( 'done', $this->state_of( 12 ) );
		$this->assertSame( 'p-12', $this->meta[12][ Backup_State::META_PHOTO_ID ] );
		$this->assertSame( 'bg-oct', $this->meta[12][ Backup_State::META_GALLERY_ID ] );
		$this->assertSame( $sha, $this->meta[12][ Backup_State::META_SHA256 ] );
		$this->assertSame( $sha, $this->meta[12][ Backup_State::META_COPY_SHA256 ] );
		$this->assertSame( 'p-12', $this->meta[12][ Backup_State::META_COPY_PHOTO ] );
		$this->assertArrayNotHasKey( Backup_Worker::LOCK, $this->options, 'The lock is released.' );
	}

	public function test_backup_galleries_are_never_made_embeddable(): void {
		$this->queued( 11 );
		$this->gallery_created();
		$this->uploaded( 'p-11' );

		$this->worker()->run();

		foreach ( $this->http->requests as $request ) {
			$this->assertStringNotContainsString( 'embeddable', $request['url'] );
			$this->assertNotSame( 'PUT', $request['method'] );
		}
		$this->assertTrue( ( new Backup_Galleries( new Api_Client( new Connection(), $this->http ) ) )->contains( 'bg-1' ) );
	}

	public function test_the_api_client_refuses_to_make_a_backup_gallery_embeddable(): void {
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-oct' );
		$api                                       = new Api_Client( new Connection(), $this->http );

		$result = $api->mark_embeddable( 'bg-oct' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_backup_gallery', $result->get_error_code() );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_contains_reads_the_stored_ids_without_a_request(): void {
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-oct' );

		$this->assertTrue( Backup_Galleries::contains( 'bg-oct' ) );
		$this->assertFalse( Backup_Galleries::contains( 'g-1' ) );
		$this->assertFalse( Backup_Galleries::contains( '' ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_nothing_is_sent_while_the_backup_is_off(): void {
		$this->queued( 11 );
		$this->backup( 'all', false );

		$this->assertSame( 'off', $this->worker()->run()['stop'] );
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( 'queued', $this->state_of( 11 ) );
	}

	public function test_nothing_is_sent_inside_a_page_request(): void {
		$this->queued( 11 );
		$this->cron = false;

		$this->assertSame( 'foreground', $this->worker()->run()['stop'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_nothing_is_sent_without_a_connection_or_the_write_scope(): void {
		$this->queued( 11 );
		$this->connect( 900, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed' );

		$this->assertSame( 'reconnect', $this->worker()->run()['stop'] );

		unset( $this->options['profotograaf_connection'] );
		$this->assertSame( 'not_connected', $this->worker()->run()['stop'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_only_queued_items_are_sent(): void {
		foreach ( array( 21 => 'done', 22 => 'skipped', 23 => 'failed', 24 => 'needs_check' ) as $id => $state ) {
			$this->attachment( $id );
			$this->meta[ $id ]['_profotograaf_backup_state'] = $state;
		}
		$this->attachment( 25 );

		$this->assertSame( 'empty', $this->worker()->run()['stop'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_a_queued_file_that_lost_its_eligibility_is_not_sent(): void {
		$this->queued( 11 );
		unlink( $this->attached[11] );
		$this->queued( 12 );
		$this->meta[12]['_profotograaf_photo_id'] = 'p-imported';

		$this->worker()->run();

		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( 'skipped', $this->state_of( 11 ) );
		$this->assertSame( Backup_Eligibility::REASON_MISSING_FILE, $this->meta[11][ Backup_State::META_REASON ] );
		$this->assertSame( Backup_Eligibility::REASON_IMPORTED, $this->meta[12][ Backup_State::META_REASON ] );
	}

	public function test_full_storage_pauses_the_backup_and_keeps_the_file_queued(): void {
		$this->queued( 11 );
		$this->queued( 12 );
		$this->gallery_created();
		$this->http->reply( 413, array( 'code' => 'storage.cap_reached' ) );

		$this->assertSame( 'storage_full', $this->worker()->run()['stop'] );
		$this->assertCount( 2, $this->http->requests, 'The run stops at the first 413.' );
		$this->assertSame( 'queued', $this->state_of( 11 ) );
		$this->assertSame( 'queued', $this->state_of( 12 ) );
		$this->assertSame( 'storage_full', $this->options[ Backup_Worker::PAUSE ]['reason'] );
		$this->assertSame( $this->now + Backup_Worker::STORAGE_PAUSE, $this->options[ Backup_Worker::PAUSE ]['until'] );
		$this->assertContains( 'Profotograaf storage is full; the backup is paused.', $this->logged( 'warning' ) );

		$this->now += 3600;
		$this->options['profotograaf_connection']['expires_at'] = $this->now + 900;
		$this->assertSame( 'paused', $this->worker()->run()['stop'], 'No retry loop.' );
		$this->assertCount( 2, $this->http->requests );

		$worker = $this->worker();
		$worker->resume();
		$this->uploaded( 'p-11' );
		$this->uploaded( 'p-12' );
		$this->assertSame( 2, $worker->run()['sent'] );
	}

	public function test_a_full_storage_pause_ends_after_a_day(): void {
		$this->queued( 11 );
		$this->options[ Backup_Worker::PAUSE ]     = array(
			'reason' => 'storage_full',
			'until'  => $this->now - 1,
			'count'  => 1,
		);
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->uploaded( 'p-11' );

		$this->assertSame( 1, $this->worker()->run()['sent'] );
		$this->assertArrayNotHasKey( Backup_Worker::PAUSE, $this->options, 'A sent file ends the pause.' );
	}

	public function test_429_and_503_wait_for_retry_after_else_5_15_and_60_minutes(): void {
		$this->queued( 11 );
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );

		$this->http->reply( 429, null, array( 'retry-after' => '120' ) );
		$this->assertSame( 'backoff', $this->worker()->run()['stop'] );
		$this->assertSame( $this->now + 120, $this->options[ Backup_Worker::PAUSE ]['until'] );
		$this->assertSame( 'queued', $this->state_of( 11 ) );

		$waits = array();
		foreach ( array( 1, 2, 3, 4 ) as $round ) {
			$this->now = $this->options[ Backup_Worker::PAUSE ]['until'];
			$this->options['profotograaf_connection']['expires_at'] = $this->now + 900;
			$this->http->reply( 503 );
			$this->assertSame( 'backoff', $this->worker()->run()['stop'], 'round ' . $round );
			$waits[] = $this->options[ Backup_Worker::PAUSE ]['until'] - $this->now;
		}
		$this->assertSame( array( 900, 3600, 3600, 3600 ), $waits, 'The 429 counted as the first wait.' );
		$this->assertSame( 'queued', $this->state_of( 11 ) );
		$this->assertSame( 'paused', $this->worker()->run()['stop'] );

		$this->now = $this->options[ Backup_Worker::PAUSE ]['until'];
		$this->options['profotograaf_connection']['expires_at'] = $this->now + 900;
		$this->uploaded( 'p-11' );
		$this->assertSame( 1, $this->worker()->run()['sent'] );
		$this->assertArrayNotHasKey( Backup_Worker::PAUSE, $this->options );
	}

	public function test_an_upload_without_a_clear_answer_needs_a_check_and_is_never_sent_again_on_its_own(): void {
		$this->queued( 11 );
		$this->queued( 12 );
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->http->fail( new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$this->assertSame( 'backoff', $this->worker()->run()['stop'] );
		$this->assertSame( 'needs_check', $this->state_of( 11 ) );
		$this->assertSame( Backup_Worker::REASON_NO_ANSWER, $this->meta[11][ Backup_State::META_REASON ] );
		$this->assertSame( 'queued', $this->state_of( 12 ), 'The run stops after a lost answer.' );
		$this->assertSame( $this->now + 300, $this->options[ Backup_Worker::PAUSE ]['until'] );
		$this->assertContains( 'A backup upload got no clear answer; the file may be on Profotograaf.', $this->logged( 'warning' ) );

		$this->now += 300;
		$this->options['profotograaf_connection']['expires_at'] = $this->now + 900;
		$this->http->reply( 502 );
		$this->assertSame( 'backoff', $this->worker()->run()['stop'] );
		$this->assertSame( 'needs_check', $this->state_of( 12 ) );

		$this->now += 900;
		$this->options['profotograaf_connection']['expires_at'] = $this->now + 900;
		$this->assertSame( 'empty', $this->worker()->run()['stop'] );
		$this->assertCount( 2, $this->http->requests, 'Neither file is sent again.' );

		// Applying the rules again leaves them alone too.
		$eligibility = new Backup_Eligibility( new Settings(), new Backup_State( $this->clock() ) );
		$this->assertSame( 'needs_check', $eligibility->apply( 11 ) );
	}

	public function test_an_upload_interrupted_by_a_crash_needs_a_check(): void {
		$this->attachment( 11 );
		$this->meta[11]['_profotograaf_backup_state'] = 'uploading';

		$this->assertSame( 'empty', $this->worker()->run()['stop'] );
		$this->assertSame( 'needs_check', $this->state_of( 11 ) );
		$this->assertSame( Backup_Worker::REASON_INTERRUPTED, $this->meta[11][ Backup_State::META_REASON ] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_a_403_keeps_the_file_queued_and_asks_for_a_new_pairing(): void {
		$this->queued( 11 );
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->http->reply( 403, array( 'code' => 'auth.scope' ) );

		$this->assertSame( 'reconnect', $this->worker()->run()['stop'] );
		$this->assertSame( 'queued', $this->state_of( 11 ) );
		$this->assertTrue( ( new Connection() )->needs_write_reconnect( true ) );

		$this->assertSame( 'reconnect', $this->worker()->run()['stop'] );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_a_403_on_gallery_creation_keeps_the_file_queued(): void {
		$this->queued( 11 );
		$this->http->reply( 403 );

		$this->assertSame( 'reconnect', $this->worker()->run()['stop'] );
		$this->assertSame( 'queued', $this->state_of( 11 ) );
		$this->assertArrayNotHasKey( Backup_Galleries::OPTION, $this->options );
	}

	public function test_a_deleted_gallery_is_replaced_on_the_next_run(): void {
		$this->queued( 11 );
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-old' );
		$this->http->reply( 404 );

		$this->assertSame( 'gallery_missing', $this->worker()->run()['stop'] );
		$this->assertSame( 'queued', $this->state_of( 11 ) );
		$this->assertSame( array(), $this->options[ Backup_Galleries::OPTION ] );

		$this->gallery_created( 'bg-new' );
		$this->uploaded( 'p-11', 'bg-new' );
		$this->assertSame( 1, $this->worker()->run()['sent'] );
		$this->assertSame( 'bg-new', $this->meta[11][ Backup_State::META_GALLERY_ID ] );
	}

	public function test_a_rejected_file_is_skipped_and_another_error_fails_the_file(): void {
		$this->queued( 11 );
		$this->queued( 12 );
		$this->queued( 13 );
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->http->reply( 400, array( 'code' => 'upload.type' ) );
		$this->http->reply( 422 );
		$this->uploaded( 'p-13' );

		$result = $this->worker()->run();

		$this->assertSame( 1, $result['sent'] );
		$this->assertSame( 'skipped', $this->state_of( 11 ) );
		$this->assertSame( Backup_Worker::REASON_REJECTED, $this->meta[11][ Backup_State::META_REASON ] );
		$this->assertSame( 'failed', $this->state_of( 12 ) );
		$this->assertSame( 'http_422', $this->meta[12][ Backup_State::META_REASON ] );
		$this->assertSame( 1, $this->meta[12][ Backup_State::META_FAILURES ] );
		$this->assertContains( 'A backup file could not be sent to Profotograaf.', $this->logged( 'error' ) );
		$this->assertSame( 'done', $this->state_of( 13 ) );
	}

	public function test_a_failed_gallery_creation_waits_and_keeps_the_file_queued(): void {
		$this->queued( 11 );
		$this->http->reply( 503 );

		$this->assertSame( 'backoff', $this->worker()->run()['stop'] );
		$this->assertSame( 'queued', $this->state_of( 11 ) );
		$this->assertSame( array(), $this->logged( 'error' ), 'A retryable error is no error yet.' );

		$this->now += 300;
		$this->options['profotograaf_connection']['expires_at'] = $this->now + 900;
		$this->http->reply( 422 );
		$this->assertSame( 'backoff', $this->worker()->run()['stop'] );
		$this->assertSame( 'queued', $this->state_of( 11 ) );
		$this->assertContains( 'A backup gallery could not be created on Profotograaf.', $this->logged( 'error' ) );

		$this->now += 900;
		$this->options['profotograaf_connection']['expires_at'] = $this->now + 900;
		$this->http->reply( 413 );
		$this->assertSame( 'storage_full', $this->worker()->run()['stop'] );
	}

	public function test_a_file_copied_before_a_reinstall_is_not_sent_again(): void {
		$this->queued( 11 );
		$sha = hash( 'sha256', 'bytes of 11' );
		$this->meta[11][ Backup_State::META_COPY_SHA256 ]  = $sha;
		$this->meta[11][ Backup_State::META_COPY_PHOTO ]   = 'p-old';
		$this->meta[11][ Backup_State::META_COPY_GALLERY ] = 'bg-old';

		$this->assertSame(
			array(
				'sent' => 0,
				'stop' => 'empty',
			),
			$this->worker()->run()
		);
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( 'done', $this->state_of( 11 ) );
		$this->assertSame( 'p-old', $this->meta[11][ Backup_State::META_PHOTO_ID ] );
		$this->assertSame( 'bg-old', $this->meta[11][ Backup_State::META_GALLERY_ID ] );
	}

	public function test_the_same_bytes_in_a_second_attachment_are_not_sent_twice(): void {
		$this->queued( 11, 'same bytes' );
		$this->queued( 12, 'same bytes' );
		$this->gallery_created();
		$this->uploaded( 'p-11' );

		$this->worker()->run();

		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( 'done', $this->state_of( 12 ) );
		$this->assertSame( 'p-11', $this->meta[12][ Backup_State::META_PHOTO_ID ] );
	}

	public function test_a_file_edited_after_its_copy_is_sent_again(): void {
		$this->queued( 11, 'edited bytes' );
		$this->meta[11][ Backup_State::META_COPY_SHA256 ] = hash( 'sha256', 'old bytes' );
		$this->meta[11][ Backup_State::META_COPY_PHOTO ]  = 'p-old';
		$this->options[ Backup_Galleries::OPTION ]        = array( '2026-10' => 'bg-1' );
		$this->uploaded( 'p-new' );

		$this->assertSame( 1, $this->worker()->run()['sent'] );
		$this->assertSame( 'p-new', $this->meta[11][ Backup_State::META_COPY_PHOTO ] );
	}

	public function test_a_held_lock_stops_a_second_run_until_it_is_abandoned(): void {
		$this->queued( 11 );
		$this->options[ Backup_Worker::LOCK ] = $this->now - 60;

		$this->assertSame( 'busy', $this->worker()->run()['stop'] );
		$this->assertSame( array(), $this->http->requests );

		$this->options[ Backup_Worker::LOCK ]      = $this->now - Backup_Worker::LOCK_TTL;
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->uploaded( 'p-11' );
		$this->assertSame( 1, $this->worker()->run()['sent'] );
	}

	public function test_the_time_budget_ends_a_run(): void {
		$this->queued( 11 );
		$this->queued( 12 );
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->tick                                = 15.0;
		$this->uploaded( 'p-11' );

		$this->assertSame(
			array(
				'sent' => 1,
				'stop' => 'budget',
			),
			$this->worker()->run()
		);
		$this->assertSame( 'queued', $this->state_of( 12 ) );
	}

	public function test_a_run_sends_at_most_max_files(): void {
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		for ( $id = 1; $id <= Backup_Worker::MAX_FILES + 1; $id++ ) {
			$this->queued( $id );
			$this->uploaded( 'p-' . $id );
		}

		$this->assertSame(
			array(
				'sent' => Backup_Worker::MAX_FILES,
				'stop' => 'limit',
			),
			$this->worker()->run()
		);
		$this->assertSame( 'queued', $this->state_of( Backup_Worker::MAX_FILES + 1 ) );
	}

	public function test_a_file_that_does_not_fit_in_memory_is_skipped_or_left_for_the_next_run(): void {
		$this->queued( 11 );
		$this->free = Backup_Worker::MEMORY_RESERVE;

		$this->assertSame( 'empty', $this->worker()->run()['stop'] );
		$this->assertSame( 'skipped', $this->state_of( 11 ) );
		$this->assertSame( Backup_Worker::REASON_HOST_MEMORY, $this->meta[11][ Backup_State::META_REASON ] );

		$this->queued( 12 );
		$this->queued( 13 );
		$this->options[ Backup_Galleries::OPTION ] = array( '2026-10' => 'bg-1' );
		$this->free                                = PHP_INT_MAX;
		$this->http->reply( 202, array( 'id' => 'p-12' ) );
		Functions\when( 'wp_basename' )->alias(
			function ( $path ) {
				$this->free = Backup_Worker::MEMORY_RESERVE;
				return basename( (string) $path );
			}
		);

		$this->assertSame(
			array(
				'sent' => 1,
				'stop' => 'memory',
			),
			$this->worker()->run()
		);
		$this->assertSame( 'queued', $this->state_of( 13 ), 'The next run starts with fresh memory.' );
	}

	public function test_free_memory_reads_the_php_limit(): void {
		Functions\when( 'wp_convert_hr_to_bytes' )->alias( fn( $value ) => '-1' === $value ? -1 : 256 * 1024 * 1024 );

		$this->assertGreaterThan( 0, Backup_Worker::free_memory() );
	}

	public function test_the_month_comes_from_the_upload_date(): void {
		$this->attachment( 11, '', '2025-02-14 10:00:00' );
		$this->attachment( 12, '', '0000-00-00 00:00:00' );

		$this->assertSame( '2025-02', Backup_Galleries::month_of( 11, $this->now ) );
		$this->assertSame( gmdate( 'Y-m', $this->now ), Backup_Galleries::month_of( 12, $this->now ) );
		$this->assertSame( gmdate( 'Y-m', $this->now ), Backup_Galleries::month_of( 99, $this->now ) );
	}

	public function test_a_gallery_title_falls_back_to_the_host_and_stays_short(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		$this->assertSame( 'photos.example.com backup 2026-10', Backup_Galleries::title( '2026-10' ) );

		Functions\when( 'get_bloginfo' )->justReturn( str_repeat( 'a', 300 ) );
		$this->assertSame( str_repeat( 'a', 150 ) . ' backup 2026-10', Backup_Galleries::title( '2026-10' ) );
	}
}
