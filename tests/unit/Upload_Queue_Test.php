<?php
/**
 * Upload queue tests, with a faked HTTP layer and in-memory post meta.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Leads\Queue;
use Profotograaf\Settings;
use Profotograaf\Upload_Links;
use Profotograaf\Upload_Queue;

class Upload_Queue_Test extends Wp_Test_Case {

	private Fake_Transport $http;

	private Upload_Queue $queue;

	private Upload_Links $links;

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Scheduled single events: hook => time.
	 *
	 * @var array<string,int>
	 */
	private array $scheduled = array();

	private string $file = '';

	private string $title = 'Bride';

	private string $mime = 'image/jpeg';

	protected function setUp(): void {
		parent::setUp();
		$this->options[ Settings::OPTION ] = array( 'media_upload' => true );
		$this->connect( 900, 'access-1', 'refresh-1', 'galleries:read leads:write galleries:embed galleries:write' );

		$this->file = (string) tempnam( sys_get_temp_dir(), 'pfq' );
		file_put_contents( $this->file, Location_Stripper_Test::jpeg( Location_Stripper_Test::tiff() ) );

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
			function ( $args ) {
				$clause = $args['meta_query'][0];
				$ids    = array();
				foreach ( $this->meta as $id => $meta ) {
					if ( ! array_key_exists( $clause['key'], $meta ) ) {
						continue;
					}
					if ( isset( $clause['value'] ) && $meta[ $clause['key'] ] !== $clause['value'] ) {
						continue;
					}
					$ids[] = $id;
				}
				sort( $ids );
				return $ids;
			}
		);
		Functions\when( 'get_post' )->alias(
			function () {
				$post               = new \WP_Post();
				$post->post_title   = $this->title;
				$post->post_excerpt = 'First dance';
				return $post;
			}
		);
		Functions\when( 'get_post_mime_type' )->alias( fn() => $this->mime );
		Functions\when( 'wp_get_original_image_path' )->alias( fn() => $this->file );
		Functions\when( 'get_attached_file' )->alias( fn() => $this->file );
		Functions\when( 'wp_basename' )->alias( fn( $path ) => basename( $path ) );
		Functions\when( 'wp_tempnam' )->alias( fn() => (string) tempnam( sys_get_temp_dir(), 'pfq-out' ) );
		Functions\when( 'wp_delete_file' )->alias(
			function ( $file ) {
				is_file( $file ) && unlink( $file );
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias( fn( $hook ) => $this->scheduled[ $hook ] ?? false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $time, $hook ) {
				$this->scheduled[ $hook ] = $time;
				return true;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->http  = new Fake_Transport();
		$connection  = new Connection();
		$api         = new Api_Client( $connection, $this->http, $this->clock() );
		$this->links = new Upload_Links();
		$this->links->add( 'g-1', 'Spring wedding', 5, $this->now );
		$this->queue = new Upload_Queue( new Settings(), $this->links, $api, $connection, $this->clock() );
	}

	protected function tearDown(): void {
		is_file( $this->file ) && unlink( $this->file );
		parent::tearDown();
	}

	/**
	 * Moves the clock and keeps the access token current, so no refresh request joins the script.
	 *
	 * @param int $seconds Seconds to move.
	 */
	private function advance( int $seconds ): void {
		$this->now += $seconds;
		$this->options['profotograaf_connection']['expires_at'] = $this->now + 900;
	}

	private function uploaded_reply(): void {
		$this->http->reply(
			202,
			array(
				'id'         => 'p-9',
				'gallery_id' => 'g-1',
				'filename'   => basename( $this->file ),
				'status'     => 'uploaded',
			)
		);
	}

	private function queued( int $id = 40 ): void {
		$this->assertTrue( $this->queue->assign( $id, 'g-1' ) );
	}

	public function test_assign_queues_a_file_for_a_linked_gallery_and_schedules_a_run(): void {
		$this->queued();

		$this->assertSame( 'queued', $this->queue->state( 40 ) );
		$this->assertSame( 'g-1', $this->meta[40]['_profotograaf_upload_gallery'] );
		$this->assertSame( $this->now, $this->scheduled[ Upload_Queue::HOOK ] );
		$this->assertSame( array(), $this->http->requests, 'Assigning sends nothing.' );
	}

	public function test_assign_refuses_what_a_person_may_not_send(): void {
		$cases = array(
			'unlinked gallery' => array( 'g-other', 'profotograaf_upload_no_link' ),
		);
		foreach ( $cases as $label => $case ) {
			$result = $this->queue->assign( 40, $case[0] );
			$this->assertInstanceOf( \WP_Error::class, $result, $label );
			$this->assertSame( $case[1], $result->get_error_code(), $label );
		}

		$this->mime = 'video/mp4';
		$this->assertSame( 'profotograaf_upload_type', $this->queue->assign( 40, 'g-1' )->get_error_code() );

		$this->mime                               = 'image/jpeg';
		$this->meta[41]['_profotograaf_photo_id'] = 'p-1';
		$this->assertSame( 'profotograaf_upload_known', $this->queue->assign( 41, 'g-1' )->get_error_code() );

		$this->options[ Settings::OPTION ] = array( 'media_upload' => false );
		$this->assertSame( 'profotograaf_upload_off', $this->queue->assign( 40, 'g-1' )->get_error_code() );
		$this->assertSame( '', $this->queue->state( 40 ) );
		$this->assertArrayNotHasKey( Upload_Queue::HOOK, $this->scheduled );
	}

	public function test_a_disabled_link_cannot_be_chosen(): void {
		$this->links->disable( 'g-1' );

		$this->assertSame( 'profotograaf_upload_no_link', $this->queue->assign( 40, 'g-1' )->get_error_code() );
	}

	public function test_a_run_sends_the_file_without_gps_and_then_the_text(): void {
		$this->queued();
		$this->uploaded_reply();
		$this->http->reply( 200, array( 'id' => 'p-9', 'title' => 'Bride' ) );

		$this->queue->run();

		$upload = $this->http->requests[0];
		$this->assertSame( 'POST', $upload['method'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries/g-1/photos', $upload['url'] );
		$this->assertStringContainsString( 'name="file"; filename="' . basename( $this->file ) . '"', (string) $upload['body'] );
		$this->assertStringContainsString( 'image/jpeg', (string) $upload['body'] );
		$this->assertStringContainsString( "Canon\0", (string) $upload['body'], 'The rest of the EXIF data stays.' );
		$this->assertStringNotContainsString( 'LATITUDE', (string) $upload['body'], 'The GPS position is removed.' );
		$this->assertStringContainsString( 'LATITUDE', (string) file_get_contents( $this->file ), 'The file on this site is untouched.' );

		$patch = $this->http->requests[1];
		$this->assertSame( 'PATCH', $patch['method'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/photos/p-9', $patch['url'] );
		$this->assertSame( array( 'title' => 'Bride', 'caption' => 'First dance' ), $this->http->body( 1 ), 'Empty fields are not sent after the upload.' );

		$this->assertSame( 'uploaded', $this->queue->state( 40 ) );
		$this->assertSame( 'p-9', $this->meta[40]['_profotograaf_photo_id'] );
		$this->assertSame( 'g-1', $this->meta[40]['_profotograaf_gallery_id'] );
		$this->assertSame( 'upload', $this->meta[40]['_profotograaf_origin'] );
		$this->assertSame( $this->now, $this->meta[40]['_profotograaf_uploaded_at'] );
		$this->assertArrayNotHasKey( '_profotograaf_text_push', $this->meta[40] );
		$this->assertSame( sha1( (string) json_encode( array( 'Bride', 'First dance', '' ) ) ), $this->meta[40]['_profotograaf_text_hash'] );
	}

	public function test_a_run_does_nothing_while_upload_is_off_or_the_site_is_not_connected(): void {
		$this->queued();
		$this->options[ Settings::OPTION ] = array( 'media_upload' => false );
		$this->queue->run();
		$this->options[ Settings::OPTION ] = array( 'media_upload' => true );
		unset( $this->options['profotograaf_connection'] );
		$this->queue->run();

		$this->assertSame( array(), $this->http->requests );
	}

	public function test_files_wait_while_the_token_lacks_the_write_scope(): void {
		$this->connect();
		$this->queued();

		$this->queue->run();

		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( 'queued', $this->queue->state( 40 ) );
	}

	public function test_a_403_keeps_the_file_queued_for_the_reconnect(): void {
		$this->queued();
		$this->http->reply( 403, array( 'error' => 'forbidden' ) );

		$this->queue->run();

		$this->assertSame( 'queued', $this->queue->state( 40 ) );
		$this->assertSame( 0, $this->meta[40]['_profotograaf_upload_attempts'] );
		$this->assertTrue( ( new Connection() )->needs_write_reconnect( true ) );
		$this->queue->run();
		$this->assertCount( 1, $this->http->requests, 'No new request while the reconnect is pending.' );
	}

	public function test_storage_full_is_final_for_the_file(): void {
		$this->queued();
		$this->http->reply( 413, array( 'error' => 'quota' ) );

		$this->queue->run();

		$this->assertSame( 'failed', $this->queue->state( 40 ) );
		$this->assertStringContainsString( 'storage is full', $this->queue->error( 40 ) );
		$this->queue->run();
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_an_unknown_gallery_switches_the_link_off(): void {
		$this->queued();
		$this->http->reply( 404, array( 'error' => 'not found' ) );

		$this->queue->run();

		$this->assertSame( 'failed', $this->queue->state( 40 ) );
		$this->assertTrue( $this->links->find( 'g-1' )['disabled'] );
	}

	public function test_a_rejected_type_is_final_for_the_file(): void {
		$this->queued();
		$this->http->reply( 400, array( 'error' => 'type' ) );

		$this->queue->run();

		$this->assertSame( 'failed', $this->queue->state( 40 ) );
		$this->assertStringContainsString( 'does not accept', $this->queue->error( 40 ) );
	}

	public function test_a_removed_link_fails_the_file_without_a_request(): void {
		$this->queued();
		$this->links->remove( 'g-1' );

		$this->queue->run();

		$this->assertSame( 'failed', $this->queue->state( 40 ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_a_missing_file_and_a_too_large_file_fail_without_a_request(): void {
		$this->queued();
		unlink( $this->file );
		$this->queue->run();
		$this->assertSame( 'failed', $this->queue->state( 40 ) );

		file_put_contents( $this->file, str_repeat( 'x', 100 ) );
		\Brain\Monkey\Filters\expectApplied( 'profotograaf_upload_max_bytes' )->andReturn( 10 );
		$this->queued( 41 );
		$this->queue->run();

		$this->assertSame( 'failed', $this->queue->state( 41 ) );
		$this->assertStringContainsString( 'too large', $this->queue->error( 41 ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_a_server_error_retries_with_backoff_and_looks_for_the_file_first(): void {
		$this->queued();
		$this->http->reply( 503, array( 'error' => 'down' ) );
		$this->queue->run();

		$this->assertSame( 'queued', $this->queue->state( 40 ) );
		$this->assertSame( 1, $this->meta[40]['_profotograaf_upload_attempts'] );
		$this->assertSame( '1', $this->meta[40]['_profotograaf_upload_check'] );
		$this->assertSame( $this->now + Queue::backoff( 1 ), $this->meta[40]['_profotograaf_upload_next_at'] );

		$this->queue->run();
		$this->assertCount( 1, $this->http->requests, 'Nothing is sent before the retry time.' );

		$this->now += Queue::backoff( 1 );
		$this->http->reply( 200, array( 'photos' => array(), 'total' => 0 ) );
		$this->uploaded_reply();
		$this->http->reply( 200, array( 'id' => 'p-9' ) );
		$this->queue->run();

		$this->assertSame( 'GET', $this->http->requests[1]['method'] );
		$this->assertStringContainsString( '/api/v1/embed/galleries/g-1/photos?limit=200&q=', $this->http->requests[1]['url'] );
		$this->assertSame( 'POST', $this->http->requests[2]['method'] );
		$this->assertSame( 'uploaded', $this->queue->state( 40 ) );
		$this->assertSame( 2, $this->meta[40]['_profotograaf_upload_attempts'] );
	}

	public function test_a_file_that_the_lookup_finds_is_not_sent_twice(): void {
		$this->queued();
		$this->http->fail( new \WP_Error( 'http_request_failed', 'timeout' ) );
		$this->queue->run();
		$this->assertSame( '1', $this->meta[40]['_profotograaf_upload_check'] );

		$this->advance( 3600 );
		$this->http->reply( 200, array( 'photos' => array( array( 'id' => 'p-7', 'title' => pathinfo( $this->file, PATHINFO_FILENAME ) ) ) ) );
		$this->http->reply( 200, array( 'id' => 'p-7' ) );
		$this->queue->run();

		$this->assertSame( 'uploaded', $this->queue->state( 40 ), $this->queue->error( 40 ) );
		$this->assertSame( 'p-7', $this->meta[40]['_profotograaf_photo_id'] );
		$this->assertSame( 'GET', $this->http->requests[1]['method'] );
		$this->assertSame( 'PATCH', $this->http->requests[2]['method'] );
		$this->assertCount( 3, $this->http->requests, 'The file was not posted again.' );
	}

	public function test_an_inconclusive_lookup_fails_the_file_with_a_warning(): void {
		$this->queued();
		$this->http->reply( 503, array( 'error' => 'down' ) );
		$this->queue->run();

		$this->advance( 3600 );
		$this->http->reply( 404, array( 'error' => 'not embeddable' ) );
		$this->queue->run();

		$this->assertSame( 'failed', $this->queue->state( 40 ) );
		$this->assertStringContainsString( 'may already exist', $this->queue->error( 40 ) );
		$this->assertCount( 2, $this->http->requests, 'No second upload.' );
	}

	public function test_the_queue_gives_up_after_the_last_attempt(): void {
		$this->queued();
		$this->meta[40]['_profotograaf_upload_attempts'] = Upload_Queue::MAX_ATTEMPTS - 1;
		$this->http->reply( 503, array( 'error' => 'down' ) );

		$this->queue->run();

		$this->assertSame( 'failed', $this->queue->state( 40 ) );
		$this->assertStringContainsString( 'may exist', $this->queue->error( 40 ) );
	}

	public function test_a_file_left_in_uploading_is_checked_after_the_stale_period(): void {
		$this->meta[40] = array(
			'_profotograaf_upload_state'      => 'uploading',
			'_profotograaf_upload_gallery'    => 'g-1',
			'_profotograaf_upload_started_at' => $this->now,
			'_profotograaf_upload_attempts'   => 1,
		);
		$this->queue->run();
		$this->assertSame( array(), $this->http->requests, 'A request in progress is left alone.' );

		$this->advance( Upload_Queue::STALE_AFTER );
		$this->http->reply( 200, array( 'photos' => array( array( 'id' => 'p-3', 'filename' => basename( $this->file ) ) ) ) );
		$this->http->reply( 200, array( 'id' => 'p-3' ) );
		$this->queue->run();

		$this->assertSame( 'uploaded', $this->queue->state( 40 ) );
		$this->assertSame( 'GET', $this->http->requests[0]['method'] );
	}

	public function test_the_queue_works_one_file_per_run_and_schedules_the_next(): void {
		$this->queued( 40 );
		$this->queued( 41 );
		unset( $this->scheduled[ Upload_Queue::HOOK ] );
		$this->uploaded_reply();
		$this->http->reply( 200, array( 'id' => 'p-9' ) );

		$this->queue->run();

		$this->assertSame( 'uploaded', $this->queue->state( 40 ) );
		$this->assertSame( 'queued', $this->queue->state( 41 ) );
		$this->assertSame( $this->now + Upload_Queue::RUN_GAP, $this->scheduled[ Upload_Queue::HOOK ] );
	}

	public function test_a_held_lock_stops_a_second_run(): void {
		$this->queued();
		$this->options[ Upload_Queue::LOCK ] = $this->now;

		$this->queue->run();

		$this->assertSame( array(), $this->http->requests );

		$this->options[ Upload_Queue::LOCK ] = $this->now - Upload_Queue::LOCK_TTL;
		$this->http->reply( 503, array( 'error' => 'down' ) );
		$this->queue->run();
		$this->assertCount( 1, $this->http->requests, 'A lock past its TTL is replaced.' );
		$this->assertArrayNotHasKey( Upload_Queue::LOCK, $this->options );
	}

	public function test_an_imported_photo_caught_by_the_automatic_send_is_dropped_from_the_queue(): void {
		$this->queued();
		$this->meta[40]['_profotograaf_photo_id'] = 'p-1';

		$this->queue->run();

		$this->assertSame( '', $this->queue->state( 40 ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_assign_automatic_follows_the_auto_link_for_users_who_may_upload(): void {
		$this->queue->assign_automatic( 40 );
		$this->assertSame( '', $this->queue->state( 40 ), 'No link sends automatically yet.' );

		$this->links->set_auto( 'g-1', true );
		$this->queue->assign_automatic( 40 );
		$this->assertSame( 'queued', $this->queue->state( 40 ) );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->queue->assign_automatic( 41 );
		$this->assertSame( '', $this->queue->state( 41 ) );
	}

	public function test_a_text_edit_of_a_sent_file_is_pushed_in_full(): void {
		$this->queued();
		$this->uploaded_reply();
		$this->http->reply( 200, array( 'id' => 'p-9' ) );
		$this->queue->run();
		$this->queue->note_text_edit( 40 );
		$this->assertArrayNotHasKey( '_profotograaf_text_push', $this->meta[40], 'Unchanged text queues nothing.' );

		$this->title                                = 'Bride and groom';
		$this->meta[40]['_wp_attachment_image_alt'] = '';
		unset( $this->scheduled[ Upload_Queue::HOOK ] );
		$this->queue->note_text_edit( 40 );
		$this->assertSame( '1', $this->meta[40]['_profotograaf_text_push'] );
		$this->assertSame( $this->now + 30, $this->scheduled[ Upload_Queue::HOOK ] );

		$this->now += 30;
		$this->http->reply( 200, array( 'id' => 'p-9' ) );
		$this->queue->run();

		$this->assertSame( 'PATCH', $this->http->requests[2]['method'] );
		$this->assertSame( array( 'title' => 'Bride and groom', 'caption' => 'First dance', 'alt' => '' ), $this->http->body( 2 ), 'An emptied field clears it on the platform.' );
		$this->assertArrayNotHasKey( '_profotograaf_text_push', $this->meta[40] );
	}

	public function test_a_text_edit_of_a_file_that_was_not_sent_by_this_site_queues_nothing(): void {
		$this->meta[40] = array(
			'_profotograaf_origin'       => 'import',
			'_profotograaf_upload_state' => 'uploaded',
		);
		$this->title    = 'Changed';
		$this->queue->note_text_edit( 40 );

		$this->assertArrayNotHasKey( '_profotograaf_text_push', $this->meta[40] );
	}

	public function test_a_text_push_that_fails_is_retried_and_then_dropped(): void {
		$this->queued();
		$this->uploaded_reply();
		$this->http->reply( 404, array( 'error' => 'processing' ) );
		$this->queue->run();

		$this->assertSame( 'uploaded', $this->queue->state( 40 ), 'The file is on Profotograaf whatever happens to its text.' );
		$this->assertSame( 'initial', $this->meta[40]['_profotograaf_text_push'] );
		$this->assertSame( 1, $this->meta[40]['_profotograaf_text_attempts'] );
		$this->assertSame( $this->now + Queue::backoff( 1 ), $this->meta[40]['_profotograaf_upload_next_at'] );

		$this->meta[40]['_profotograaf_text_attempts'] = Upload_Queue::MAX_ATTEMPTS - 1;
		$this->advance( 86400 );
		$this->http->reply( 404, array( 'error' => 'gone' ) );
		$this->queue->run();

		$this->assertArrayNotHasKey( '_profotograaf_text_push', $this->meta[40] );
	}

	public function test_resume_schedules_a_run_only_when_files_wait(): void {
		$this->queue->resume();
		$this->assertArrayNotHasKey( Upload_Queue::HOOK, $this->scheduled );

		$this->queued();
		unset( $this->scheduled[ Upload_Queue::HOOK ] );
		$this->queue->resume();
		$this->assertSame( $this->now, $this->scheduled[ Upload_Queue::HOOK ] );
	}

	public function test_counts_report_queued_failed_and_text_pushes(): void {
		$this->meta[40] = array( '_profotograaf_upload_state' => 'queued' );
		$this->meta[41] = array( '_profotograaf_upload_state' => 'failed' );
		$this->meta[42] = array( '_profotograaf_text_push' => '1' );

		$this->assertSame(
			array(
				'queued'    => 1,
				'uploading' => 0,
				'failed'    => 1,
				'text'      => 1,
			),
			$this->queue->counts()
		);
	}
}
