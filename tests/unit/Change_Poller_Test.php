<?php
/**
 * Change feed poll tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Change_Poller;
use Profotograaf\Logger;
use Profotograaf\Media_Baseline;
use Profotograaf\Modules\Change_Poll;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Reconcile;
use Profotograaf\Settings;

class Change_Poller_Test extends Gallery_Test_Case {

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Single events scheduled.
	 *
	 * @var array<int,string>
	 */
	private array $single_events = array();

	/**
	 * Current unix time, advanced by tests that need the time budget.
	 *
	 * @var int
	 */
	private int $tick = 0;

	private Change_Poller $poller;

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		$this->connect();
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'get_posts' )->alias(
			function () {
				$ids = array();
				foreach ( $this->meta as $id => $meta ) {
					if ( ! empty( $meta['_profotograaf_photo_id'] ) && ( $meta['_profotograaf_origin'] ?? '' ) !== 'upload' ) {
						$ids[] = $id;
					}
				}
				return $ids;
			}
		);
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				$post               = new \WP_Post();
				$post->post_title   = (string) ( $this->meta[ $id ]['_title'] ?? 'Photo p1' );
				$post->post_excerpt = '';
				return $post;
			}
		);
		Functions\when( 'wp_get_original_image_path' )->justReturn( '' );
		Functions\when( 'get_attached_file' )->justReturn( '' );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $when, $hook ) {
				$this->single_events[] = $hook;
				return true;
			}
		);
		$this->tick   = $this->now;
		$this->poller = new Change_Poller( $this->api, new Photo_Catalogue( $this->api, new Settings(), $this->clock() ), fn() => $this->tick );
	}

	protected function tearDown(): void {
		Logger::configure( null );
		parent::tearDown();
	}

	/**
	 * An imported copy that matches photo $photo_id as listed by photo().
	 *
	 * @param int                 $id       Attachment id.
	 * @param string              $photo_id Photo id.
	 * @param array<string,mixed> $extra    Meta to add or replace.
	 */
	private function copy( int $id, string $photo_id = 'p1', array $extra = array() ): void {
		$this->meta[ $id ] = array_merge(
			array(
				'_profotograaf_photo_id'   => $photo_id,
				'_profotograaf_gallery_id' => 'g-1',
				'_profotograaf_version'    => '0123456789ab',
				'_profotograaf_text_hash'  => Media_Baseline::text_hash( 'Photo p1', '', 'Photo p1' ),
				'_wp_attachment_image_alt' => 'Photo p1',
			),
			$extra
		);
	}

	/**
	 * A photo as the library list returns it.
	 *
	 * @param string $id    Photo id.
	 * @param string $title Title.
	 * @return array<string,mixed>
	 */
	private function photo( string $id = 'p1', string $title = 'Photo p1' ): array {
		return array(
			'id'            => $id,
			'width'         => 1600,
			'height'        => 1067,
			'title'         => $title,
			'caption'       => '',
			'alt'           => '',
			'gallery_id'    => 'g-1',
			'gallery_title' => 'Spring wedding',
			'full_url'      => 'https://profotograaf.nl/share/img/a/web-0123456789ab.jpg',
		);
	}

	/**
	 * Queues the library list.
	 *
	 * @param array<int,array<string,mixed>> $photos Photos.
	 */
	private function library( array $photos ): void {
		$this->http->reply(
			200,
			array(
				'photos' => $photos,
				'total'  => count( $photos ),
				'limit'  => 200,
				'offset' => 0,
			)
		);
	}

	/**
	 * Queues one feed page.
	 *
	 * @param array<string,mixed> $over Fields to replace.
	 */
	private function feed( array $over = array() ): void {
		$this->http->reply(
			200,
			array_merge(
				array(
					'cursor'    => 'c-2',
					'has_more'  => false,
					'resync'    => false,
					'galleries' => array(
						'created' => array(),
						'updated' => array(),
						'deleted' => array(),
					),
					'photos'    => array(
						'created' => array(),
						'updated' => array(),
						'deleted' => array(),
					),
				),
				$over
			)
		);
	}

	/**
	 * A gallery list row.
	 *
	 * @param bool $serving Whether the gallery is embeddable and available.
	 * @return array<string,mixed>
	 */
	private function gallery( bool $serving = true ): array {
		return array_merge(
			$this->row( 'g-1' ),
			array(
				'embeddable' => $serving,
				'available'  => $serving,
			)
		);
	}

	public function test_it_reads_the_feed_with_the_stored_cursor_and_keeps_the_new_one(): void {
		$this->options[ Change_Poller::OPTION_CURSOR ] = 'c-1';
		$this->feed();

		$result = $this->poller->run();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['pages'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/changes?limit=500&since=c-1', $this->http->requests[0]['url'] );
		$this->assertSame( 'c-2', $this->options[ Change_Poller::OPTION_CURSOR ] );
		$this->assertSame( $this->now, $this->options[ Change_Poller::OPTION_LAST_OK ] );
		$this->assertFalse( $this->autoload[ Change_Poller::OPTION_CURSOR ] );
		$this->assertArrayNotHasKey( Change_Poller::OPTION_LOCK, $this->options );
	}

	public function test_it_does_nothing_but_move_the_cursor_for_entries_of_photos_it_never_imported(): void {
		$this->copy( 11 );
		$this->feed(
			array(
				'photos' => array(
					'created' => array(),
					'updated' => array( array( 'id' => 'other', 'gallery_id' => 'g-9' ) ),
					'deleted' => array( array( 'id' => 'foreign', 'gallery_id' => '' ) ),
				),
			)
		);
		$this->http->reply( 200, array( $this->gallery() ) );

		$this->poller->run();

		$this->assertSame( 'c-2', $this->options[ Change_Poller::OPTION_CURSOR ] );
		$urls = array_column( $this->http->requests, 'url' );
		$this->assertNotContains( 'https://profotograaf.nl/api/v1/embed/photos?limit=200&offset=0', $urls );
	}

	public function test_it_flags_an_imported_photo_deleted_on_the_platform(): void {
		$this->copy( 11 );
		$this->feed( array( 'photos' => array( 'created' => array(), 'updated' => array(), 'deleted' => array( array( 'id' => 'p1', 'gallery_id' => '' ) ) ) ) );
		$this->library( array() );
		$this->http->reply( 200, array( $this->gallery() ) );

		$result = $this->poller->run();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['applied'] );
		$this->assertSame( Reconcile::STATE_MISSING, $this->meta[11][ Reconcile::META_STATE ] );
		$this->assertSame( Reconcile::REASON_DELETED, $this->meta[11][ Reconcile::META_REASON ] );
		$this->assertSame( 1, $this->options[ Reconcile::COUNTS_OPTION ]['missing'] );
		$this->assertSame( 'c-2', $this->options[ Change_Poller::OPTION_CURSOR ] );
	}

	public function test_a_repeated_page_changes_nothing(): void {
		$this->copy( 11 );
		$page = array( 'photos' => array( 'created' => array(), 'updated' => array(), 'deleted' => array( array( 'id' => 'p1', 'gallery_id' => '' ) ) ) );
		$this->feed( $page );
		$this->library( array() );
		$this->http->reply( 200, array( $this->gallery() ) );
		$this->poller->run();
		$counts = $this->options[ Reconcile::COUNTS_OPTION ];
		$since  = $this->meta[11][ Reconcile::META_SINCE ];

		$this->tick += 600;
		$this->feed( $page );
		$this->library( array() );
		$this->http->reply( 200, array( $this->gallery() ) );
		$second = $this->poller->run();

		$this->assertSame( 0, $second['applied'] );
		$this->assertSame( $counts, $this->options[ Reconcile::COUNTS_OPTION ] );
		$this->assertSame( $since, $this->meta[11][ Reconcile::META_SINCE ] );
	}

	public function test_a_changed_file_version_is_flagged_as_changed(): void {
		$this->copy( 11, 'p1', array( '_profotograaf_version' => 'aaaaaaaaaaaa' ) );
		$this->feed( array( 'photos' => array( 'created' => array(), 'updated' => array( array( 'id' => 'p1', 'gallery_id' => 'g-1' ) ), 'deleted' => array() ) ) );
		$this->library( array( $this->photo() ) );
		$this->http->reply( 200, array( $this->gallery() ) );

		$this->poller->run();

		$this->assertSame( Reconcile::STATE_CHANGED, $this->meta[11][ Reconcile::META_STATE ] );
		$this->assertSame( '0123456789ab', $this->meta[11][ Reconcile::META_REMOTE_VERSION ] );
	}

	public function test_a_failed_page_leaves_the_cursor_and_the_last_success_alone(): void {
		$this->options[ Change_Poller::OPTION_CURSOR ]  = 'c-1';
		$this->options[ Change_Poller::OPTION_LAST_OK ] = 123;
		$this->http->reply( 503, array( 'error' => 'down' ) );

		$result = $this->poller->run();

		$this->assertFalse( $result['ok'] );
		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( 'c-1', $this->options[ Change_Poller::OPTION_CURSOR ] );
		$this->assertSame( 123, $this->options[ Change_Poller::OPTION_LAST_OK ] );
		$this->assertSame( $result['error'], $this->options[ Change_Poller::OPTION_STATUS ]['error'] );
		$this->assertArrayNotHasKey( Change_Poller::OPTION_LOCK, $this->options );
	}

	public function test_a_failed_catalogue_keeps_the_cursor_so_the_page_is_read_again(): void {
		$this->options[ Change_Poller::OPTION_CURSOR ] = 'c-1';
		$this->copy( 11 );
		$this->feed( array( 'photos' => array( 'created' => array(), 'updated' => array(), 'deleted' => array( array( 'id' => 'p1', 'gallery_id' => '' ) ) ) ) );
		$this->http->reply( 503, array( 'error' => 'down' ) );

		$result = $this->poller->run();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'c-1', $this->options[ Change_Poller::OPTION_CURSOR ] );
		$this->assertArrayNotHasKey( Reconcile::META_STATE, $this->meta[11] );
	}

	public function test_it_follows_has_more_up_to_twenty_pages(): void {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->feed( array( 'cursor' => 'c-' . $i, 'has_more' => true ) );
		}

		$result = $this->poller->run();

		$this->assertSame( Change_Poller::MAX_PAGES, $result['pages'] );
		$this->assertCount( 20, $this->http->requests );
		$this->assertSame( 'c-19', $this->options[ Change_Poller::OPTION_CURSOR ] );
		$this->assertTrue( $result['ok'] );
	}

	public function test_it_stops_when_the_time_budget_is_used(): void {
		$poller = new Change_Poller(
			$this->api,
			new Photo_Catalogue( $this->api, new Settings(), $this->clock() ),
			function () {
				$this->tick += 8;
				return $this->tick;
			}
		);
		for ( $i = 0; $i < 10; $i++ ) {
			$this->feed( array( 'cursor' => 'c-' . $i, 'has_more' => true ) );
		}

		$result = $poller->run();

		$this->assertLessThan( 10, $result['pages'] );
		$this->assertGreaterThan( 0, $result['pages'] );
		$this->assertTrue( $result['ok'] );
	}

	public function test_a_second_run_does_nothing_while_the_lock_is_held(): void {
		$this->options[ Change_Poller::OPTION_LOCK ] = $this->tick;

		$result = $this->poller->run();

		$this->assertTrue( $result['busy'] );
		$this->assertCount( 0, $this->http->requests );
		$this->assertArrayHasKey( Change_Poller::OPTION_LOCK, $this->options );
	}

	public function test_an_abandoned_lock_is_replaced(): void {
		$this->options[ Change_Poller::OPTION_LOCK ] = $this->tick - Change_Poller::LOCK_TTL - 1;
		$this->feed();

		$result = $this->poller->run();

		$this->assertFalse( $result['busy'] );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_a_resync_stores_the_new_cursor_first_and_schedules_the_batches(): void {
		$this->copy( 11 );
		$this->feed( array( 'cursor' => 'fresh', 'resync' => true ) );

		$result = $this->poller->run();

		$this->assertTrue( $result['resync'] );
		$this->assertSame( 'fresh', $this->options[ Change_Poller::OPTION_CURSOR ] );
		$this->assertSame( 0, $this->options[ Change_Poller::OPTION_RESYNC ] );
		$this->assertSame( array( Change_Poller::BATCH_HOOK ), $this->single_events );
		$this->assertCount( 1, $this->http->requests, 'The listing waits for the batch event.' );
	}

	public function test_the_resync_batches_reconcile_every_copy_against_the_complete_lists(): void {
		for ( $i = 1; $i <= 60; $i++ ) {
			$this->copy( 100 + $i, 'p' . $i );
		}
		$this->copy( 11, 'gone' );
		$this->options[ Change_Poller::OPTION_RESYNC ] = 0;
		$photos                                        = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$photos[] = $this->photo( 'p' . $i );
		}
		$this->library( $photos );
		$this->http->reply( 200, array( $this->gallery() ) );
		$this->http->reply( 200, array( $this->gallery() ) );
		$this->http->reply( 200, array( 'photos' => array() ) );

		$this->poller->run_batch();

		$this->assertSame( Change_Poller::BATCH_SIZE, $this->options[ Change_Poller::OPTION_RESYNC ] );
		$this->assertSame( array( Change_Poller::BATCH_HOOK ), $this->single_events );

		$this->poller->run_batch();

		$this->assertArrayNotHasKey( Change_Poller::OPTION_RESYNC, $this->options );
		$this->assertSame( $this->now, $this->options[ Change_Poller::OPTION_LAST_OK ] );
		$this->assertSame( Reconcile::STATE_MISSING, $this->meta[11][ Reconcile::META_STATE ] );
		$this->assertSame( Reconcile::REASON_UNKNOWN, $this->meta[11][ Reconcile::META_REASON ] );
		$this->assertArrayNotHasKey( Reconcile::META_STATE, $this->meta[101] );
	}

	public function test_a_resync_against_an_account_that_lists_none_of_the_copies_pauses(): void {
		$this->copy( 11 );
		$this->options[ Change_Poller::OPTION_RESYNC ] = 0;
		$this->library( array( $this->photo( 'someone-else' ) ) );

		$this->poller->run_batch();

		$this->assertTrue( $this->poller->status()['paused'] );
		$this->assertArrayNotHasKey( Change_Poller::OPTION_RESYNC, $this->options );
		$this->assertArrayNotHasKey( Reconcile::META_STATE, $this->meta[11] );

		$this->tick += 3600;
		$count       = count( $this->http->requests );
		$result      = $this->poller->run();
		$this->assertTrue( $result['paused'] );
		$this->assertCount( $count, $this->http->requests, 'A paused poll makes no request.' );
	}

	public function test_resuming_skips_the_account_check_and_starts_from_a_fresh_cursor(): void {
		$this->copy( 11 );
		$this->options[ Change_Poller::OPTION_CURSOR ] = 'old';
		$this->options[ Change_Poller::OPTION_STATUS ] = array( 'paused' => true );

		$this->poller->resume();

		$this->assertFalse( $this->poller->status()['paused'] );
		$this->assertArrayNotHasKey( Change_Poller::OPTION_CURSOR, $this->options );

		$this->options[ Change_Poller::OPTION_RESYNC ] = 0;
		$this->library( array( $this->photo( 'someone-else' ) ) );
		$this->http->reply( 200, array( $this->gallery() ) );
		$this->http->reply( 200, array( 'photos' => array() ) );
		$this->poller->run_batch();

		$this->assertFalse( $this->poller->status()['paused'] );
		$this->assertSame( Reconcile::STATE_MISSING, $this->meta[11][ Reconcile::META_STATE ] );
	}

	public function test_a_gallery_that_stopped_serving_is_found_without_a_feed_entry(): void {
		$this->copy( 11 );
		$this->feed();
		$this->http->reply( 200, array( $this->gallery( false ) ) );
		$this->library( array() );

		$this->poller->run();

		$this->assertSame( Reconcile::STATE_MISSING, $this->meta[11][ Reconcile::META_STATE ] );
		$this->assertSame( Reconcile::REASON_UNAVAILABLE, $this->meta[11][ Reconcile::META_REASON ] );
	}

	public function test_a_quiet_poll_makes_no_catalogue_request(): void {
		$this->copy( 11 );
		$this->feed();
		$this->http->reply( 200, array( $this->gallery() ) );

		$this->poller->run();

		$this->assertCount( 2, $this->http->requests );
	}

	public function test_it_runs_while_the_media_source_setting_is_off(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		$this->copy( 11 );
		$this->feed( array( 'photos' => array( 'created' => array(), 'updated' => array(), 'deleted' => array( array( 'id' => 'p1', 'gallery_id' => '' ) ) ) ) );
		$this->library( array() );
		$this->http->reply( 200, array( $this->gallery() ) );

		$this->poller->run();

		$this->assertSame( Reconcile::STATE_MISSING, $this->meta[11][ Reconcile::META_STATE ] );
	}

	public function test_a_file_the_site_uploaded_is_not_watched(): void {
		$this->copy( 11, 'p1', array( '_profotograaf_origin' => 'upload' ) );
		$this->feed( array( 'photos' => array( 'created' => array(), 'updated' => array(), 'deleted' => array( array( 'id' => 'p1', 'gallery_id' => '' ) ) ) ) );

		$this->poller->run();

		$this->assertCount( 1, $this->http->requests );
		$this->assertArrayNotHasKey( Reconcile::META_STATE, $this->meta[11] );
	}

	public function test_forget_removes_every_record_of_earlier_runs(): void {
		foreach ( array( Change_Poller::OPTION_CURSOR, Change_Poller::OPTION_LAST_OK, Change_Poller::OPTION_STATUS, Change_Poller::OPTION_LOCK, Change_Poller::OPTION_RESYNC ) as $option ) {
			$this->options[ $option ] = 'x';
		}

		Change_Poller::forget();

		$this->assertSame( array( 'profotograaf_connection', Settings::OPTION ), array_keys( $this->options ) );
	}

	public function test_the_module_schedules_hourly_only_while_connected(): void {
		defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
		$scheduled = array();
		$cleared   = array();
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $when, $recurrence, $hook ) use ( &$scheduled ) {
				$scheduled[] = array( $recurrence, $hook );
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
				return 1;
			}
		);
		$module = new Change_Poll();
		$module->register( $this->plugin );

		$module->sync_schedule();
		$this->assertSame( array( array( 'hourly', Change_Poll::HOOK ) ), $scheduled );
		$this->assertSame( array(), $cleared );

		unset( $this->options['profotograaf_connection'] );
		$module->sync_schedule();
		$this->assertSame( array( Change_Poll::HOOK, Change_Poller::BATCH_HOOK ), $cleared );
	}

	public function test_the_module_runs_with_the_media_source_off_and_not_while_disconnected(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		$module                            = new Change_Poll();
		$module->register( $this->plugin );
		$this->feed();

		$module->run();
		$this->assertCount( 1, $this->http->requests );

		unset( $this->options['profotograaf_connection'] );
		$module->run();
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_disconnecting_removes_the_events_and_the_cursor(): void {
		$cleared = array();
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
				return 1;
			}
		);
		$this->options[ Change_Poller::OPTION_CURSOR ]  = 'c-1';
		$this->options[ Change_Poller::OPTION_LAST_OK ] = 5;
		$module = new Change_Poll();
		$module->register( $this->plugin );

		$module->on_disconnected();

		$this->assertSame( array( Change_Poll::HOOK, Change_Poller::BATCH_HOOK ), $cleared );
		$this->assertArrayNotHasKey( Change_Poller::OPTION_CURSOR, $this->options );
		$this->assertArrayNotHasKey( Change_Poller::OPTION_LAST_OK, $this->options );
	}

	public function test_the_poll_event_records_the_cron_heartbeat_before_it_runs(): void {
		$module = new Change_Poll();
		$module->register( $this->plugin );

		$this->assertSame( 1, has_action( Change_Poll::HOOK, 'Profotograaf\Cron_Health->beat()' ) );
		$this->assertSame( 10, has_action( Change_Poll::HOOK, 'Profotograaf\Modules\Change_Poll->run()' ) );
		$this->assertSame( 10, has_action( Change_Poller::BATCH_HOOK, 'Profotograaf\Modules\Change_Poll->run_batch()' ) );
	}
}
