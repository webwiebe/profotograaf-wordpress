<?php
/**
 * Virtual attachment prototype tests (spike #104).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Logger;
use Profotograaf\Modules\Media_Import_Screen;
use Profotograaf\Modules\Media_Source_Rest;
use Profotograaf\Modules\Virtual_Attachments as Virtual_Module;
use Profotograaf\Modules\Virtual_Convert;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Photo_Rest;
use Profotograaf\Settings;
use Profotograaf\Settings_Schema;
use Profotograaf\Virtual_Attachments;

class Virtual_Attachments_Test extends Gallery_Test_Case {

	private Virtual_Attachments $virtual;

	/**
	 * Capabilities the current user has.
	 *
	 * @var array<int,string>
	 */
	private array $caps = array( 'upload_files', 'edit_post' );

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Attachment metadata (`_wp_attachment_metadata`) by id.
	 *
	 * @var array<int,mixed>
	 */
	private array $attachment_meta = array();

	/**
	 * Arguments of every wp_insert_attachment call.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $inserted = array();

	/**
	 * URLs passed to download_url.
	 *
	 * @var array<int,string>
	 */
	private array $downloads = array();

	/**
	 * Calls that changed a file, in order.
	 *
	 * @var array<int,string>
	 */
	private array $writes = array();

	private string $tmp = '';

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		$this->connect();
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );

		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
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
		Functions\when( 'wp_insert_attachment' )->alias(
			function ( $args, $file ) {
				$this->inserted[]                       = $args + array( '_file' => $file );
				$id                                     = 100 + count( $this->inserted );
				$this->meta[ $id ]['_wp_attached_file'] = $file;
				return $id;
			}
		);
		Functions\when( 'wp_update_attachment_metadata' )->alias(
			function ( $id, $data ) {
				$this->attachment_meta[ $id ] = $data;
				$this->writes[]               = 'metadata';
				return true;
			}
		);
		Functions\when( 'wp_get_attachment_metadata' )->alias( fn( $id ) => $this->attachment_meta[ $id ] ?? false );
		Functions\when( 'get_posts' )->alias(
			function ( $args ) {
				$ids = array();
				foreach ( $this->meta as $id => $meta ) {
					if ( isset( $args['meta_query'] ) ) {
						$match = true;
						foreach ( $args['meta_query'] as $clause ) {
							$has    = isset( $meta[ $clause['key'] ] );
							$match  = $match && $has;
							$values = isset( $clause['value'] ) ? (array) $clause['value'] : array();
							if ( $has && 'EXISTS' !== ( $clause['compare'] ?? '' ) ) {
								$match = $match && in_array( (string) $meta[ $clause['key'] ], $values, true );
							}
						}
						if ( $match ) {
							$ids[] = $id;
						}
						continue;
					}
					if ( isset( $meta[ $args['meta_key'] ] ) && (string) $meta[ $args['meta_key'] ] === (string) $args['meta_value'] ) {
						$ids[] = $id;
					}
				}
				return $ids;
			}
		);
		Functions\when( 'media_handle_sideload' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

		$this->tmp = (string) tempnam( sys_get_temp_dir(), 'pfva' );
		$tmp       = $this->tmp;
		Functions\when( 'download_url' )->alias(
			function ( $url ) use ( $tmp ) {
				$this->downloads[] = $url;
				return $tmp;
			}
		);
		Functions\when( 'wp_handle_sideload' )->justReturn(
			array(
				'file' => '/uploads/2026/10/profotograaf-p-1.jpg',
				'type' => 'image/jpeg',
			)
		);
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn(
			array(
				'file'   => '2026/10/profotograaf-p-1.jpg',
				'width'  => 1600,
				'height' => 1067,
				'sizes'  => array(),
			)
		);
		Functions\when( 'wp_get_original_image_path' )->justReturn( false );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'get_attached_file' )->alias( fn( $id ) => '/uploads/' . ( $this->meta[ $id ]['_wp_attached_file'] ?? '' ) );
		Functions\when( 'update_attached_file' )->alias(
			function () {
				$this->writes[] = 'file';
				return true;
			}
		);
		Functions\when( 'wp_update_post' )->justReturn( 1 );
		Functions\when( 'wp_delete_attachment_files' )->justReturn( true );
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
	}

	protected function tearDown(): void {
		if ( is_file( $this->tmp ) ) {
			unlink( $this->tmp );
		}
		Logger::configure( null );
		parent::tearDown();
	}

	/**
	 * Switches the hidden flag on for the rest of the test.
	 */
	private function flag_on(): void {
		Filters\expectApplied( Virtual_Attachments::FILTER )->zeroOrMoreTimes()->andReturn( true );
	}

	/**
	 * Builds the prototype with the flag on and its filters registered.
	 */
	private function enable(): void {
		$this->flag_on();
		$settings      = new Settings();
		$catalogue     = new Photo_Catalogue( $this->api, $settings, $this->clock() );
		$importer      = new Photo_Importer( $settings, null, $catalogue );
		$this->virtual = new Virtual_Attachments( $settings, $importer, $catalogue );
		$this->virtual->register();
	}

	/**
	 * A catalogue row.
	 *
	 * @param array<string,mixed> $overrides Fields to change.
	 * @return array<string,mixed>
	 */
	private function photo( array $overrides = array() ): array {
		return $overrides + array(
			'id'            => 'p-1',
			'gallery_id'    => 'g-1',
			'gallery_title' => 'Spring',
			'title'         => 'Bride',
			'caption'       => 'First dance',
			'alt'           => '',
			'width'         => 3000,
			'height'        => 2000,
			'thumb_url'     => 'https://profotograaf.nl/share/img/a1/thumb-aaaaaaaaaaaa.jpg',
			'web_url'       => 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg',
			'version'       => 'aaaaaaaaaaaa',
		);
	}

	/**
	 * Stores a catalogue option.
	 *
	 * @param array<int,array<string,mixed>> $photos Rows.
	 * @param bool                           $stale  Whether the copy is stale.
	 */
	private function store_catalogue( array $photos, bool $stale = false ): void {
		$this->options[ Photo_Catalogue::OPTION ] = array(
			'photos'     => $photos,
			'stale'      => $stale,
			'fetched_at' => 1,
			'dropped'    => 0,
		);
	}

	private function create_one(): int {
		$id = $this->virtual->create( $this->photo() );
		$this->assertIsInt( $id );
		return $id;
	}

	private const HOOKED = array( 'wp_get_attachment_url', 'image_downsize', 'wp_calculate_image_srcset', 'wp_prepare_attachment_for_js' );

	public function test_the_flag_is_off_by_default(): void {
		$this->assertFalse( Virtual_Attachments::enabled() );
	}

	public function test_the_filter_switches_the_flag_on(): void {
		$this->flag_on();
		$this->assertTrue( Virtual_Attachments::enabled() );
	}

	public function test_a_filter_returning_a_truthy_non_boolean_does_not_switch_it_on(): void {
		Filters\expectApplied( Virtual_Attachments::FILTER )->andReturn( 'yes' );
		$this->assertFalse( Virtual_Attachments::enabled() );
	}

	public function test_with_the_flag_off_the_module_registers_no_hook(): void {
		( new Virtual_Module() )->register( $this->plugin );

		foreach ( self::HOOKED as $hook ) {
			$this->assertFalse( has_filter( $hook ), $hook );
		}
		$this->assertFalse( has_action( Virtual_Attachments::SYNC_HOOK ) );
		$this->assertFalse( has_action( 'init' ) );
	}

	public function test_with_the_flag_on_the_module_registers_the_filters(): void {
		$this->flag_on();

		( new Virtual_Module() )->register( $this->plugin );

		foreach ( self::HOOKED as $hook ) {
			$this->assertNotFalse( has_filter( $hook ), $hook );
		}
		$this->assertNotFalse( has_action( Virtual_Attachments::SYNC_HOOK ) );
	}

	public function test_with_the_flag_off_create_refuses_and_writes_nothing(): void {
		$settings      = new Settings();
		$catalogue     = new Photo_Catalogue( $this->api, $settings, $this->clock() );
		$this->virtual = new Virtual_Attachments( $settings, new Photo_Importer( $settings, null, $catalogue ), $catalogue );

		$result = $this->virtual->create( $this->photo() );

		$this->assertSame( 'profotograaf_virtual_off', $result->get_error_code() );
		$this->assertSame( array(), $this->inserted );
	}

	public function test_with_the_flag_off_marked_attachments_keep_their_core_values(): void {
		$settings      = new Settings();
		$catalogue     = new Photo_Catalogue( $this->api, $settings, $this->clock() );
		$this->virtual = new Virtual_Attachments( $settings, new Photo_Importer( $settings, null, $catalogue ), $catalogue );
		$this->meta[7] = array(
			Virtual_Attachments::META_VIRTUAL => '1',
			Virtual_Attachments::META_URLS    => array( 'web' => 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg' ),
		);

		$this->assertFalse( $this->virtual->is_virtual( 7 ) );
		$this->assertSame( 'https://example.test/uploads/x.jpg', $this->virtual->filter_url( 'https://example.test/uploads/x.jpg', 7 ) );
		$this->assertFalse( $this->virtual->filter_downsize( false, 7, 'thumbnail' ) );
		$this->assertSame( array( 'core' ), $this->virtual->filter_srcset( array( 'core' ), array( 10, 10 ), 'x', array( 'width' => 10 ), 7 ) );
	}

	public function test_create_stores_the_photo_without_calling_the_platform(): void {
		$this->enable();
		Functions\expect( 'download_url' )->never();

		$id = $this->create_one();

		$this->assertSame( 101, $id );
		$this->assertSame( 'image/jpeg', $this->inserted[0]['post_mime_type'] );
		$this->assertSame( 'inherit', $this->inserted[0]['post_status'] );
		$this->assertSame( 'Bride', $this->inserted[0]['post_title'] );
		$this->assertSame( 'First dance', $this->inserted[0]['post_excerpt'] );
		$this->assertSame( 'profotograaf-virtual/p-1/web-aaaaaaaaaaaa.jpg', $this->inserted[0]['_file'] );
		$this->assertSame( 'p-1', $this->meta[101]['_profotograaf_photo_id'] );
		$this->assertSame( 'g-1', $this->meta[101]['_profotograaf_gallery_id'] );
		$this->assertSame( 'aaaaaaaaaaaa', $this->meta[101]['_profotograaf_version'] );
		$this->assertSame( 'Bride', $this->meta[101]['_wp_attachment_image_alt'] );
		$this->assertSame( '1', $this->meta[101][ Virtual_Attachments::META_VIRTUAL ] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_create_stores_the_size_of_the_web_variant_and_the_thumbnail_size(): void {
		$this->enable();

		$id = $this->create_one();

		$this->assertSame( 1600, $this->attachment_meta[ $id ]['width'] );
		$this->assertSame( 1067, $this->attachment_meta[ $id ]['height'] );
		$this->assertSame( 'profotograaf-virtual/p-1/web-aaaaaaaaaaaa.jpg', $this->attachment_meta[ $id ]['file'] );
		$this->assertSame( 'thumb-aaaaaaaaaaaa.jpg', $this->attachment_meta[ $id ]['sizes']['thumbnail']['file'] );
		$this->assertSame( 400, $this->attachment_meta[ $id ]['sizes']['thumbnail']['width'] );
	}

	public function test_create_stores_a_filesize_so_the_core_image_editor_finds_one(): void {
		$this->enable();

		$id = $this->create_one();

		$this->assertArrayHasKey( 'filesize', $this->attachment_meta[ $id ] );
		$this->assertSame( 0, $this->attachment_meta[ $id ]['filesize'] );
	}

	public function test_a_small_photo_keeps_its_own_size(): void {
		$this->enable();

		$id = $this->virtual->create( $this->photo( array( 'width' => 800, 'height' => 1200 ) ) );

		$this->assertSame( 800, $this->attachment_meta[ $id ]['width'] );
		$this->assertSame( 1200, $this->attachment_meta[ $id ]['height'] );
	}

	public function test_a_second_create_returns_the_same_attachment(): void {
		$this->enable();
		$first = $this->create_one();

		$this->assertSame( $first, $this->virtual->create( $this->photo() ) );
		$this->assertCount( 1, $this->inserted );
	}

	public function test_create_refuses_a_foreign_host_without_the_upload_capability_and_while_the_media_source_is_off(): void {
		$this->enable();

		$foreign = $this->virtual->create( $this->photo( array( 'web_url' => 'https://evil.example/web.jpg' ) ) );
		$this->assertSame( 'profotograaf_import_host', $foreign->get_error_code() );

		$this->caps = array();
		$this->assertSame( 'profotograaf_import_forbidden', $this->virtual->create( $this->photo() )->get_error_code() );

		$this->caps                        = array( 'upload_files' );
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		$this->assertSame( 'profotograaf_media_source_off', $this->virtual->create( $this->photo() )->get_error_code() );
		$this->assertSame( array(), $this->inserted );
	}

	public function test_create_without_a_thumb_on_the_platform_host_stores_no_thumbnail_size(): void {
		$this->enable();

		$id = $this->virtual->create( $this->photo( array( 'thumb_url' => 'https://evil.example/t.jpg' ) ) );

		$this->assertSame( array(), $this->attachment_meta[ $id ]['sizes'] );
		$this->assertSame( $this->meta[ $id ][ Virtual_Attachments::META_URLS ]['web'], $this->meta[ $id ][ Virtual_Attachments::META_URLS ]['thumb'] );
	}

	public function test_the_url_filter_returns_the_web_url_for_a_virtual_attachment_only(): void {
		$this->enable();
		$id = $this->create_one();

		$this->assertSame( 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg', $this->virtual->filter_url( 'https://example.test/uploads/x.jpg', $id ) );
		$this->assertSame( 'https://example.test/uploads/x.jpg', $this->virtual->filter_url( 'https://example.test/uploads/x.jpg', 999 ) );
	}

	public function test_downsize_picks_the_thumb_for_small_sizes_and_the_web_variant_otherwise(): void {
		$this->enable();
		$id = $this->create_one();

		$this->assertSame(
			array( 'https://profotograaf.nl/share/img/a1/thumb-aaaaaaaaaaaa.jpg', 400, 400, true ),
			$this->virtual->filter_downsize( false, $id, 'thumbnail' )
		);
		$this->assertSame(
			array( 'https://profotograaf.nl/share/img/a1/thumb-aaaaaaaaaaaa.jpg', 400, 400, true ),
			$this->virtual->filter_downsize( false, $id, array( 300, 300 ) )
		);
		$web = array( 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg', 1600, 1067, false );
		$this->assertSame( $web, $this->virtual->filter_downsize( false, $id, 'full' ) );
		$this->assertSame( $web, $this->virtual->filter_downsize( false, $id, 'large' ) );
		$this->assertSame( $web, $this->virtual->filter_downsize( false, $id, array( 1200, 800 ) ) );
		$this->assertFalse( $this->virtual->filter_downsize( false, 999, 'full' ) );
	}

	public function test_the_srcset_holds_the_web_variant_only(): void {
		$this->enable();
		$id   = $this->create_one();
		$meta = $this->attachment_meta[ $id ];

		$sources = $this->virtual->filter_srcset( array( 'core' => 'x' ), array( 1600, 1067 ), 'u', $meta, $id );

		$this->assertSame(
			array(
				1600 => array(
					'url'        => 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg',
					'descriptor' => 'w',
					'value'      => 1600,
				),
			),
			$sources
		);
		$this->assertSame( array( 'core' => 'x' ), $this->virtual->filter_srcset( array( 'core' => 'x' ), array( 1, 1 ), 'u', array( 'width' => 1 ), 999 ) );
	}

	public function test_the_media_modal_learns_that_the_attachment_is_virtual(): void {
		$this->enable();
		$id = $this->create_one();

		$virtual = $this->virtual->filter_js( array( 'id' => $id ), (object) array( 'ID' => $id ), array() );
		$other   = $this->virtual->filter_js( array( 'id' => 5 ), (object) array( 'ID' => 5 ), array() );

		$this->assertTrue( $virtual['profotograafVirtual'] );
		$this->assertArrayNotHasKey( 'profotograafVirtual', $other );
	}

	public function test_no_filter_calls_the_platform(): void {
		$this->enable();
		$id = $this->create_one();
		Functions\expect( 'download_url' )->never();
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_request' )->never();

		$this->virtual->filter_url( 'x', $id );
		$this->virtual->filter_downsize( false, $id, 'full' );
		$this->virtual->filter_srcset( array(), array( 1, 1 ), 'u', $this->attachment_meta[ $id ], $id );
		$this->virtual->filter_js( array(), (object) array( 'ID' => $id ), array() );

		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_convert_to_local_replaces_the_file_in_place_and_drops_the_virtual_marks(): void {
		$this->enable();
		$id         = $this->create_one();
		$this->caps = array( 'upload_files', 'edit_post' );
		$this->store_catalogue( array( $this->photo( array( 'web_url' => 'https://profotograaf.nl/share/img/a1/web-bbbbbbbbbbbb.jpg', 'version' => 'bbbbbbbbbbbb' ) ) ) );
		Actions\expectDone( 'profotograaf_virtual_attachment_converted' )->once()->with( $id );

		$result = $this->virtual->convert_to_local( $id );

		$this->assertSame( $id, $result );
		$this->assertSame( array( 'https://profotograaf.nl/share/img/a1/web-bbbbbbbbbbbb.jpg' ), $this->downloads );
		$this->assertArrayNotHasKey( Virtual_Attachments::META_VIRTUAL, $this->meta[ $id ] );
		$this->assertArrayNotHasKey( Virtual_Attachments::META_URLS, $this->meta[ $id ] );
		$this->assertSame( 'p-1', $this->meta[ $id ]['_profotograaf_photo_id'] );
		$this->assertSame( 'bbbbbbbbbbbb', $this->meta[ $id ]['_profotograaf_version'] );
		$this->assertSame( '2026/10/profotograaf-p-1.jpg', $this->attachment_meta[ $id ]['file'] );
		$this->assertFalse( $this->virtual->is_virtual( $id ) );
		$this->assertSame( 'https://example.test/x.jpg', $this->virtual->filter_url( 'https://example.test/x.jpg', $id ) );
	}

	public function test_convert_to_local_keeps_the_attachment_virtual_when_the_photo_is_gone(): void {
		$this->enable();
		$id = $this->create_one();
		$this->store_catalogue( array() );
		$this->meta[ $id ][ Virtual_Attachments::META_GONE ] = 1700000000;
		Functions\when( 'download_url' )->alias(
			function ( $url ) {
				$this->downloads[] = $url;
				return new \WP_Error( 'http_404', 'Not Found' );
			}
		);
		Actions\expectDone( 'profotograaf_virtual_attachment_converted' )->never();

		$result = $this->virtual->convert_to_local( $id );

		$this->assertSame( 'profotograaf_photo_gone', $result->get_error_code() );
		$this->assertTrue( $this->virtual->is_virtual( $id ) );
		$this->assertSame( array( 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg' ), $this->downloads, 'It tried the stored URL once.' );
		$this->assertSame( array( 'metadata' ), $this->writes, 'Only create() wrote; the failed conversion changed nothing.' );
	}

	public function test_convert_to_local_refuses_an_attachment_that_is_not_virtual(): void {
		$this->enable();
		$this->meta[9] = array( '_profotograaf_photo_id' => 'p-9' );

		$this->assertSame( 'profotograaf_not_virtual', $this->virtual->convert_to_local( 9 )->get_error_code() );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_sync_updates_urls_and_clears_the_gone_mark_of_listed_photos(): void {
		$this->enable();
		$id = $this->create_one();
		$this->meta[ $id ][ Virtual_Attachments::META_GONE ] = 123;
		$new = $this->photo( array( 'web_url' => 'https://profotograaf.nl/share/img/a1/web-bbbbbbbbbbbb.jpg', 'version' => 'bbbbbbbbbbbb' ) );

		$result = $this->virtual->sync(
			array(
				'photos' => array( $new ),
				'stale'  => false,
			)
		);

		$this->assertSame(
			array(
				'updated' => 1,
				'gone'    => 0,
				'skipped' => false,
			),
			$result
		);
		$this->assertSame( 'https://profotograaf.nl/share/img/a1/web-bbbbbbbbbbbb.jpg', $this->meta[ $id ][ Virtual_Attachments::META_URLS ]['web'] );
		$this->assertSame( 'bbbbbbbbbbbb', $this->meta[ $id ]['_profotograaf_version'] );
		$this->assertArrayNotHasKey( Virtual_Attachments::META_GONE, $this->meta[ $id ] );
	}

	public function test_sync_marks_a_missing_photo_gone_once(): void {
		$this->enable();
		$id = $this->create_one();
		Actions\expectDone( 'profotograaf_virtual_attachment_gone' )->once()->with( $id );
		$fresh = array(
			'photos' => array(),
			'stale'  => false,
		);

		$first  = $this->virtual->sync( $fresh );
		$stamp  = $this->meta[ $id ][ Virtual_Attachments::META_GONE ];
		$second = $this->virtual->sync( $fresh );

		$this->assertSame( 1, $first['gone'] );
		$this->assertSame( 0, $second['gone'] );
		$this->assertSame( $stamp, $this->meta[ $id ][ Virtual_Attachments::META_GONE ] );
		$this->assertTrue( $this->virtual->is_virtual( $id ), 'The attachment keeps serving its stored URL.' );
	}

	public function test_sync_does_nothing_with_a_stale_catalogue(): void {
		$this->enable();
		$id = $this->create_one();
		Actions\expectDone( 'profotograaf_virtual_attachment_gone' )->never();

		$result = $this->virtual->sync(
			array(
				'photos' => array(),
				'stale'  => true,
			)
		);

		$this->assertTrue( $result['skipped'] );
		$this->assertArrayNotHasKey( Virtual_Attachments::META_GONE, $this->meta[ $id ] );
	}

	public function test_the_legacy_image_editor_is_refused_for_a_virtual_attachment_only(): void {
		$this->enable();
		$id = $this->create_one();

		$this->assertFalse( $this->virtual->filter_edit_path( '/uploads/x.jpg', $id ) );
		$this->assertSame( '/uploads/x.jpg', $this->virtual->filter_edit_path( '/uploads/x.jpg', 5 ) );
	}

	public function test_the_editor_guard_can_be_switched_off_for_the_compatibility_test(): void {
		$this->enable();
		$id = $this->create_one();
		Filters\expectApplied( Virtual_Attachments::BLOCK_EDITING_FILTER )->andReturn( false );

		$this->assertSame( '/uploads/x.jpg', $this->virtual->filter_edit_path( '/uploads/x.jpg', $id ) );
	}

	public function test_with_the_flag_off_the_editor_path_is_untouched(): void {
		$this->meta[7][ Virtual_Attachments::META_VIRTUAL ] = '1';
		$virtual = new Virtual_Attachments( new Settings(), new Photo_Importer( new Settings() ), null );

		$this->assertSame( '/uploads/x.jpg', $virtual->filter_edit_path( '/uploads/x.jpg', 7 ) );
	}

	/**
	 * Switches the setting "Keep photos on Profotograaf" on, next to the media source.
	 */
	private function setting_on(): void {
		$this->options[ Settings::OPTION ] = array(
			'media_source'        => true,
			'virtual_attachments' => true,
		);
	}

	/**
	 * The photo routes as the Media_Source_Rest module registers them.
	 *
	 * @return array<string,array<string,mixed>> Route arguments by route.
	 */
	private function routes(): array {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $space, $route, $args ) use ( &$routes ) {
				$routes[ $route ] = $args;
			}
		);
		Actions\expectAdded( 'rest_api_init' )->whenHappen( fn( $callback ) => $callback() );
		( new Media_Source_Rest() )->register( $this->plugin );
		return $routes;
	}

	/**
	 * A REST request carrying some params.
	 *
	 * @param array<string,mixed> $params Params.
	 */
	private function request( array $params ): object {
		return new class( $params ) {
			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $params Params.
			 */
			public function __construct( private array $params ) {}

			/**
			 * Param.
			 *
			 * @param string $name Name.
			 */
			public function get_param( $name ) {
				return $this->params[ $name ] ?? null;
			}
		};
	}

	/**
	 * Photo_Rest with virtual attachments, as the module builds it while the setting is on.
	 */
	private function keep_rest(): Photo_Rest {
		$settings      = new Settings();
		$catalogue     = new Photo_Catalogue( $this->api, $settings, $this->clock() );
		$importer      = new Photo_Importer( $settings, null, $catalogue );
		$this->virtual = new Virtual_Attachments( $settings, $importer, $catalogue );
		return new Photo_Rest( $catalogue, $importer, $settings, $this->virtual );
	}

	public function test_the_setting_is_off_by_default_on_the_general_tab(): void {
		$entry = Settings_Schema::entry( 'virtual_attachments' );

		$this->assertNotNull( $entry );
		$this->assertFalse( $entry['default'] );
		$this->assertSame( 'bool', $entry['type'] );
		$this->assertSame( Settings_Schema::TAB_GENERAL, $entry['tab'] );
		$this->assertSame( 'Keep photos on Profotograaf', $entry['label'] );
		$this->assertStringContainsString( 'edge caches', $entry['description'] );
		$this->assertStringContainsString( 'up to a year', $entry['description'] );
		$this->assertFalse( ( new Settings() )->virtual_attachments_enabled() );
	}

	public function test_the_setting_switches_it_on_and_sanitises_like_any_bool(): void {
		$settings = new Settings();

		$this->options[ Settings::OPTION ] = $settings->sanitize( array( 'virtual_attachments' => '1' ) );
		$this->assertTrue( $settings->virtual_attachments_enabled() );
		$this->assertTrue( Virtual_Attachments::enabled() );

		$this->options[ Settings::OPTION ] = $settings->sanitize( array( 'virtual_attachments' => 'junk' ) );
		$this->assertTrue( $settings->virtual_attachments_enabled(), 'An unusable value keeps the saved state.' );

		$this->options[ Settings::OPTION ] = $settings->sanitize( array( '_tab' => 'general' ) );
		$this->assertFalse( $settings->virtual_attachments_enabled(), 'Unchecking the box on the General tab turns it off.' );
		$this->assertFalse( Virtual_Attachments::enabled() );
	}

	public function test_the_filter_can_switch_the_setting_off(): void {
		$this->setting_on();
		Filters\expectApplied( Virtual_Attachments::FILTER )->andReturn( false );

		$this->assertFalse( Virtual_Attachments::enabled() );
	}

	public function test_with_the_setting_on_the_module_registers_the_filters(): void {
		$this->setting_on();

		( new Virtual_Module() )->register( $this->plugin );

		foreach ( self::HOOKED as $hook ) {
			$this->assertNotFalse( has_filter( $hook ), $hook );
		}
	}

	public function test_with_the_setting_off_there_is_no_keep_route(): void {
		$routes = $this->routes();

		$this->assertSame( array( '/photos', '/photos/import', '/photos/reimport' ), array_keys( $routes ) );
	}

	public function test_with_the_setting_on_the_keep_route_needs_upload_files(): void {
		$this->setting_on();

		$routes = $this->routes();

		$this->assertArrayHasKey( '/photos/keep', $routes );
		$this->assertSame( 'POST', $routes['/photos/keep']['methods'] );
		$this->assertTrue( $routes['/photos/keep']['args']['ids']['required'] );
		$check = $routes['/photos/keep']['permission_callback'];
		$this->assertTrue( $check() );
		$this->caps = array( 'edit_posts' );
		$this->assertFalse( $check(), 'Without upload_files the action is refused.' );
		$this->caps                        = array( 'upload_files' );
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		$this->assertFalse( $check(), 'A route registered before the setting went off refuses.' );
	}

	public function test_without_virtual_attachments_photo_rest_refuses_to_keep(): void {
		$settings  = new Settings();
		$catalogue = new Photo_Catalogue( $this->api, $settings, $this->clock() );
		$rest      = new Photo_Rest( $catalogue, new Photo_Importer( $settings, null, $catalogue ), $settings );

		$this->assertFalse( $rest->can_keep() );
		$error = $rest->keep_photos( $this->request( array( 'ids' => array( 'p-1' ) ) ) );
		$this->assertSame( 'profotograaf_virtual_off', $error->get_error_code() );
		$this->assertSame( array(), $this->inserted );
	}

	public function test_keep_creates_virtual_attachments_without_a_download(): void {
		$this->setting_on();
		$this->store_catalogue( array( $this->photo(), $this->photo( array( 'id' => 'p-2', 'title' => 'Rings' ) ) ) );
		Functions\expect( 'download_url' )->never();
		Functions\expect( 'media_handle_sideload' )->never();

		$results = $this->keep_rest()->keep_photos( $this->request( array( 'ids' => array( 'p-1', 'p-2', 'p-404' ) ) ) )['results'];

		$this->assertSame( array( 'p-1', 'p-2', 'p-404' ), array_column( $results, 'id' ) );
		$this->assertSame( 101, $results[0]['attachment_id'] );
		$this->assertSame( 102, $results[1]['attachment_id'] );
		$this->assertStringContainsString( 'not in your Profotograaf library', $results[2]['error'] );
		$this->assertSame( '1', $this->meta[101][ Virtual_Attachments::META_VIRTUAL ] );
		$this->assertSame( '1', $this->meta[102][ Virtual_Attachments::META_VIRTUAL ] );
		$this->assertSame( 'Rings', $this->inserted[1]['post_title'] );
		$this->assertSame( array(), $this->downloads );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_keep_refuses_while_the_media_source_is_off(): void {
		$this->options[ Settings::OPTION ] = array( 'virtual_attachments' => true );

		$error = $this->keep_rest()->keep_photos( $this->request( array( 'ids' => array( 'p-1' ) ) ) );

		$this->assertSame( 'profotograaf_media_source_off', $error->get_error_code() );
		$this->assertSame( array(), $this->inserted );
	}

	public function test_the_photo_list_marks_kept_photos(): void {
		$this->setting_on();
		$this->store_catalogue( array( $this->photo(), $this->photo( array( 'id' => 'p-2' ) ) ) );
		$this->meta[50] = array( Photo_Importer::META_PHOTO_ID => 'p-2' );
		$rest           = $this->keep_rest();
		$this->virtual->create( $this->photo() );

		$items = $rest->list_photos( $this->request( array() ) )['items'];

		$this->assertSame( 101, $items[0]['id'] );
		$this->assertTrue( $items[0]['kept'] );
		$this->assertSame( 50, $items[1]['id'] );
		$this->assertArrayNotHasKey( 'kept', $items[1] );
	}

	public function test_with_the_setting_off_the_photo_list_carries_no_kept_mark(): void {
		$this->store_catalogue( array( $this->photo() ) );
		$this->meta[50] = array(
			Photo_Importer::META_PHOTO_ID     => 'p-1',
			Virtual_Attachments::META_VIRTUAL => '1',
		);
		$settings       = new Settings();
		$catalogue      = new Photo_Catalogue( $this->api, $settings, $this->clock() );
		$rest           = new Photo_Rest( $catalogue, new Photo_Importer( $settings, null, $catalogue ), $settings );

		$items = $rest->list_photos( $this->request( array() ) )['items'];

		$this->assertSame( 50, $items[0]['id'] );
		$this->assertArrayNotHasKey( 'kept', $items[0] );
	}

	/**
	 * The Import from Profotograaf page as the module prints it.
	 */
	private function import_page(): string {
		Functions\when( 'admin_url' )->justReturn( 'https://site.test/wp-admin/' );
		ob_start();
		( new Media_Import_Screen() )->render();
		return (string) ob_get_clean();
	}

	public function test_the_import_page_offers_keep_only_while_the_setting_is_on(): void {
		$this->assertStringNotContainsString( 'data-keep', $this->import_page() );

		$this->setting_on();
		$this->assertStringContainsString( 'data-admin-url="https://site.test/wp-admin/" data-keep="1"', $this->import_page() );
	}

	public function test_a_kept_photo_says_so_in_its_source_field_and_has_no_reimport_button(): void {
		$this->setting_on();
		$this->meta[60] = array(
			Photo_Importer::META_PHOTO_ID     => 'p-1',
			Virtual_Attachments::META_VIRTUAL => '1',
		);
		$screen         = new Media_Import_Screen();
		$screen->register( $this->plugin );

		$html = $screen->source_field( array(), (object) array( 'ID' => 60 ) )['profotograaf_source']['html'];

		$this->assertStringContainsString( 'Kept on Profotograaf.', $html );
		$this->assertStringNotContainsString( 'Re-import', $html );
	}

	// Issue #152: Convert to local.

	private const WEB_A = 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg';

	/**
	 * Stores a virtual attachment directly, as create() leaves it, so a test
	 * can run with the setting off.
	 *
	 * @param int    $id    Attachment id.
	 * @param bool   $gone  Whether the sync marked it gone.
	 * @param string $title Attachment title.
	 */
	private function marked( int $id, bool $gone = false, string $title = 'Bride' ): void {
		$this->meta[ $id ] = array(
			Photo_Importer::META_PHOTO_ID     => 'p-1',
			Photo_Importer::META_GALLERY_ID   => 'g-1',
			Photo_Importer::META_VERSION      => 'aaaaaaaaaaaa',
			Virtual_Attachments::META_VIRTUAL => '1',
			Virtual_Attachments::META_URLS    => array(
				'thumb' => 'https://profotograaf.nl/share/img/a1/thumb-aaaaaaaaaaaa.jpg',
				'web'   => self::WEB_A,
			),
			'_wp_attached_file'               => 'profotograaf-virtual/p-1/web-aaaaaaaaaaaa.jpg',
			'_title'                          => $title,
		);
		if ( $gone ) {
			$this->meta[ $id ][ Virtual_Attachments::META_GONE ] = 1700000000;
		}
	}

	/**
	 * The convert module with the stubs its links and notices need.
	 */
	private function convert_module(): Recording_Virtual_Convert {
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://site.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			function ( ...$args ) {
				if ( is_array( $args[0] ) ) {
					return $args[1] . '?' . http_build_query( $args[0] );
				}
				return $args[2] . ( str_contains( (string) $args[2], '?' ) ? '&' : '?' ) . $args[0] . '=' . $args[1];
			}
		);
		Functions\when( 'wp_nonce_url' )->alias( fn( $url, $action ) => $url . '&_wpnonce=' . $action );
		Functions\when( 'get_the_title' )->alias( fn( $id ) => (string) ( $this->meta[ $id ]['_title'] ?? '' ) );
		Functions\when( 'get_edit_post_link' )->alias( fn( $id ) => 'https://site.test/wp-admin/post.php?post=' . $id . '&action=edit' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'absint' )->alias( fn( $value ) => abs( (int) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
		$module = new Recording_Virtual_Convert();
		$module->register( $this->plugin );
		return $module;
	}

	/**
	 * What a callback printed.
	 *
	 * @param callable $callback Callback.
	 */
	private function printed( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	public function test_convert_to_local_works_after_the_setting_was_switched_off(): void {
		$this->marked( 30 );
		$this->store_catalogue( array( $this->photo() ) );
		$settings  = new Settings();
		$catalogue = new Photo_Catalogue( $this->api, $settings, $this->clock() );
		$virtual   = new Virtual_Attachments( $settings, new Photo_Importer( $settings, null, $catalogue ), $catalogue );
		$this->assertFalse( Virtual_Attachments::enabled() );

		$this->assertSame( 30, $virtual->convert_to_local( 30 ) );

		$this->assertSame( array( self::WEB_A ), $this->downloads );
		$this->assertArrayNotHasKey( Virtual_Attachments::META_VIRTUAL, $this->meta[30] );
		$this->assertArrayNotHasKey( Virtual_Attachments::META_URLS, $this->meta[30] );
		$this->assertSame( array( 'file', 'metadata' ), $this->writes, 'The file is stored and the sizes are generated.' );
	}

	public function test_a_gone_photo_converts_from_its_stored_url_while_profotograaf_still_serves_it(): void {
		$this->enable();
		$this->marked( 31, true );
		$this->store_catalogue( array() );

		$this->assertSame( 31, $this->virtual->convert_to_local( 31 ) );

		$this->assertSame( array( self::WEB_A ), $this->downloads );
		$this->assertArrayNotHasKey( Virtual_Attachments::META_VIRTUAL, $this->meta[31] );
		$this->assertArrayNotHasKey( Virtual_Attachments::META_GONE, $this->meta[31] );
		$this->assertSame( 'g-1', $this->meta[31][ Photo_Importer::META_GALLERY_ID ] );
		$this->assertSame( 'aaaaaaaaaaaa', $this->meta[31][ Photo_Importer::META_VERSION ] );
	}

	public function test_a_gone_photo_that_no_longer_downloads_stays_virtual_with_a_clear_message(): void {
		$this->enable();
		$this->marked( 32, true );
		$this->store_catalogue( array() );
		Functions\when( 'download_url' )->justReturn( new \WP_Error( 'http_404', 'Not Found' ) );

		$error = $this->virtual->convert_to_local( 32 );

		$this->assertSame( 'profotograaf_photo_gone', $error->get_error_code() );
		$this->assertStringContainsString( 'no longer serves this photo', $error->get_error_message() );
		$this->assertSame( '1', $this->meta[32][ Virtual_Attachments::META_VIRTUAL ] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_a_failed_download_of_a_listed_photo_keeps_the_importer_message(): void {
		$this->enable();
		$this->marked( 33 );
		$this->store_catalogue( array( $this->photo() ) );
		Functions\when( 'download_url' )->justReturn( new \WP_Error( 'http_503', 'Down' ) );

		$this->assertSame( 'profotograaf_import_download', $this->virtual->convert_to_local( 33 )->get_error_code() );
		$this->assertSame( '1', $this->meta[33][ Virtual_Attachments::META_VIRTUAL ] );
	}

	public function test_the_convert_module_registers_while_the_setting_is_off(): void {
		$module = $this->convert_module();

		$this->assertNotFalse( has_filter( 'media_row_actions', array( $module, 'row_actions' ) ) );
		$this->assertNotFalse( has_filter( 'bulk_actions-upload', array( $module, 'bulk_actions' ) ) );
		$this->assertNotFalse( has_filter( 'handle_bulk_actions-upload', array( $module, 'handle_bulk' ) ) );
		$this->assertNotFalse( has_filter( 'attachment_fields_to_edit', array( $module, 'convert_field' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_profotograaf_convert_local', array( $module, 'handle_single' ) ) );
		$this->assertContains( 'profotograaf_converted', $module->removable_args( array() ) );
	}

	public function test_the_row_action_is_offered_for_virtual_attachments_to_users_who_may_edit_them(): void {
		$module = $this->convert_module();
		$this->marked( 34 );
		$this->meta[35] = array( Photo_Importer::META_PHOTO_ID => 'p-2' );

		$actions = $module->row_actions( array( 'edit' => 'Edit' ), (object) array( 'ID' => 34 ) );

		$this->assertStringContainsString( 'admin-post.php?action=profotograaf_convert_local&amp;attachment=34&amp;_wpnonce=profotograaf_convert_local_34', $actions['profotograaf_convert'] );
		$this->assertStringContainsString( '>Convert to local</a>', $actions['profotograaf_convert'] );
		$this->assertStringContainsString( 'aria-label="Convert “Bride” to local"', $actions['profotograaf_convert'] );
		$this->assertSame( array( 'edit' => 'Edit' ), $module->row_actions( array( 'edit' => 'Edit' ), (object) array( 'ID' => 35 ) ), 'An import gets no action.' );
		$this->caps = array( 'upload_files' );
		$this->assertArrayNotHasKey( 'profotograaf_convert', $module->row_actions( array(), (object) array( 'ID' => 34 ) ) );
		$this->caps = array( 'edit_post' );
		$this->assertArrayNotHasKey( 'profotograaf_convert', $module->row_actions( array(), (object) array( 'ID' => 34 ) ) );
	}

	public function test_the_details_field_explains_the_state_and_links_the_conversion(): void {
		$module = $this->convert_module();
		$this->marked( 36 );
		$this->marked( 37, true );

		$off = $module->convert_field( array(), (object) array( 'ID' => 36 ) )['profotograaf_convert'];
		$this->assertSame( 'Local copy', $off['label'] );
		$this->assertStringContainsString( 'Keeping photos on Profotograaf is switched off, so this photo shows no image on this site until you convert it to local.', $off['html'] );
		$this->assertStringContainsString( 'profotograaf_convert_local_36', $off['html'] );

		$gone = $module->convert_field( array(), (object) array( 'ID' => 37 ) )['profotograaf_convert']['html'];
		$this->assertStringContainsString( 'Profotograaf no longer lists this photo', $gone );

		$this->flag_on();
		$on = $module->convert_field( array(), (object) array( 'ID' => 36 ) )['profotograaf_convert']['html'];
		$this->assertStringContainsString( 'This site stores no copy of this photo.', $on );

		$this->assertSame( array(), $module->convert_field( array(), (object) array( 'ID' => 99 ) ) );
	}

	public function test_the_bulk_action_shows_while_the_setting_is_on_or_a_marked_attachment_exists(): void {
		$module = $this->convert_module();

		$this->assertSame( array(), $module->bulk_actions( array() ), 'Setting off and nothing marked.' );

		$this->marked( 38 );
		$this->assertSame( array( 'profotograaf_convert_local' => 'Convert to local' ), $module->bulk_actions( array() ) );

		$this->caps = array( 'edit_post' );
		$this->assertSame( array(), $module->bulk_actions( array() ), 'Without upload_files there is no action.' );

		$this->caps = array( 'upload_files', 'edit_post' );
		$this->meta = array();
		$this->flag_on();
		$this->assertArrayHasKey( 'profotograaf_convert_local', $module->bulk_actions( array() ) );
	}

	public function test_the_bulk_action_converts_the_selection_and_reports_what_failed(): void {
		$module = $this->convert_module();
		$this->store_catalogue( array( $this->photo() ) );
		$this->marked( 40, false, 'Bride' );
		$this->marked( 41, true, 'Rings' );
		$this->meta[42] = array( Photo_Importer::META_PHOTO_ID => 'p-9' );
		Functions\when( 'download_url' )->alias(
			function ( $url ) {
				$this->downloads[] = $url;
				return 2 === count( $this->downloads ) ? new \WP_Error( 'http_404', 'Not Found' ) : $this->tmp;
			}
		);

		$redirect = $module->handle_bulk( 'https://site.test/wp-admin/upload.php?mode=list', 'profotograaf_convert_local', array( '40', '41', '42' ) );

		$this->assertSame( 'https://site.test/wp-admin/upload.php?mode=list&profotograaf_converted=1', $redirect );
		$this->assertArrayNotHasKey( Virtual_Attachments::META_VIRTUAL, $this->meta[40] );
		$this->assertSame( '1', $this->meta[41][ Virtual_Attachments::META_VIRTUAL ], 'A failure leaves the attachment virtual.' );
		$stored = $this->transients['profotograaf_convert_result_7'];
		$this->assertSame( 1, $stored['converted'] );
		$this->assertSame( 1, $stored['skipped'] );
		$this->assertSame( 'Rings', $stored['failed'][0]['title'] );

		$_GET['profotograaf_converted'] = '1';
		$html                           = $this->printed( array( $module, 'result_notice' ) );
		unset( $_GET['profotograaf_converted'] );

		$this->assertStringContainsString( '1 photo converted to local.', $html );
		$this->assertStringContainsString( '1 photo could not be converted and stays kept on Profotograaf:', $html );
		$this->assertStringContainsString( '<li><strong>Rings</strong>: Profotograaf no longer serves this photo, so no local copy could be made. It stays kept on Profotograaf.</li>', $html );
		$this->assertStringContainsString( '1 file was skipped because it is not kept on Profotograaf.', $html );
		$this->assertArrayNotHasKey( 'profotograaf_convert_result_7', $this->transients, 'The notice shows once.' );
	}

	public function test_the_bulk_action_leaves_other_actions_and_users_without_upload_files_alone(): void {
		$module = $this->convert_module();
		$this->marked( 43 );

		$this->assertSame( 'back', $module->handle_bulk( 'back', 'delete', array( 43 ) ) );
		$this->caps = array( 'edit_post' );
		$this->assertSame( 'back', $module->handle_bulk( 'back', 'profotograaf_convert_local', array( 43 ) ) );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_the_bulk_action_reports_an_attachment_the_user_may_not_edit(): void {
		$module = $this->convert_module();
		$this->marked( 44 );
		Functions\when( 'current_user_can' )->alias( fn( $cap, $id = 0 ) => 'upload_files' === $cap );

		$result = $module->convert_many( array( 44 ) );

		$this->assertSame( 0, $result['converted'] );
		$this->assertSame( 'You are not allowed to change this file.', $result['failed'][0]['message'] );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_the_single_action_checks_the_nonce_converts_and_goes_back(): void {
		$module = $this->convert_module();
		$this->store_catalogue( array( $this->photo() ) );
		$this->marked( 45 );
		Functions\expect( 'check_admin_referer' )->once()->with( 'profotograaf_convert_local_45' )->andReturn( 1 );
		Functions\when( 'wp_get_referer' )->justReturn( 'https://site.test/wp-admin/post.php?post=45&action=edit' );
		$_GET['attachment'] = '45';

		$module->handle_single();
		unset( $_GET['attachment'] );

		$this->assertArrayNotHasKey( Virtual_Attachments::META_VIRTUAL, $this->meta[45] );
		$this->assertSame( array( 'https://site.test/wp-admin/post.php?post=45&action=edit&profotograaf_converted=1' ), $module->redirects );
		$this->assertSame( 1, $this->transients['profotograaf_convert_result_7']['converted'] );
	}

	public function test_the_single_action_refuses_users_who_may_not_edit(): void {
		$module = $this->convert_module();
		$this->marked( 46 );
		Functions\expect( 'check_admin_referer' )->never();
		$dies = array();
		Functions\when( 'wp_die' )->alias(
			function ( $message, $title, $args ) use ( &$dies ) {
				$dies[] = $args['response'];
			}
		);
		$_GET['attachment'] = '46';

		$this->caps = array( 'upload_files' );
		$module->handle_single();
		unset( $_GET['attachment'] );

		$this->assertSame( array( 403 ), $dies );
		$this->assertSame( array(), $module->redirects );
		$this->assertSame( '1', $this->meta[46][ Virtual_Attachments::META_VIRTUAL ] );
	}

	public function test_a_failed_single_conversion_shows_the_reason(): void {
		$module = $this->convert_module();
		$this->marked( 47 );
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_get_referer' )->justReturn( false );
		$_GET['attachment'] = '47';

		$module->handle_single();
		$_GET['profotograaf_converted'] = '1';
		$html                           = $this->printed( array( $module, 'result_notice' ) );
		unset( $_GET['attachment'], $_GET['profotograaf_converted'] );

		$this->assertSame( array( 'https://site.test/wp-admin/upload.php?profotograaf_converted=1' ), $module->redirects );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Using Profotograaf photos in the editor is switched off in the plugin settings.', $html );
		$this->assertStringNotContainsString( 'converted to local.', $html );
		$this->assertSame( '1', $this->meta[47][ Virtual_Attachments::META_VIRTUAL ] );
	}

	public function test_the_result_notice_needs_the_flag_and_a_stored_result(): void {
		$module = $this->convert_module();

		$this->assertSame( '', $this->printed( array( $module, 'result_notice' ) ) );
		$_GET['profotograaf_converted'] = '1';
		$this->assertSame( '', $this->printed( array( $module, 'result_notice' ) ) );
		unset( $_GET['profotograaf_converted'] );
	}

	public function test_the_gone_notice_offers_conversion_on_the_attachment_screen_and_in_the_media_library(): void {
		$module = $this->convert_module();
		$this->marked( 50, true, 'Rings' );
		$this->marked( 51 );
		$screen = (object) array( 'id' => 'attachment' );
		Functions\when( 'get_current_screen' )->alias( fn() => $screen );

		$_GET['post'] = '50';
		$html         = $this->printed( array( $module, 'gone_notice' ) );
		$_GET['post'] = '51';
		$none         = $this->printed( array( $module, 'gone_notice' ) );
		unset( $_GET['post'] );

		$this->assertStringContainsString( 'Profotograaf no longer lists this photo, so it may stop showing on your site. Convert it to local while Profotograaf still serves it.', $html );
		$this->assertStringContainsString( 'profotograaf_convert_local_50', $html );
		$this->assertSame( '', $none );

		$screen->id = 'upload';
		$list       = $this->printed( array( $module, 'gone_notice' ) );
		$this->assertStringContainsString( '1 photo kept on Profotograaf is no longer listed there', $list );
		$this->assertStringContainsString( 'post.php?post=50&amp;action=edit">Rings</a>', $list );
		$this->assertStringNotContainsString( 'profotograaf_convert_local_51', $list );

		$screen->id = 'dashboard';
		$this->assertSame( '', $this->printed( array( $module, 'gone_notice' ) ) );
		$screen->id = 'upload';
		$this->caps = array( 'edit_post' );
		$this->assertSame( '', $this->printed( array( $module, 'gone_notice' ) ) );
	}

	public function test_with_the_setting_off_a_kept_photo_offers_no_reimport(): void {
		$this->marked( 60 );
		$screen = new Media_Import_Screen();
		$screen->register( $this->plugin );

		$html = $screen->source_field( array(), (object) array( 'ID' => 60 ) )['profotograaf_source']['html'];

		$this->assertStringNotContainsString( 'Re-import', $html );
		$this->assertStringNotContainsString( 'Kept on Profotograaf.', $html );
	}
}

/**
 * Records the redirect instead of exiting.
 */
class Recording_Virtual_Convert extends Virtual_Convert {

	/**
	 * Where the module redirected to.
	 *
	 * @var array<int,string>
	 */
	public array $redirects = array();

	protected function finish( string $url ): void {
		$this->redirects[] = $url;
	}
}
