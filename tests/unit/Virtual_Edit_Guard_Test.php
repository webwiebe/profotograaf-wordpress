<?php
/**
 * Image editing guard for photos kept on Profotograaf (issue #155).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Modules\Virtual_Edit_Guard;
use Profotograaf\Settings;
use Profotograaf\Virtual_Attachments;

class Virtual_Edit_Guard_Test extends Gallery_Test_Case {

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
	 * Arguments of register_rest_field.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $fields = array();

	private string $build = '';

	protected function setUp(): void {
		parent::setUp();
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		$this->meta                        = array(
			5 => array( Virtual_Attachments::META_VIRTUAL => '1' ),
			6 => array( '_profotograaf_photo_id' => 'p-6' ),
		);

		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://site.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		// Like core, wp_nonce_url returns the URL HTML-escaped.
		Functions\when( 'wp_nonce_url' )->alias( fn( $url, $action ) => str_replace( '&', '&amp;', $url . '&_wpnonce=' . $action ) );
		Functions\when( 'register_rest_field' )->alias(
			function ( ...$args ) {
				$this->fields[] = $args;
			}
		);
		Functions\when( 'get_posts' )->alias(
			function ( $args ) {
				$ids = array();
				foreach ( $this->meta as $id => $meta ) {
					if ( (string) ( $meta[ $args['meta_key'] ] ?? '' ) === (string) $args['meta_value'] ) {
						$ids[] = $id;
					}
				}
				return array_slice( $ids, 0, (int) $args['posts_per_page'] );
			}
		);
		Functions\when( 'wp_set_script_translations' )->justReturn( true );

		$this->build = sys_get_temp_dir() . '/pfveg-' . uniqid() . '/';
		foreach ( array( 'virtual-edit-media', 'virtual-edit-editor' ) as $folder ) {
			mkdir( $this->build . $folder, 0777, true );
			file_put_contents(
				$this->build . $folder . '/index.asset.php',
				"<?php return array( 'dependencies' => array( 'wp-i18n', 'wp-hooks' ), 'version' => 'abc' );"
			);
		}
	}

	protected function tearDown(): void {
		foreach ( array( 'virtual-edit-media', 'virtual-edit-editor' ) as $folder ) {
			if ( is_file( $this->build . $folder . '/index.asset.php' ) ) {
				unlink( $this->build . $folder . '/index.asset.php' );
				rmdir( $this->build . $folder );
			}
		}
		if ( is_dir( $this->build ) ) {
			rmdir( $this->build );
		}
		parent::tearDown();
	}

	private function guard(): Virtual_Edit_Guard {
		$guard = new Built_Virtual_Edit_Guard( $this->build );
		$guard->register( $this->plugin );
		return $guard;
	}

	private function post( int $id, string $type = 'attachment' ): \WP_Post {
		$post            = new \WP_Post();
		$post->ID        = $id;
		$post->post_type = $type;
		return $post;
	}

	public function test_it_registers_its_hooks_with_the_setting_off(): void {
		$guard = $this->guard();

		$this->assertSame( 10, has_filter( 'rest_request_before_callbacks', array( $guard, 'refuse_rest_edit' ) ) );
		$this->assertSame( 10, has_action( 'rest_api_init', array( $guard, 'register_rest_field' ) ) );
		$this->assertSame( 20, has_filter( 'wp_prepare_attachment_for_js', array( $guard, 'mark_for_js' ) ) );
		$this->assertSame( 11, has_action( 'edit_form_after_title', array( $guard, 'edit_screen_note' ) ) );
		$this->assertSame( 10, has_action( 'wp_enqueue_media', array( $guard, 'enqueue_media' ) ) );
		$this->assertSame( 10, has_action( 'enqueue_block_editor_assets', array( $guard, 'enqueue_editor' ) ) );
	}

	public function test_the_rest_image_editor_refuses_a_kept_photo_with_a_specific_error(): void {
		$result = $this->guard()->refuse_rest_edit( null, array(), new \WP_REST_Request( 'POST', '/wp/v2/media/5/edit' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_virtual_not_editable', $result->get_error_code() );
		$this->assertSame(
			'This photo is kept on Profotograaf, so this site has no file to edit. Convert it to local in the Media Library first, then edit it.',
			$result->get_error_message()
		);
		$data = $result->get_error_data();
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( 5, $data['attachment_id'] );
		$this->assertSame(
			'https://site.test/wp-admin/admin-post.php?action=profotograaf_convert_local&attachment=5&_wpnonce=profotograaf_convert_local_5',
			$data['convert_url']
		);
	}

	public function test_the_error_has_no_convert_link_for_a_user_who_cannot_upload(): void {
		$this->caps = array( 'edit_post' );

		$result = $this->guard()->refuse_rest_edit( null, array(), new \WP_REST_Request( 'POST', '/wp/v2/media/5/edit' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '', $result->get_error_data()['convert_url'] );
	}

	public function test_the_rest_image_editor_is_untouched_for_a_normal_attachment(): void {
		$guard = $this->guard();

		$this->assertNull( $guard->refuse_rest_edit( null, array(), new \WP_REST_Request( 'POST', '/wp/v2/media/6/edit' ) ) );
		$this->assertNull( $guard->refuse_rest_edit( null, array(), new \WP_REST_Request( 'POST', '/wp/v2/media/77/edit' ) ) );
	}

	public function test_other_requests_and_earlier_answers_pass_through(): void {
		$guard   = $this->guard();
		$earlier = new \WP_Error( 'rest_forbidden', 'No.' );

		$this->assertNull( $guard->refuse_rest_edit( null, array(), new \WP_REST_Request( 'GET', '/wp/v2/media/5' ) ) );
		$this->assertNull( $guard->refuse_rest_edit( null, array(), new \WP_REST_Request( 'POST', '/wp/v2/media/5' ) ) );
		$this->assertNull( $guard->refuse_rest_edit( null, array(), new \WP_REST_Request( 'GET', '/wp/v2/media/5/edit' ) ) );
		$this->assertNull( $guard->refuse_rest_edit( null, array(), 'not a request' ) );
		$this->assertSame( $earlier, $guard->refuse_rest_edit( $earlier, array(), new \WP_REST_Request( 'POST', '/wp/v2/media/5/edit' ) ) );
	}

	public function test_a_user_who_may_not_edit_the_attachment_gets_the_core_answer(): void {
		$this->caps = array( 'upload_files' );

		$this->assertNull( $this->guard()->refuse_rest_edit( null, array(), new \WP_REST_Request( 'POST', '/wp/v2/media/5/edit' ) ) );
	}

	public function test_the_rest_field_carries_the_convert_link_for_a_kept_photo_only(): void {
		$guard = $this->guard();
		$guard->register_rest_field();

		$this->assertSame( 'attachment', $this->fields[0][0] );
		$this->assertSame( 'profotograaf_virtual', $this->fields[0][1] );
		$this->assertSame( array( 'view', 'edit', 'embed' ), $this->fields[0][2]['schema']['context'] );
		$this->assertSame(
			array( 'convert_url' => 'https://site.test/wp-admin/admin-post.php?action=profotograaf_convert_local&attachment=5&_wpnonce=profotograaf_convert_local_5' ),
			$guard->rest_value( array( 'id' => 5 ) )
		);
		$this->assertNull( $guard->rest_value( array( 'id' => 6 ) ) );
		$this->assertNull( $guard->rest_value( 'nothing' ) );
	}

	public function test_the_media_modal_learns_a_kept_photo_cannot_be_edited(): void {
		$guard = $this->guard();

		$kept = $guard->mark_for_js( array( 'id' => 5 ), $this->post( 5 ) );
		$this->assertSame(
			'https://site.test/wp-admin/admin-post.php?action=profotograaf_convert_local&attachment=5&_wpnonce=profotograaf_convert_local_5',
			$kept['profotograafNotEditable']['convertUrl']
		);
		$this->assertSame( array( 'id' => 6 ), $guard->mark_for_js( array( 'id' => 6 ), $this->post( 6 ) ) );
		$this->assertSame( 'x', $guard->mark_for_js( 'x', $this->post( 5 ) ) );
		$this->assertSame( array(), $guard->mark_for_js( array(), null ) );
	}

	public function test_the_edit_screen_hides_edit_image_and_explains_why(): void {
		$guard = $this->guard();

		ob_start();
		$guard->edit_screen_note( $this->post( 5 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '#imgedit-open-btn-5, #imgedit-open-btn-5 + .spinner { display: none; }', $html );
		$this->assertStringContainsString( 'This photo is kept on Profotograaf, so this site has no file to edit. Convert it to local to crop, rotate or scale it.', $html );
		$this->assertStringContainsString( '>Convert to local</a>', $html );
		$this->assertStringContainsString( 'attachment=5&amp;_wpnonce=profotograaf_convert_local_5', $html );
	}

	public function test_the_edit_screen_note_has_no_button_for_a_user_who_cannot_convert(): void {
		$this->caps = array( 'edit_post' );
		$guard      = $this->guard();

		ob_start();
		$guard->edit_screen_note( $this->post( 5 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'imgedit-open-btn-5', $html );
		$this->assertStringNotContainsString( 'Convert to local</a>', $html );
	}

	public function test_the_edit_screen_of_a_normal_attachment_is_untouched(): void {
		$guard = $this->guard();

		ob_start();
		$guard->edit_screen_note( $this->post( 6 ) );
		$guard->edit_screen_note( $this->post( 5, 'post' ) );
		$guard->edit_screen_note( null );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_scripts_load_while_a_kept_photo_exists(): void {
		$guard = $this->guard();
		$guard->enqueue_media();
		$guard->enqueue_editor();

		$this->assertSame( 'https://example.test/wp-content/plugins/profotograaf/build/virtual-edit-media/index.js', $this->enqueued['profotograaf-virtual-edit-media']['src'] );
		$this->assertSame( array( 'wp-i18n', 'wp-hooks', 'media-views' ), $this->enqueued['profotograaf-virtual-edit-media']['deps'] );
		$this->assertSame( 'abc', $this->enqueued['profotograaf-virtual-edit-media']['ver'] );
		$this->assertSame( array( 'wp-i18n', 'wp-hooks', 'wp-core-data' ), $this->enqueued['profotograaf-virtual-edit-editor']['deps'] );
	}

	public function test_the_scripts_load_while_the_setting_is_on(): void {
		$this->meta                        = array();
		$this->options[ Settings::OPTION ] = array(
			'media_source'        => true,
			'virtual_attachments' => true,
		);
		$this->guard()->enqueue_editor();

		$this->assertArrayHasKey( 'profotograaf-virtual-edit-editor', $this->enqueued );
	}

	public function test_no_script_without_kept_photos_or_without_upload_rights(): void {
		$this->meta = array( 6 => array( '_profotograaf_photo_id' => 'p-6' ) );
		$this->guard()->enqueue_media();
		$this->assertSame( array(), $this->enqueued );

		$this->meta = array( 5 => array( Virtual_Attachments::META_VIRTUAL => '1' ) );
		$this->caps = array( 'edit_post' );
		$this->guard()->enqueue_editor();
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_no_script_without_a_build(): void {
		unlink( $this->build . 'virtual-edit-media/index.asset.php' );
		rmdir( $this->build . 'virtual-edit-media' );

		$this->guard()->enqueue_media();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_the_default_build_directory_is_the_plugin_build_folder(): void {
		$this->assertSame( PROFOTOGRAAF_DIR . 'build/', Built_Virtual_Edit_Guard::default_directory() );
	}
}

/**
 * Reads the built scripts from a temporary folder.
 */
class Built_Virtual_Edit_Guard extends Virtual_Edit_Guard {

	private string $dir;

	public function __construct( string $dir ) {
		parent::__construct();
		$this->dir = $dir;
	}

	protected function build_directory(): string {
		return $this->dir;
	}

	public static function default_directory(): string {
		return ( new Virtual_Edit_Guard() )->build_directory();
	}
}
