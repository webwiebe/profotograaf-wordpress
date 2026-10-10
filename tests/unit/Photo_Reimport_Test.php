<?php
/**
 * Photo re-import tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Profotograaf\Logger;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Photo_Rest;
use Profotograaf\Settings;

/**
 * Just enough of a REST request.
 */
class Reimport_Request {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $params Params.
	 */
	public function __construct( private array $params = array() ) {}

	/**
	 * Param.
	 *
	 * @param string $name Name.
	 */
	public function get_param( $name ) {
		return $this->params[ $name ] ?? null;
	}
}

class Photo_Reimport_Test extends Gallery_Test_Case {

	private Photo_Importer $importer;

	private Photo_Rest $rest;

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
	 * Files deleted through wp_delete_attachment_files, one entry per call.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $deleted = array();

	/**
	 * Calls that changed the attachment, in order.
	 *
	 * @var array<int,string>
	 */
	private array $writes = array();

	/**
	 * URLs passed to download_url.
	 *
	 * @var array<int,string>
	 */
	private array $downloads = array();

	private string $tmp = '';

	/**
	 * The stored original the baseline hashes.
	 */
	private string $original = '';

	/**
	 * What wp_handle_sideload returns.
	 *
	 * @var array<string,mixed>
	 */
	private array $moved = array(
		'file' => '/uploads/2026/10/profotograaf-p-1.jpg',
		'type' => 'image/jpeg',
	);

