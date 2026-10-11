<?php
/**
 * Change feed sync of virtual attachments (issue #153).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Logger;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Settings;
use Profotograaf\Virtual_Attachments;
use Profotograaf\Virtual_Sync;

class Virtual_Sync_Test extends Gallery_Test_Case {

	private const OLD_WEB = 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg';
	private const NEW_WEB = 'https://profotograaf.nl/share/img/a1/web-bbbbbbbbbbbb.jpg';

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	private Virtual_Attachments $virtual;

	private Virtual_Sync $sync;

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		$this->connect();
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		Filters\expectApplied( Virtual_Attachments::FILTER )->zeroOrMoreTimes()->andReturn( true );
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
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'get_posts' )->alias(
			function ( $args ) {
				$ids = array();
				foreach ( $this->meta as $id => $meta ) {
					if ( (string) ( $meta[ $args['meta_key'] ] ?? '' ) === (string) $args['meta_value'] ) {
						$ids[] = $id;
					}
				}
				return $ids;
			}
		);

		$settings      = new Settings();
		$catalogue     = new Photo_Catalogue( $this->api, $settings, $this->clock() );
		$this->virtual = new Virtual_Attachments( $settings, new Photo_Importer( $settings, null, $catalogue ), $catalogue, $this->api );
		$this->sync    = new Virtual_Sync( $this->api, $catalogue, $this->virtual );
	}

	protected function tearDown(): void {
		Logger::configure( null );
		parent::tearDown();
	}

	/**
	 * A virtual attachment of photo $photo_id in gallery $gallery_id.
	 *
	 * @param int    $id         Attachment id.
	 * @param string $photo_id   Photo id.
	 * @param string $gallery_id Gallery id.
	 * @param bool   $gone       Whether it carries the gone mark.
	 */
	private function kept( int $id, string $photo_id = 'p1', string $gallery_id = 'g1', bool $gone = false ): void {
		$this->meta[ $id ] = array(
			Virtual_Attachments::META_VIRTUAL => '1',
			Photo_Importer::META_PHOTO_ID     => $photo_id,
			Photo_Importer::META_GALLERY_ID   => $gallery_id,
			Photo_Importer::META_VERSION      => 'aaaaaaaaaaaa',
			Virtual_Attachments::META_URLS    => array(
				'thumb' => self::OLD_WEB,
				'web'   => self::OLD_WEB,
			),
			'_wp_attached_file'               => 'profotograaf-virtual/' . $photo_id . '/web-aaaaaaaaaaaa.jpg',
		);
		if ( $gone ) {
			$this->meta[ $id ][ Virtual_Attachments::META_GONE ] = 1;
		}
	}

	/**
	 * Queues one feed page.
	 *
	 * @param array<string,mixed> $galleries Gallery lists to set.
	 * @param array<string,mixed> $photos    Photo lists to set.
	 * @param array<string,mixed> $over      Top-level fields to replace.
	 */
	private function feed( array $galleries = array(), array $photos = array(), array $over = array() ): void {
		$this->http->reply(
			200,
			array_merge(
				array(
					'cursor'    => 'c-2',
					'has_more'  => false,
					'resync'    => false,
					'galleries' => array_merge(
						array(
							'created'     => array(),
							'updated'     => array(),
							'deleted'     => array(),
							'unavailable' => array(),
							'available'   => array(),
						),
						$galleries
					),
					'photos'    => array_merge(
						array(
							'created' => array(),
							'updated' => array(),
							'deleted' => array(),
						),
						$photos
					),
				),
				$over
			)
		);
	}

	/**
	 * Queues the library list with the given photos, all at the new version.
	 *
	 * @param array<int,string> $ids     Photo ids.
	 * @param string            $gallery Gallery of every photo.
	 */
	private function library( array $ids, string $gallery = 'g1' ): void {
		$photos = array();
		foreach ( $ids as $id ) {
			$photos[] = array(
				'id'         => $id,
				'gallery_id' => $gallery,
				'title'      => 'Photo ' . $id,
				'width'      => 1600,
				'height'     => 1067,
				'full_url'   => self::NEW_WEB,
			);
		}
		$this->http->reply(
			200,
			array(
				'photos' => $photos,
				'total'  => count( $photos ),
			)
		);
	}

	private function gone( int $id ): bool {
		return '' !== (string) ( $this->meta[ $id ][ Virtual_Attachments::META_GONE ] ?? '' );
	}

	public function test_it_reads_the_feed_with_the_stored_cursor_and_keeps_the_new_one(): void {
		$this->kept( 10 );
		$this->options[ Virtual_Sync::OPTION_CURSOR ] = 'c-1';
		$this->feed( array(), array( 'deleted' => array( array( 'id' => 'p-other', 'gallery_id' => '' ) ) ) );

		$result = $this->sync->run();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/changes?limit=500&since=c-1', $this->http->requests[0]['url'] );
		$this->assertCount( 1, $this->http->requests, 'A page that names no kept photo needs no photo list.' );
		$this->assertSame( 'c-2', $this->options[ Virtual_Sync::OPTION_CURSOR ] );
		$this->assertFalse( $this->autoload[ Virtual_Sync::OPTION_CURSOR ] );
		$this->assertFalse( $this->gone( 10 ) );
		$this->assertIsInt( $this->options[ Virtual_Sync::OPTION_LAST_OK ] );
	}

	public function test_a_photo_deleted_from_the_library_marks_its_attachment_gone_once(): void {
		$this->kept( 10, 'p1' );
		$this->kept( 11, 'p2' );
		Actions\expectDone( 'profotograaf_virtual_attachment_gone' )->once()->with( 10 );
		$deleted = array( 'deleted' => array( array( 'id' => 'p1', 'gallery_id' => '' ) ) );
		$this->feed( array(), $deleted );
		$this->feed( array(), $deleted, array( 'cursor' => 'c-3' ) );

		$first = $this->sync->run();
		$stamp = $this->meta[10][ Virtual_Attachments::META_GONE ];
		$again = $this->sync->run();

		$this->assertSame( 1, $first['gone'] );
		$this->assertSame( 0, $again['gone'], 'A repeated entry changes nothing.' );
		$this->assertSame( $stamp, $this->meta[10][ Virtual_Attachments::META_GONE ] );
		$this->assertFalse( $this->gone( 11 ) );
		$this->assertSame( self::OLD_WEB, $this->meta[10][ Virtual_Attachments::META_URLS ]['web'], 'The stored URLs stay.' );
	}

	public function test_a_photo_taken_out_of_its_own_gallery_is_gone_and_out_of_another_gallery_is_not(): void {
		$this->kept( 10, 'p1', 'g1' );
		$this->kept( 11, 'p2', 'g1' );
		$this->feed(
			array(),
			array(
				'deleted' => array(
					array(
						'id'         => 'p1',
						'gallery_id' => 'g1',
					),
					array(
						'id'         => 'p2',
						'gallery_id' => 'g9',
					),
				),
			)
		);

		$this->sync->run();

		$this->assertTrue( $this->gone( 10 ) );
		$this->assertFalse( $this->gone( 11 ) );
	}

	public function test_a_deleted_gallery_marks_every_attachment_in_it_gone(): void {
		$this->kept( 10, 'p1', 'g1' );
		$this->kept( 11, 'p2', 'g1' );
		$this->kept( 12, 'p3', 'g2' );
		$this->feed( array( 'deleted' => array( 'g1' ) ) );

		$result = $this->sync->run();

		$this->assertSame( 2, $result['gone'] );
		$this->assertTrue( $this->gone( 10 ) );
		$this->assertTrue( $this->gone( 11 ) );
		$this->assertFalse( $this->gone( 12 ) );
	}

	public function test_an_unavailable_gallery_marks_its_attachments_gone(): void {
		$this->kept( 10, 'p1', 'g1' );
		$this->kept( 12, 'p3', 'g2' );
		$this->feed(
			array(
				'unavailable' => array(
					array(
						'id'     => 'g1',
						'reason' => 'password',
					),
				),
			)
		);

		$this->sync->run();

		$this->assertTrue( $this->gone( 10 ) );
		$this->assertFalse( $this->gone( 12 ) );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_a_gallery_available_again_clears_the_gone_mark_from_the_photo_list(): void {
		$this->kept( 10, 'p1', 'g1', true );
		$this->feed( array( 'available' => array( 'g1' ) ) );
		$this->library( array( 'p1' ) );

		$result = $this->sync->run();

		$this->assertSame( 1, $result['updated'] );
		$this->assertFalse( $this->gone( 10 ) );
		$this->assertSame( self::NEW_WEB, $this->meta[10][ Virtual_Attachments::META_URLS ]['web'] );
		$this->assertSame( 'bbbbbbbbbbbb', $this->meta[10][ Photo_Importer::META_VERSION ] );
	}

	public function test_an_updated_photo_gets_fresh_urls_and_gallery(): void {
		$this->kept( 10, 'p1', 'g1' );
		$this->feed(
			array(),
			array(
				'updated' => array(
					array(
						'id'         => 'p1',
						'gallery_id' => 'g5',
					),
				),
			)
		);
		$this->library( array( 'p1' ), 'g5' );

		$this->sync->run();

		$this->assertSame( self::NEW_WEB, $this->meta[10][ Virtual_Attachments::META_URLS ]['web'] );
		$this->assertSame( 'g5', $this->meta[10][ Photo_Importer::META_GALLERY_ID ] );
		$this->assertStringStartsWith( 'https://profotograaf.nl/api/v1/embed/photos?', $this->http->requests[1]['url'] );
	}

	public function test_a_photo_deleted_and_added_again_on_one_page_stays_kept(): void {
		$this->kept( 10, 'p1', 'g1' );
		Actions\expectDone( 'profotograaf_virtual_attachment_gone' )->never();
		$entry = array(
			'id'         => 'p1',
			'gallery_id' => 'g1',
		);
		$this->feed(
			array(),
			array(
				'deleted' => array( $entry ),
				'created' => array( $entry ),
			)
		);
		$this->library( array( 'p1' ) );

		$this->sync->run();

		$this->assertFalse( $this->gone( 10 ) );
	}

	public function test_resync_runs_the_full_list_and_then_stores_the_new_cursor(): void {
		$this->kept( 10, 'p1', 'g1', true );
		$this->kept( 11, 'p2', 'g1' );
		$this->options[ Virtual_Sync::OPTION_CURSOR ] = 'too-old';
		$this->feed( array(), array(), array( 'resync' => true, 'cursor' => 'fresh' ) );
		$this->library( array( 'p1' ) );

		$result = $this->sync->run();

		$this->assertTrue( $result['resync'] );
		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 1, $result['gone'] );
		$this->assertFalse( $this->gone( 10 ), 'A listed photo loses its gone mark.' );
		$this->assertTrue( $this->gone( 11 ), 'A photo missing from the full list is gone.' );
		$this->assertSame( 'fresh', $this->options[ Virtual_Sync::OPTION_CURSOR ] );
		$this->assertCount( 2, $this->http->requests, 'The resync page ends the run.' );
	}

	public function test_without_a_cursor_the_first_run_is_a_resync(): void {
		$this->kept( 10 );
		$this->feed( array(), array(), array( 'resync' => true, 'cursor' => 'fresh' ) );
		$this->library( array( 'p1' ) );

		$this->sync->run();

		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/changes?limit=500', $this->http->requests[0]['url'] );
		$this->assertSame( 'fresh', $this->options[ Virtual_Sync::OPTION_CURSOR ] );
	}

	public function test_a_resync_whose_photo_list_fails_changes_nothing(): void {
		$this->kept( 10 );
		$this->options[ Virtual_Sync::OPTION_CURSOR ] = 'c-1';
		$this->feed( array(), array(), array( 'resync' => true, 'cursor' => 'fresh' ) );
		$this->http->reply( 503, array( 'error' => 'down' ) );
		Actions\expectDone( 'profotograaf_virtual_attachment_gone' )->never();

		$result = $this->sync->run();

		$this->assertFalse( $result['ok'] );
		$this->assertFalse( $this->gone( 10 ) );
		$this->assertSame( 'c-1', $this->options[ Virtual_Sync::OPTION_CURSOR ] );
		$this->assertArrayNotHasKey( Virtual_Sync::OPTION_LAST_OK, $this->options );
	}

	public function test_a_resync_with_a_stale_photo_list_changes_nothing(): void {
		$this->kept( 10 );
		$this->options[ Photo_Catalogue::OPTION ]     = array(
			'photos'     => array(),
			'stale'      => false,
			'fetched_at' => 1,
			'dropped'    => 0,
		);
		$this->options[ Virtual_Sync::OPTION_CURSOR ] = 'c-1';
		$this->feed( array(), array(), array( 'resync' => true, 'cursor' => 'fresh' ) );
		$this->http->reply( 503, array( 'error' => 'down' ) );

		$result = $this->sync->run();

		$this->assertSame( 'profotograaf_catalogue_stale', $result['error'] );
		$this->assertFalse( $this->gone( 10 ), 'The stored list is empty, but stale, so nothing is marked.' );
		$this->assertSame( 'c-1', $this->options[ Virtual_Sync::OPTION_CURSOR ] );
	}

	/**
	 * A feed failure of each kind.
	 *
	 * @return array<string,array{0:int,1:array<string,mixed>|null}>
	 */
	public static function failed_feeds(): array {
		return array(
			'server error'   => array( 503, array( 'error' => 'down' ) ),
			'no cursor'      => array( 200, array( 'resync' => false ) ),
			'not json'       => array( 200, null ),
			'token rejected' => array( 403, array( 'error' => 'forbidden' ) ),
		);
	}

	/**
	 * A failed feed leaves every attachment and the cursor as they were.
	 *
	 * @dataProvider failed_feeds
	 * @param int                       $status HTTP status.
	 * @param array<string,mixed>|null $body   Answer.
	 */
	public function test_a_failed_feed_changes_nothing( int $status, ?array $body ): void {
		$this->kept( 10 );
		$this->options[ Virtual_Sync::OPTION_CURSOR ] = 'c-1';
		$this->http->reply( $status, $body );
		Actions\expectDone( 'profotograaf_virtual_attachment_gone' )->never();

		$result = $this->sync->run();

		$this->assertFalse( $result['ok'] );
		$this->assertNotSame( '', $result['error'] );
		$this->assertFalse( $this->gone( 10 ) );
		$this->assertSame( 'c-1', $this->options[ Virtual_Sync::OPTION_CURSOR ] );
		$this->assertSame( self::OLD_WEB, $this->meta[10][ Virtual_Attachments::META_URLS ]['web'] );
	}

	public function test_a_page_whose_photo_list_fails_marks_nothing_and_is_read_again(): void {
		$this->kept( 10, 'p1', 'g1' );
		$this->kept( 11, 'p2', 'g2' );
		$this->options[ Virtual_Sync::OPTION_CURSOR ] = 'c-1';
		$this->feed(
			array( 'deleted' => array( 'g2' ) ),
			array(
				'updated' => array(
					array(
						'id'         => 'p1',
						'gallery_id' => 'g1',
					),
				),
			)
		);
		$this->http->reply( 503, array( 'error' => 'down' ) );

		$result = $this->sync->run();

		$this->assertFalse( $result['ok'] );
		$this->assertFalse( $this->gone( 11 ), 'The deleted gallery waits for the page to be read again.' );
		$this->assertSame( 'c-1', $this->options[ Virtual_Sync::OPTION_CURSOR ] );
	}

	public function test_it_follows_has_more_up_to_twenty_pages(): void {
		$this->kept( 10 );
		for ( $page = 1; $page <= 25; $page++ ) {
			$this->feed( array(), array(), array( 'cursor' => 'c-' . $page, 'has_more' => true ) );
		}

		$result = $this->sync->run();

		$this->assertSame( Virtual_Sync::MAX_PAGES, $result['pages'] );
		$this->assertSame( 'c-20', $this->options[ Virtual_Sync::OPTION_CURSOR ] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/changes?limit=500&since=c-1', $this->http->requests[1]['url'] );
	}

	public function test_without_virtual_attachments_it_makes_no_request_and_drops_the_cursor(): void {
		$this->options[ Virtual_Sync::OPTION_CURSOR ] = 'c-1';

		$result = $this->sync->run();

		$this->assertSame( 0, $result['pages'] );
		$this->assertSame( array(), $this->http->requests );
		$this->assertArrayNotHasKey( Virtual_Sync::OPTION_CURSOR, $this->options );
	}

	public function test_the_cron_callback_syncs_from_the_change_feed(): void {
		$this->kept( 10, 'p1', 'g1' );
		$this->feed( array( 'deleted' => array( 'g1' ) ) );

		$this->virtual->run_sync();

		$this->assertStringContainsString( '/api/v1/embed/changes', $this->http->requests[0]['url'] );
		$this->assertTrue( $this->gone( 10 ) );
	}

	public function test_the_cron_callback_does_nothing_without_an_api_client(): void {
		$this->kept( 10 );
		$settings = new Settings();
		$virtual  = new Virtual_Attachments( $settings, new Photo_Importer( $settings ), new Photo_Catalogue( $this->api, $settings ) );

		$virtual->run_sync();

		$this->assertSame( array(), $this->http->requests );
	}

	public function test_the_full_sync_marks_nothing_gone_from_a_truncated_list(): void {
		$this->kept( 10, 'p1' );
		$this->kept( 11, 'p2' );

		$result = $this->virtual->sync(
			array(
				'photos'  => array(),
				'stale'   => false,
				'dropped' => 3,
			)
		);

		$this->assertSame( 0, $result['gone'] );
		$this->assertFalse( $this->gone( 10 ) );
		$this->assertFalse( $this->gone( 11 ) );
	}

	public function test_forget_removes_the_cursor_and_the_last_success(): void {
		$this->options[ Virtual_Sync::OPTION_CURSOR ]  = 'c-1';
		$this->options[ Virtual_Sync::OPTION_LAST_OK ] = 5;

		Virtual_Sync::forget();

		$this->assertArrayNotHasKey( Virtual_Sync::OPTION_CURSOR, $this->options );
		$this->assertArrayNotHasKey( Virtual_Sync::OPTION_LAST_OK, $this->options );
	}
}
