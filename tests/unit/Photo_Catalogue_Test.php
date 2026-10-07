<?php
/**
 * Photo catalogue tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Logger;
use Profotograaf\Modules\Photo_Catalogue_Refresh;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Settings;

class Photo_Catalogue_Test extends Gallery_Test_Case {

	private Photo_Catalogue $catalogue;

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		$this->connect();
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		$this->catalogue                   = new Photo_Catalogue( $this->api, new Settings(), $this->clock() );
	}

	protected function tearDown(): void {
		Logger::configure( null );
		parent::tearDown();
	}

	/**
	 * A photo as the platform lists it.
	 *
	 * @param string $id    Photo id.
	 * @param array  $extra Fields to add or replace.
	 * @return array<string,mixed>
	 */
	private function photo( string $id, array $extra = array() ): array {
		return array_merge(
			array(
				'id'            => $id,
				'width'         => 1600,
				'height'        => 1067,
				'title'         => 'Photo ' . $id,
				'caption'       => '',
				'alt'           => '',
				'gallery_id'    => 'g-1',
				'gallery_title' => 'Spring wedding',
				'thumbnail_url' => 'https://profotograaf.nl/share/img/a/thumb-aaaaaaaaaaaa.jpg',
				'full_url'      => 'https://profotograaf.nl/share/img/a/web-0123456789ab.jpg',
				'images'        => array(
					array(
						'variant' => 'thumb',
						'url'     => 'https://profotograaf.nl/share/img/a/thumb-bbbbbbbbbbbb.jpg',
					),
					array(
						'variant' => 'web',
						'url'     => 'https://profotograaf.nl/share/img/a/web-cccccccccccc.jpg',
					),
				),
			),
			$extra
		);
	}

	/**
	 * Queues one page of the library-wide list.
	 *
	 * @param int $count  Photos on this page.
	 * @param int $total  Total the platform reports.
	 * @param int $start  First photo number.
	 */
	private function page_of( int $count, int $total, int $start = 0 ): void {
		$photos = array();
		for ( $i = $start; $i < $start + $count; $i++ ) {
			$photos[] = $this->photo( 'p-' . $i );
		}
		$this->http->reply(
			200,
			array(
				'photos' => $photos,
				'total'  => $total,
				'limit'  => 200,
				'offset' => $start,
			)
		);
	}

	public function test_it_pages_through_the_platform_list_until_total(): void {
		$this->page_of( 200, 450 );
		$this->page_of( 200, 450, 200 );
		$this->page_of( 50, 450, 400 );

		$result = $this->catalogue->refresh();

		$this->assertCount( 450, $result['photos'] );
		$this->assertFalse( $result['stale'] );
		$this->assertSame( $this->now, $result['fetched_at'] );
		$this->assertCount( 3, $this->http->requests );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/photos?limit=200&offset=0', $this->http->requests[0]['url'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/photos?limit=200&offset=200', $this->http->requests[1]['url'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/photos?limit=200&offset=400', $this->http->requests[2]['url'] );
		$this->assertFalse( $this->autoload[ Photo_Catalogue::OPTION ] );
	}

	public function test_it_stops_at_the_cap_and_logs_what_it_dropped(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->page_of( 200, 2300, $i * 200 );
		}

		$result = $this->catalogue->refresh();

		$this->assertCount( 2000, $result['photos'] );
		$this->assertSame( 300, $result['dropped'] );
		$this->assertCount( 10, $this->http->requests );
		$messages = array_column( Logger::entries(), 'message' );
		$this->assertContains( 'The photo catalogue hit its size limit and left photos out.', $messages );
	}

	public function test_rows_are_normalised_with_safe_defaults(): void {
		$this->http->reply(
			200,
			array(
				'photos' => array(
					$this->photo( 'p-1' ),
					array(
						'id'     => 'p-2',
						'images' => array(
							array(
								'variant' => 'web',
								'url'     => 'https://profotograaf.nl/share/img/z/web-zzzzzzzzzzzz.jpg',
							),
						),
					),
					array( 'id' => 'p-3' ),
					array( 'title' => 'no id' ),
					'junk',
				),
				'total'  => 5,
			)
		);

		$photos = $this->catalogue->refresh()['photos'];

		$this->assertCount( 3, $photos );
		$this->assertSame(
			array(
				'id'            => 'p-1',
				'gallery_id'    => 'g-1',
				'gallery_title' => 'Spring wedding',
				'title'         => 'Photo p-1',
				'caption'       => '',
				'alt'           => '',
				'width'         => 1600,
				'height'        => 1067,
				'thumb_url'     => 'https://profotograaf.nl/share/img/a/thumb-bbbbbbbbbbbb.jpg',
				'web_url'       => 'https://profotograaf.nl/share/img/a/web-0123456789ab.jpg',
				'version'       => '0123456789ab',
			),
			$photos[0]
		);
		$this->assertSame( 'https://profotograaf.nl/share/img/z/web-zzzzzzzzzzzz.jpg', $photos[1]['thumb_url'] );
		$this->assertSame( 'https://profotograaf.nl/share/img/z/web-zzzzzzzzzzzz.jpg', $photos[1]['web_url'] );
		$this->assertSame( 'zzzzzzzzzzzz', $photos[1]['version'] );
		$this->assertSame(
			array( '', '', '', '', '', 0, 0, '', '', '' ),
			array_values( array_diff_key( $photos[2], array( 'id' => 1 ) ) )
		);
	}

	public function test_the_thumb_falls_back_to_thumbnail_url(): void {
		$this->http->reply( 200, array( 'photos' => array( $this->photo( 'p-1', array( 'images' => array() ) ) ), 'total' => 1 ) );

		$row = $this->catalogue->refresh()['photos'][0];

		$this->assertSame( 'https://profotograaf.nl/share/img/a/thumb-aaaaaaaaaaaa.jpg', $row['thumb_url'] );
	}

	public function test_a_failing_platform_serves_the_stored_copy_as_stale_and_logs_it(): void {
		$this->page_of( 2, 2 );
		$this->catalogue->refresh();
		$this->http->reply( 500, array( 'code' => 'internal' ) );

		$result = $this->catalogue->refresh();

		$this->assertTrue( $result['stale'] );
		$this->assertCount( 2, $result['photos'] );
		$this->assertTrue( $this->catalogue->get()['stale'] );
		$this->assertContains( 'The photo catalogue could not be refreshed.', array_column( Logger::entries(), 'message' ) );
	}

	public function test_a_later_success_clears_the_stale_flag(): void {
		$this->page_of( 2, 2 );
		$this->catalogue->refresh();
		$this->http->reply( 500, array( 'code' => 'internal' ) );
		$this->catalogue->refresh();
		$this->page_of( 3, 3 );

		$result = $this->catalogue->refresh();

		$this->assertFalse( $result['stale'] );
		$this->assertCount( 3, $result['photos'] );
	}

	public function test_a_failure_without_a_stored_copy_returns_the_error(): void {
		$this->http->reply( 500, array( 'code' => 'internal' ) );

		$this->assertInstanceOf( \WP_Error::class, $this->catalogue->get() );
		$this->assertArrayNotHasKey( Photo_Catalogue::OPTION, $this->options );
	}

	public function test_get_serves_the_stored_copy_without_a_request(): void {
		$this->page_of( 2, 2 );
		$this->catalogue->get();
		$again = $this->catalogue->get();

		$this->assertCount( 2, $again['photos'] );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_an_empty_account_gives_an_empty_catalogue(): void {
		$this->http->reply( 200, array( 'photos' => array(), 'total' => 0 ) );

		$result = $this->catalogue->get();

		$this->assertSame( array(), $result['photos'] );
		$this->assertFalse( $result['stale'] );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 0, Photo_Catalogue::page( $result['photos'] )['total'] );
		$this->assertSame( array(), Photo_Catalogue::search( $result['photos'], 'x' ) );
	}

	public function test_nothing_happens_while_the_media_source_is_off(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		$this->page_of( 2, 2 );

		$this->assertSame( array(), $this->catalogue->get()['photos'] );
		$this->assertSame( array(), $this->catalogue->refresh()['photos'] );
		$this->assertSame( array(), $this->http->requests );
		$this->assertArrayNotHasKey( Photo_Catalogue::OPTION, $this->options );
	}

	public function test_search_matches_title_caption_alt_and_gallery_title_case_insensitively(): void {
		$photos = array(
			Photo_Catalogue::normalise( $this->photo( 'a', array( 'title' => 'Bride in Garden' ) ) ),
			Photo_Catalogue::normalise( $this->photo( 'b', array( 'caption' => 'First DANCE' ) ) ),
			Photo_Catalogue::normalise( $this->photo( 'c', array( 'alt' => 'Rings on a cushion' ) ) ),
			Photo_Catalogue::normalise( $this->photo( 'd', array( 'gallery_title' => 'Autumn Portraits' ) ) ),
		);

		$this->assertSame( array( 'a' ), array_column( Photo_Catalogue::search( $photos, 'GARDEN' ), 'id' ) );
		$this->assertSame( array( 'b' ), array_column( Photo_Catalogue::search( $photos, 'first dance' ), 'id' ) );
		$this->assertSame( array( 'c' ), array_column( Photo_Catalogue::search( $photos, 'cushion' ), 'id' ) );
		$this->assertSame( array( 'd' ), array_column( Photo_Catalogue::search( $photos, 'autumn' ), 'id' ) );
		$this->assertSame( array( 'a', 'b', 'c' ), array_column( Photo_Catalogue::search( $photos, 'spring' ), 'id' ) );
		$this->assertSame( array(), array_column( Photo_Catalogue::search( $photos, 'nothing' ), 'id' ) );
		$this->assertCount( 4, Photo_Catalogue::search( $photos, '   ' ) );
	}

	public function test_page_slices_and_reports_totals(): void {
		$photos = array();
		for ( $i = 1; $i <= 45; $i++ ) {
			$photos[] = array( 'id' => 'p-' . $i );
		}

		$first = Photo_Catalogue::page( $photos );
		$last  = Photo_Catalogue::page( $photos, 3 );
		$past  = Photo_Catalogue::page( $photos, 9, 20 );

		$this->assertCount( 20, $first['items'] );
		$this->assertSame( 45, $first['total'] );
		$this->assertSame( 3, $first['total_pages'] );
		$this->assertSame( 'p-41', $last['items'][0]['id'] );
		$this->assertCount( 5, $last['items'] );
		$this->assertSame( array(), $past['items'] );
		$this->assertSame( 1, Photo_Catalogue::page( $photos, 0, 0 )['page'] );
		$this->assertSame( 1, Photo_Catalogue::page( $photos, 1, 0 )['per_page'] );
		$this->assertSame( 200, Photo_Catalogue::page( $photos, 1, 9999 )['per_page'] );
	}

	public function test_the_cron_event_is_scheduled_only_while_the_setting_is_on(): void {
		defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
		$scheduled = array();
		$cleared   = array();
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $when, $recurrence, $hook ) use ( &$scheduled ) {
				$scheduled[] = $hook;
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
				return 1;
			}
		);
		$module = new Photo_Catalogue_Refresh();
		$module->register( $this->plugin );

		$module->sync_schedule();
		$this->assertSame( array( Photo_Catalogue_Refresh::HOOK ), $scheduled );
		$this->assertSame( array(), $cleared );

		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		$module->sync_schedule();
		$this->assertSame( array( Photo_Catalogue_Refresh::HOOK ), $scheduled );
		$this->assertSame( array( Photo_Catalogue_Refresh::HOOK ), $cleared );
	}

	public function test_the_cron_run_refreshes_only_while_the_setting_is_on(): void {
		$module = new Photo_Catalogue_Refresh();
		$module->register( $this->plugin );
		$this->page_of( 1, 1 );

		$module->run();
		$this->assertCount( 1, $this->http->requests );
		$this->assertCount( 1, $this->options[ Photo_Catalogue::OPTION ]['photos'] );

		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		$module->run();
		$this->assertCount( 1, $this->http->requests );
	}
}
