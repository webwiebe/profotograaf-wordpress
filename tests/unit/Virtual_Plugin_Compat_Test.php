<?php
/**
 * Offload and thumbnail plugins next to photos kept on Profotograaf (issue #156).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Modules\Virtual_Plugin_Compat;
use Profotograaf\Virtual_Attachments;

class Virtual_Plugin_Compat_Test extends Gallery_Test_Case {

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Attached file by attachment id.
	 *
	 * @var array<int,string>
	 */
	private array $files = array();

	/**
	 * Capabilities the current user has.
	 *
	 * @var array<int,string>
	 */
	private array $caps = array( 'edit_post' );

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/pfvpc-' . uniqid() . '/';
		mkdir( $this->dir );
		file_put_contents( $this->dir . 'local.jpg', 'jpeg' );
		file_put_contents( $this->dir . 'converted.jpg', 'jpeg' );

		$this->meta  = array(
			5 => array( Virtual_Attachments::META_VIRTUAL => '1' ),
			6 => array( '_profotograaf_photo_id' => 'p-6' ),
			7 => array( Virtual_Attachments::META_VIRTUAL => '1' ),
		);
		$this->files = array(
			5 => $this->dir . 'profotograaf-virtual/p-5/web-v1.jpg',
			6 => $this->dir . 'local.jpg',
			// Halfway through Convert to local: still marked, the new file is in place.
			7 => $this->dir . 'converted.jpg',
		);

		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'get_attached_file' )->alias( fn( $id ) => $this->files[ $id ] ?? false );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
	}

	protected function tearDown(): void {
		unlink( $this->dir . 'local.jpg' );
		unlink( $this->dir . 'converted.jpg' );
		rmdir( $this->dir );
		parent::tearDown();
	}

	private function compat(): Virtual_Plugin_Compat {
		$compat = new Virtual_Plugin_Compat();
		$compat->register( $this->plugin );
		return $compat;
	}

	private function post( int $id ): \WP_Post {
		$post            = new \WP_Post();
		$post->ID        = $id;
		$post->post_type = 'attachment';
		return $post;
	}

	private function as3cf_item( int $id, string $type = 'media-library' ): object {
		return new class( $id, $type ) {
			public function __construct( private int $id, private string $type ) {}

			public function source_id(): int {
				return $this->id;
			}

			public function source_type(): string {
				return $this->type;
			}
		};
	}

	public function test_it_registers_its_hooks_with_the_setting_off(): void {
		$compat = $this->compat();

		$this->assertSame( 10, has_filter( 'as3cf_pre_update_attachment_metadata', array( $compat, 'cancel_as3cf_metadata' ) ) );
		$this->assertSame( 10, has_filter( 'as3cf_pre_handle_item_upload', array( $compat, 'cancel_as3cf_upload' ) ) );
		$this->assertSame( 10, has_filter( 'advmo_should_offload_attachment', array( $compat, 'advmo_should_offload' ) ) );
		$this->assertSame( 20, has_filter( 'rest_attachment_query', array( $compat, 'leave_regenerate_list' ) ) );
		$this->assertSame( 10, has_filter( 'rest_request_before_callbacks', array( $compat, 'refuse_regenerate' ) ) );
		$this->assertSame( 11, has_filter( 'media_row_actions', array( $compat, 'remove_row_action' ) ) );
		$this->assertSame( 100, has_filter( 'attachment_fields_to_edit', array( $compat, 'remove_modal_button' ) ) );
		$this->assertSame( 10, has_action( 'attachment_submitbox_misc_actions', array( $compat, 'hide_edit_screen_button' ) ) );
	}

	public function test_a_kept_photo_counts_only_while_its_file_is_missing(): void {
		$this->assertTrue( Virtual_Plugin_Compat::kept_without_file( 5 ) );
		$this->assertFalse( Virtual_Plugin_Compat::kept_without_file( 6 ) );
		$this->assertFalse( Virtual_Plugin_Compat::kept_without_file( 7 ) );
		$this->assertFalse( Virtual_Plugin_Compat::kept_without_file( 0 ) );

		$this->meta[8] = array( Virtual_Attachments::META_VIRTUAL => '1' );
		$this->assertTrue( Virtual_Plugin_Compat::kept_without_file( 8 ) );
	}

	public function test_a_photo_being_kept_counts_before_its_mark_is_set(): void {
		// create() saves the metadata, and offload plugins act on it, before the mark is set.
		$this->meta[9]   = array( '_wp_attached_file' => 'profotograaf-virtual/p-9/web-v1.jpg' );
		$this->files[9]  = $this->dir . 'profotograaf-virtual/p-9/web-v1.jpg';
		$this->meta[10]  = array( '_wp_attached_file' => '2026/10/local.jpg' );
		$this->files[10] = $this->dir . 'missing.jpg';

		$this->assertTrue( Virtual_Plugin_Compat::kept_without_file( 9 ) );
		$this->assertTrue( $this->compat()->cancel_as3cf_metadata( false, array(), 9 ) );
		$this->assertFalse( Virtual_Plugin_Compat::kept_without_file( 10 ) );
	}

	public function test_wp_offload_media_skips_a_kept_photo(): void {
		$compat = $this->compat();

		$this->assertTrue( $compat->cancel_as3cf_metadata( false, array(), 5 ) );
		$this->assertFalse( $compat->cancel_as3cf_metadata( false, array(), 6 ) );
		$this->assertFalse( $compat->cancel_as3cf_metadata( false, array(), 7 ) );
		$this->assertTrue( $compat->cancel_as3cf_metadata( true, array(), 6 ) );

		$this->assertTrue( $compat->cancel_as3cf_upload( false, $this->as3cf_item( 5 ) ) );
		$this->assertFalse( $compat->cancel_as3cf_upload( false, $this->as3cf_item( 6 ) ) );
		$this->assertFalse( $compat->cancel_as3cf_upload( false, $this->as3cf_item( 5, 'bboss-user-avatar' ) ) );
		$this->assertFalse( $compat->cancel_as3cf_upload( false, 'no item' ) );
	}

	public function test_advanced_media_offloader_skips_a_kept_photo(): void {
		$compat = $this->compat();

		$this->assertFalse( $compat->advmo_should_offload( true, 5 ) );
		$this->assertTrue( $compat->advmo_should_offload( true, 6 ) );
		$this->assertTrue( $compat->advmo_should_offload( true, 7 ) );
		$this->assertFalse( $compat->advmo_should_offload( false, 6 ) );
	}

	public function test_kept_photos_leave_the_regenerate_thumbnails_list(): void {
		$compat  = $this->compat();
		$request = new \WP_REST_Request( 'GET', '/wp/v2/media' );
		$request->set_param( 'is_regeneratable', '1' );
		$earlier = array(
			'post_type'  => 'attachment',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- test input.
				array(
					'key'     => '_wp_attachment_context',
					'value'   => 'site-icon',
					'compare' => 'NOT EXISTS',
				),
			),
		);

		$args = $compat->leave_regenerate_list( $earlier, $request );

		$this->assertSame( $earlier['meta_query'][0], $args['meta_query'][0] );
		$this->assertSame(
			array(
				'key'     => '_profotograaf_virtual',
				'compare' => 'NOT EXISTS',
			),
			$args['meta_query'][1]
		);
		$this->assertCount( 1, $compat->leave_regenerate_list( array(), $request )['meta_query'] );
	}

	public function test_other_media_queries_are_untouched(): void {
		$compat = $this->compat();
		$args   = array( 'post_type' => 'attachment' );

		$this->assertSame( $args, $compat->leave_regenerate_list( $args, new \WP_REST_Request( 'GET', '/wp/v2/media' ) ) );
		$this->assertSame( $args, $compat->leave_regenerate_list( $args, 'not a request' ) );
		$this->assertSame( 'x', $compat->leave_regenerate_list( 'x', new \WP_REST_Request( 'GET', '/wp/v2/media' ) ) );
	}

	public function test_regenerating_a_kept_photo_gets_a_clear_error(): void {
		$result = $this->compat()->refuse_regenerate( null, array(), new \WP_REST_Request( 'POST', '/regenerate-thumbnails/v1/regenerate/5' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_virtual_no_file', $result->get_error_code() );
		$this->assertSame(
			'This photo is kept on Profotograaf, so this site has no file to make thumbnails from. Profotograaf serves its sizes. Convert it to local if you need the files on this site.',
			$result->get_error_message()
		);
		$this->assertSame(
			array(
				'status'        => 409,
				'attachment_id' => 5,
			),
			$result->get_error_data()
		);
	}

	public function test_regenerate_passes_through_for_other_requests(): void {
		$compat  = $this->compat();
		$earlier = new \WP_Error( 'rest_forbidden', 'No.' );

		$this->assertNull( $compat->refuse_regenerate( null, array(), new \WP_REST_Request( 'POST', '/regenerate-thumbnails/v1/regenerate/6' ) ) );
		$this->assertNull( $compat->refuse_regenerate( null, array(), new \WP_REST_Request( 'POST', '/regenerate-thumbnails/v1/regenerate/7' ) ) );
		$this->assertNull( $compat->refuse_regenerate( null, array(), new \WP_REST_Request( 'GET', '/regenerate-thumbnails/v1/attachmentinfo/5' ) ) );
		$this->assertNull( $compat->refuse_regenerate( null, array(), new \WP_REST_Request( 'POST', '/wp/v2/media/5' ) ) );
		$this->assertNull( $compat->refuse_regenerate( null, array(), 'not a request' ) );
		$this->assertSame( $earlier, $compat->refuse_regenerate( $earlier, array(), new \WP_REST_Request( 'POST', '/regenerate-thumbnails/v1/regenerate/5' ) ) );
	}

	public function test_a_user_who_may_not_edit_the_photo_gets_the_plugin_answer(): void {
		$this->caps = array();

		$this->assertNull( $this->compat()->refuse_regenerate( null, array(), new \WP_REST_Request( 'POST', '/regenerate-thumbnails/v1/regenerate/5' ) ) );
	}

	public function test_regenerate_links_and_buttons_leave_a_kept_photo(): void {
		$compat  = $this->compat();
		$actions = array(
			'edit'                  => 'Edit',
			'regenerate_thumbnails' => 'Regenerate Thumbnails',
		);

		$this->assertSame( array( 'edit' => 'Edit' ), $compat->remove_row_action( $actions, $this->post( 5 ) ) );
		$this->assertSame( $actions, $compat->remove_row_action( $actions, $this->post( 6 ) ) );
		$this->assertSame( 'x', $compat->remove_row_action( 'x', $this->post( 5 ) ) );

		$fields = array(
			'post_title'            => array(),
			'regenerate_thumbnails' => array( 'html' => '<a>' ),
		);
		$this->assertSame( array( 'post_title' => array() ), $compat->remove_modal_button( $fields, $this->post( 5 ) ) );
		$this->assertSame( $fields, $compat->remove_modal_button( $fields, $this->post( 7 ) ) );
		$this->assertSame( $fields, $compat->remove_modal_button( $fields, null ) );
	}

	public function test_the_edit_screen_hides_the_regenerate_button_of_a_kept_photo_only(): void {
		$compat = $this->compat();

		ob_start();
		$compat->hide_edit_screen_button( $this->post( 5 ) );
		$this->assertSame( '<style>.misc-pub-regenerate-thumbnails { display: none; }</style>', (string) ob_get_clean() );

		ob_start();
		$compat->hide_edit_screen_button( $this->post( 6 ) );
		$compat->hide_edit_screen_button();
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