	/**
	 * What wp_generate_attachment_metadata returns.
	 *
	 * @var mixed
	 */
	private $generated = array(
		'file'   => '2026/10/profotograaf-p-1.jpg',
		'width'  => 1600,
		'height' => 1067,
		'sizes'  => array( 'thumbnail' => array( 'file' => 'profotograaf-p-1-150x150.jpg' ) ),
	);

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		$this->connect();
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );

		$this->meta[40] = array(
			'_profotograaf_photo_id'   => 'p-1',
			'_profotograaf_gallery_id' => 'g-old',
			'_profotograaf_version'    => 'aaaaaaaaaaaa',
		);
		$this->store(
			array(
				array(
					'id'            => 'p-1',
					'gallery_id'    => 'g-1',
					'gallery_title' => 'Spring',
					'title'         => 'Bride',
					'caption'       => '',
					'alt'           => '',
					'web_url'       => 'https://profotograaf.nl/share/img/a1/web-bbbbbbbbbbbb.jpg',
					'version'       => 'bbbbbbbbbbbb',
				),
			)
		);

		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				$this->writes[]            = 'meta:' . $key;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
		$this->original = (string) tempnam( sys_get_temp_dir(), 'pfreo' );
		file_put_contents( $this->original, 'replaced original' );
		$original = $this->original;
		Functions\when( 'wp_get_original_image_path' )->alias( fn() => $original );
		Functions\when( 'get_post' )->alias(
			function () {
				$post               = new \WP_Post();
				$post->post_title   = 'Bride';
				$post->post_excerpt = 'Edited caption';
				return $post;
			}
		);
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/2026/09/old.jpg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'file'  => '2026/09/old.jpg',
				'sizes' => array( 'thumbnail' => array( 'file' => 'old-150x150.jpg' ) ),
			)
		);
		Functions\when( 'wp_handle_sideload' )->alias( fn() => $this->moved );
		Functions\when( 'wp_generate_attachment_metadata' )->alias( fn() => $this->generated );
		Functions\when( 'update_attached_file' )->alias(
			function () {
				$this->writes[] = 'file';
				return true;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function () {
				$this->writes[] = 'post';
				return 40;
			}
		);
		Functions\when( 'wp_update_attachment_metadata' )->alias(
			function () {
				$this->writes[] = 'metadata';
				return true;
			}
		);
		Functions\when( 'wp_delete_attachment_files' )->alias(
			function ( $id, $meta, $backups, $file ) {
				$this->writes[]  = 'delete-old';
				$this->deleted[] = array( $id, $meta, $backups, $file );
				return true;
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );

		$this->tmp = (string) tempnam( sys_get_temp_dir(), 'pfre' );
		$tmp       = $this->tmp;
		Functions\when( 'download_url' )->alias(
			function ( $url ) use ( $tmp ) {
				$this->downloads[] = $url;
				return $tmp;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );

		$settings       = new Settings();
		$catalogue      = new Photo_Catalogue( $this->api, $settings, $this->clock() );
		$this->importer = new Photo_Importer( $settings, null, $catalogue );
		$this->rest     = new Photo_Rest( $catalogue, $this->importer, $settings );
	}

	protected function tearDown(): void {
		if ( is_file( $this->tmp ) ) {
			unlink( $this->tmp );
		}
		if ( is_file( $this->original ) ) {
			unlink( $this->original );
		}
		Logger::configure( null );
		parent::tearDown();
	}

	/**
	 * Stores a catalogue.
	 *
	 * @param array<int,array<string,mixed>> $photos Rows.
	 */
	private function store( array $photos ): void {
		$this->options[ Photo_Catalogue::OPTION ] = array(
			'photos'     => $photos,
			'stale'      => false,
			'fetched_at' => 1,
			'dropped'    => 0,
		);
	}

	public function test_it_replaces_the_file_in_place_and_updates_the_version(): void {
		Actions\expectDone( 'profotograaf_photo_reimported' )->once()->with( 40, \Mockery::type( 'array' ) );

		$result = $this->importer->reimport( 40 );

		$this->assertSame( 40, $result );
		$this->assertSame( array( 'https://profotograaf.nl/share/img/a1/web-bbbbbbbbbbbb.jpg' ), $this->downloads );
		$this->assertSame( 'bbbbbbbbbbbb', $this->meta[40]['_profotograaf_version'] );
		$this->assertSame( 'p-1', $this->meta[40]['_profotograaf_photo_id'] );
		$this->assertSame( 'g-1', $this->meta[40]['_profotograaf_gallery_id'] );
	}

	public function test_it_records_the_file_baseline_and_fills_in_a_missing_text_baseline(): void {
		$this->importer->reimport( 40 );

		$this->assertSame( sha1( 'replaced original' ), $this->meta[40]['_profotograaf_file_hash'] );
		$this->assertSame( 'import', $this->meta[40]['_profotograaf_origin'] );
		$this->assertSame( sha1( (string) json_encode( array( 'Bride', 'Edited caption', '' ) ) ), $this->meta[40]['_profotograaf_text_hash'] );
	}

	public function test_it_keeps_an_existing_text_baseline_so_a_local_text_edit_stays_visible(): void {
		$this->meta[40]['_profotograaf_text_hash'] = 'written-by-importer';
		$this->meta[40]['_profotograaf_origin']    = 'upload';

		$this->importer->reimport( 40 );

		$this->assertSame( 'written-by-importer', $this->meta[40]['_profotograaf_text_hash'] );
		$this->assertSame( 'upload', $this->meta[40]['_profotograaf_origin'] );
		$this->assertSame( sha1( 'replaced original' ), $this->meta[40]['_profotograaf_file_hash'] );
	}

	public function test_the_old_files_go_only_after_the_new_ones_are_in_place(): void {
		$this->importer->reimport( 40 );

		$this->assertSame( array( 'file', 'post', 'metadata', 'meta:_profotograaf_gallery_id', 'meta:_profotograaf_version', 'meta:_profotograaf_file_hash', 'meta:_profotograaf_origin', 'meta:_profotograaf_text_hash', 'delete-old' ), $this->writes );
		$this->assertSame( 40, $this->deleted[0][0] );
		$this->assertSame( 'old-150x150.jpg', $this->deleted[0][1]['sizes']['thumbnail']['file'] );
		$this->assertSame( '/uploads/2026/09/old.jpg', $this->deleted[0][3] );
	}

	public function test_a_photo_missing_from_the_catalogue_leaves_the_local_copy_alone(): void {
		$this->store( array() );
		Actions\expectDone( 'profotograaf_photo_reimported' )->never();

		$result = $this->importer->reimport( 40 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_photo_gone', $result->get_error_code() );
		$this->assertSame( 404, $result->data['status'] );
		$this->assertStringContainsString( 'no longer available on Profotograaf', $result->get_error_message() );
		$this->assertSame( array(), $this->downloads );
		$this->assertSame( array(), $this->writes );
		$this->assertSame( 'aaaaaaaaaaaa', $this->meta[40]['_profotograaf_version'] );
	}

	/**
	 * @return array<string,array{array<int,string>}>
	 */
	public static function missing_caps(): array {
		return array(
			'cannot edit the attachment' => array( array( 'upload_files' ) ),
			'cannot upload'              => array( array( 'edit_post' ) ),
			'neither'                    => array( array() ),
		);
	}

	/**
	 * @dataProvider missing_caps
	 * @param array<int,string> $caps Capabilities the user has.
	 */
	public function test_it_refuses_without_the_needed_permissions( array $caps ): void {
		$this->caps = $caps;

		$result = $this->importer->reimport( 40 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_reimport_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] );
		$this->assertSame( array(), $this->downloads );
		$this->assertSame( array(), $this->writes );
	}

	public function test_it_refuses_while_the_media_source_is_off(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );

		$result = $this->importer->reimport( 40 );

		$this->assertSame( 'profotograaf_media_source_off', $result->get_error_code() );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_it_refuses_an_attachment_that_was_not_imported(): void {
		$result = $this->importer->reimport( 41 );

		$this->assertSame( 'profotograaf_reimport_not_imported', $result->get_error_code() );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_it_refuses_a_url_outside_the_platform_host(): void {
		$this->options[ Photo_Catalogue::OPTION ]['photos'][0]['web_url'] = 'https://evil.example/web.jpg';

		$result = $this->importer->reimport( 40 );

		$this->assertSame( 'profotograaf_import_host', $result->get_error_code() );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_a_download_failure_changes_nothing(): void {
		Functions\when( 'download_url' )->justReturn( new \WP_Error( 'http_404', 'Not Found' ) );
		Actions\expectDone( 'profotograaf_photo_reimported' )->never();

		$result = $this->importer->reimport( 40 );

		$this->assertSame( 'profotograaf_import_download', $result->get_error_code() );
		$this->assertTrue( $result->data['retryable'] );
		$this->assertSame( array(), $this->writes );
		$this->assertSame( 'aaaaaaaaaaaa', $this->meta[40]['_profotograaf_version'] );
		$this->assertArrayNotHasKey( 'profotograaf_import_lock_' . md5( 'p-1' ), $this->options );
	}

	public function test_a_held_lock_ends_in_a_retryable_error(): void {
		$this->options[ Photo_Importer::LOCK_PREFIX . md5( 'p-1' ) ] = time();

		$result = $this->importer->reimport( 40 );

		$this->assertSame( 'profotograaf_import_busy', $result->get_error_code() );
		$this->assertSame( array(), $this->downloads );
		$this->assertArrayHasKey( Photo_Importer::LOCK_PREFIX . md5( 'p-1' ), $this->options, 'the other request still owns the lock' );
	}

	public function test_a_failed_store_keeps_the_old_file_and_removes_the_temp_file(): void {
		$this->moved = array( 'error' => 'Disk full' );
		Actions\expectDone( 'profotograaf_photo_reimported' )->never();

		$result = $this->importer->reimport( 40 );

		$this->assertSame( 'profotograaf_import_failed', $result->get_error_code() );
		$this->assertSame( array(), $this->writes );
	}

	public function test_failing_to_make_the_sizes_keeps_the_old_file(): void {
		$this->generated = array();

		$result = $this->importer->reimport( 40 );

		$this->assertSame( 'profotograaf_import_failed', $result->get_error_code() );
		$this->assertSame( array(), $this->writes );
		$this->assertSame( 'aaaaaaaaaaaa', $this->meta[40]['_profotograaf_version'] );
	}

	public function test_without_a_catalogue_it_cannot_reimport(): void {
		$importer = new Photo_Importer( new Settings() );

		$result = $importer->reimport( 40 );

		$this->assertSame( 'profotograaf_reimport_not_imported', $result->get_error_code() );
	}

	public function test_the_route_is_registered_with_its_own_permission_check(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $ns, $route, $args ) use ( &$routes ) {
				$routes[ $route ] = $args;
			}
		);

		$this->rest->register_routes();

		$this->assertSame( 'POST', $routes['/photos/reimport']['methods'] );
		$this->assertSame( array( $this->rest, 'can_reimport' ), $routes['/photos/reimport']['permission_callback'] );
		$this->assertTrue( $routes['/photos/reimport']['args']['attachment_id']['required'] );
	}

	public function test_the_route_permission_needs_edit_post_and_upload_files(): void {
		$request = new Reimport_Request( array( 'attachment_id' => 40 ) );
		$this->assertTrue( $this->rest->can_reimport( $request ) );

		$this->caps = array( 'upload_files' );
		$this->assertFalse( $this->rest->can_reimport( $request ) );

		$this->caps = array( 'upload_files', 'edit_post' );
		$this->assertFalse( $this->rest->can_reimport( new Reimport_Request( array( 'attachment_id' => 0 ) ) ) );
	}

	public function test_the_route_returns_the_attachment_id_or_the_importer_error(): void {
		$result = $this->rest->reimport_photo( new Reimport_Request( array( 'attachment_id' => 40 ) ) );
		$this->assertSame( array( 'attachment_id' => 40 ), $result );

		$this->store( array() );
		$error = $this->rest->reimport_photo( new Reimport_Request( array( 'attachment_id' => 40 ) ) );
		$this->assertSame( 'profotograaf_photo_gone', $error->get_error_code() );
	}

	public function test_the_route_refuses_while_the_setting_is_off(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );

		$error = $this->rest->reimport_photo( new Reimport_Request( array( 'attachment_id' => 40 ) ) );

		$this->assertSame( 'profotograaf_media_source_off', $error->get_error_code() );
		$this->assertSame( 403, $error->data['status'] );
	}
}
