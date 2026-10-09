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
use Profotograaf\Modules\Virtual_Attachments as Virtual_Module;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Settings;
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
		Actions\expectDone( 'profotograaf_virtual_attachment_converted' )->never();

		$result = $this->virtual->convert_to_local( $id );

		$this->assertSame( 'profotograaf_photo_gone', $result->get_error_code() );
		$this->assertTrue( $this->virtual->is_virtual( $id ) );
		$this->assertSame( array(), $this->downloads );
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
	 * A saved core/image block.
	 *
	 * @param int    $id  Attachment id.
	 * @param string $src Saved src.
	 * @return array<string,mixed>
	 */
	private function image_block( int $id, string $src ): array {
		$html = '<figure class="wp-block-image"><img src="' . $src . '" alt="" class="wp-image-' . $id . '"/></figure>';
		return array(
			'blockName'    => 'core/image',
			'attrs'        => array( 'id' => $id ),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	public function test_a_saved_image_block_gets_the_current_attachment_url(): void {
		$this->enable();
		$id = $this->create_one();
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://profotograaf.nl/share/img/a1/web-bbbbbbbbbbbb.jpg' );
		Functions\when( 'esc_url' )->returnArg();

		$block = $this->virtual->filter_block_data( $this->image_block( $id, 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg' ) );

		$this->assertStringContainsString( 'web-bbbbbbbbbbbb.jpg', $block['innerHTML'] );
		$this->assertStringNotContainsString( 'web-aaaaaaaaaaaa.jpg', $block['innerContent'][0] );
	}

	public function test_block_data_of_other_blocks_foreign_hosts_and_plain_attachments_is_untouched(): void {
		$this->enable();
		$id = $this->create_one();
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://profotograaf.nl/share/img/a1/web-bbbbbbbbbbbb.jpg' );
		Functions\when( 'esc_url' )->returnArg();

		$foreign = $this->image_block( $id, 'https://example.com/a.jpg' );
		$plain   = $this->image_block( 5, 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg' );
		$other   = array(
			'blockName' => 'core/paragraph',
			'attrs'     => array(),
		);

		$this->assertSame( $foreign, $this->virtual->filter_block_data( $foreign ) );
		$this->assertSame( $plain, $this->virtual->filter_block_data( $plain ) );
		$this->assertSame( $other, $this->virtual->filter_block_data( $other ) );
	}

	public function test_with_the_flag_off_block_data_is_untouched(): void {
		$virtual                                        = new Virtual_Attachments( new Settings(), new Photo_Importer( new Settings() ), null );
		$this->meta[9][ Photo_Importer::META_PHOTO_ID ] = 'p-1';
		$block = $this->image_block( 9, 'https://profotograaf.nl/share/img/a1/web-aaaaaaaaaaaa.jpg' );

		$this->assertSame( $block, $virtual->filter_block_data( $block ) );
	}
}
