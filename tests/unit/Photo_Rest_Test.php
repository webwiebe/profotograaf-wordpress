<?php
/**
 * Photo browsing REST route tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Logger;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Photo_Rest;
use Profotograaf\Settings;

/**
 * Just enough of a REST request.
 */
class Photo_Rest_Request {

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

class Photo_Rest_Test extends Gallery_Test_Case {

	private Photo_Rest $rest;

	/**
	 * Attachment ids by photo id, as the Media Library holds them.
	 *
	 * @var array<string,int>
	 */
	private array $imported = array();

	/**
	 * Capability the current user has.
	 */
	private string $capability = 'upload_files';

	private string $tmp = '';

	private int $next_id = 900;

	/**
	 * URLs the importer downloaded.
	 *
	 * @var array<int,string>
	 */
	private array $downloads = array();

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		$this->connect();
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );

		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $cap === $this->capability );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'get_posts' )->alias(
			function ( $args ) {
				$this->assertSame( 'attachment', $args['post_type'] );
				if ( isset( $args['meta_key'] ) ) {
					$id = $this->imported[ $args['meta_value'] ] ?? null;
					return null === $id ? array() : array( $id );
				}
				$this->assertSame( '_profotograaf_photo_id', $args['meta_query'][0]['key'] );
				$this->assertSame( 'IN', $args['meta_query'][0]['compare'] );
				$found = array();
				foreach ( $args['meta_query'][0]['value'] as $photo_id ) {
					if ( isset( $this->imported[ $photo_id ] ) ) {
						$found[] = $this->imported[ $photo_id ];
					}
				}
				return $found;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( $id ) => (string) array_search( $id, $this->imported, true )
		);

		$this->tmp = (string) tempnam( sys_get_temp_dir(), 'pfrest' );
		$tmp       = $this->tmp;
		Functions\when( 'download_url' )->alias(
			function ( $url ) use ( $tmp ) {
				$this->downloads[] = $url;
				return false !== strpos( $url, 'broken' ) ? new \WP_Error( 'http_404', 'nope' ) : $tmp;
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'media_handle_sideload' )->alias( fn() => $this->next_id++ );

		$settings   = new Settings();
		$this->rest = new Photo_Rest( new Photo_Catalogue( $this->api, $settings, $this->clock() ), new Photo_Importer( $settings ), $settings );
	}

	protected function tearDown(): void {
		if ( is_file( $this->tmp ) ) {
			unlink( $this->tmp );
		}
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
				'caption'       => 'Caption ' . $id,
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
				),
			),
			$extra
		);
	}

	/**
	 * Queues a platform list of photos.
	 *
	 * @param array<int,array<string,mixed>> $photos Photos.
	 */
	private function platform_has( array $photos ): void {
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
	 * Queues 45 photos.
	 */
	private function platform_has_many(): void {
		$photos = array();
		for ( $i = 1; $i <= 45; $i++ ) {
			$photos[] = $this->photo( 'p-' . $i );
		}
		$this->platform_has( $photos );
	}

	public function test_the_route_needs_upload_files_and_has_paging_args(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $space, $route, $args ) use ( &$routes ) {
				$routes[ $route ] = array_merge( array( 'namespace' => $space ), $args );
			}
		);

		$this->rest->register_routes();

		$this->assertSame( array( '/photos', '/photos/import', '/photos/reimport' ), array_keys( $routes ) );
		$this->assertSame( 'POST', $routes['/photos/import']['methods'] );
		$this->assertSame( array( $this->rest, 'can_upload' ), $routes['/photos/import']['permission_callback'] );
		$this->assertTrue( $routes['/photos/import']['args']['ids']['required'] );
		$this->assertArrayHasKey( 'gallery', $routes['/photos']['args'] );
		$this->assertSame( 'profotograaf/v1', $routes['/photos']['namespace'] );
		$this->assertSame( 'GET', $routes['/photos']['methods'] );
		$this->assertSame( array( $this->rest, 'can_upload' ), $routes['/photos']['permission_callback'] );
		$this->assertSame( 20, $routes['/photos']['args']['per_page']['default'] );
		$this->assertSame( 100, $routes['/photos']['args']['per_page']['maximum'] );
		$this->assertTrue( $this->rest->can_upload() );
		$this->capability = 'edit_posts';
		$this->assertFalse( $this->rest->can_upload() );
	}

	public function test_it_refuses_while_the_setting_is_off(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );

		$error = $this->rest->list_photos( new Photo_Rest_Request() );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'profotograaf_media_source_off', $error->get_error_code() );
		$this->assertSame( 403, $error->data['status'] );
		$this->assertSame( 'Using Profotograaf photos in the editor is switched off in the plugin settings.', $error->get_error_message() );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_it_answers_with_the_inserter_shape(): void {
		$this->platform_has( array( $this->photo( 'p-1', array( 'alt' => 'A bride' ) ), $this->photo( 'p-2' ) ) );

		$result = $this->rest->list_photos( new Photo_Rest_Request() );

		$this->assertSame( 2, $result['totalItems'] );
		$this->assertSame( 1, $result['totalPages'] );
		$this->assertFalse( $result['stale'] );
		$this->assertSame(
			array(
				'url'          => 'https://profotograaf.nl/share/img/a/web-0123456789ab.jpg',
				'previewUrl'   => 'https://profotograaf.nl/share/img/a/thumb-bbbbbbbbbbbb.jpg',
				'alt'          => 'A bride',
				'caption'      => 'Caption p-1',
				'title'        => 'Photo p-1',
				'sourceId'     => 'p-1',
				'type'         => 'image',
				'galleryId'    => 'g-1',
				'galleryTitle' => 'Spring wedding',
			),
			$result['items'][0]
		);
		$this->assertSame( 'Photo p-2', $result['items'][1]['alt'] );
	}

	public function test_it_pages_with_correct_totals(): void {
		$this->platform_has_many();

		$first = $this->rest->list_photos( new Photo_Rest_Request() );
		$this->assertCount( 20, $first['items'] );
		$this->assertSame( 45, $first['totalItems'] );
		$this->assertSame( 3, $first['totalPages'] );

		$last = $this->rest->list_photos( new Photo_Rest_Request( array( 'page' => 3 ) ) );
		$this->assertCount( 5, $last['items'] );
		$this->assertSame( 'p-41', $last['items'][0]['sourceId'] );

		$wide = $this->rest->list_photos( new Photo_Rest_Request( array( 'per_page' => 500 ) ) );
		$this->assertCount( 45, $wide['items'] );
		$this->assertSame( 1, $wide['totalPages'] );

		$small = $this->rest->list_photos(
			new Photo_Rest_Request(
				array(
					'per_page' => 10,
					'page'     => 5,
				)
			)
		);
		$this->assertCount( 5, $small['items'] );
		$this->assertSame( 5, $small['totalPages'] );

		$this->assertCount( 1, $this->http->requests );
	}

	public function test_it_searches_the_catalogue(): void {
		$this->platform_has(
			array(
				$this->photo( 'p-1', array( 'title' => 'Church door' ) ),
				$this->photo( 'p-2', array( 'gallery_title' => 'Autumn walk' ) ),
				$this->photo( 'p-3', array( 'title' => 'Church bells' ) ),
			)
		);

		$result = $this->rest->list_photos( new Photo_Rest_Request( array( 'search' => 'church' ) ) );

		$this->assertSame( 2, $result['totalItems'] );
		$this->assertSame( array( 'p-1', 'p-3' ), array_column( $result['items'], 'sourceId' ) );

		$gallery = $this->rest->list_photos( new Photo_Rest_Request( array( 'search' => 'autumn' ) ) );
		$this->assertSame( array( 'p-2' ), array_column( $gallery['items'], 'sourceId' ) );

		$none = $this->rest->list_photos( new Photo_Rest_Request( array( 'search' => 'zzz' ) ) );
		$this->assertSame( 0, $none['totalItems'] );
		$this->assertSame( 0, $none['totalPages'] );
		$this->assertSame( array(), $none['items'] );
	}

	public function test_only_imported_photos_carry_an_attachment_id(): void {
		$this->platform_has( array( $this->photo( 'p-1' ), $this->photo( 'p-2' ), $this->photo( 'p-3' ) ) );
		$this->imported = array(
			'p-1' => 71,
			'p-3' => 73,
		);

		$result = $this->rest->list_photos( new Photo_Rest_Request() );

		$this->assertSame( 71, $result['items'][0]['id'] );
		$this->assertArrayNotHasKey( 'id', $result['items'][1] );
		$this->assertSame( 73, $result['items'][2]['id'] );
	}

	public function test_a_stale_catalogue_still_answers_with_the_flag(): void {
		$this->platform_has( array( $this->photo( 'p-1' ) ) );
		( new Photo_Catalogue( $this->api, new Settings(), $this->clock() ) )->refresh();
		$this->http->reply( 500, array( 'error' => 'boom' ) );
		( new Photo_Catalogue( $this->api, new Settings(), $this->clock() ) )->refresh();

		$result = $this->rest->list_photos( new Photo_Rest_Request() );

		$this->assertTrue( $result['stale'] );
		$this->assertSame( 1, $result['totalItems'] );
	}

	public function test_a_platform_failure_without_a_stored_copy_is_a_translated_error(): void {
		$this->http->reply( 500, array( 'error' => 'boom' ) );

		$error = $this->rest->list_photos( new Photo_Rest_Request() );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 502, $error->data['status'] );
		$this->assertNotSame( '', $error->get_error_message() );
	}

	public function test_a_disconnected_site_is_a_409(): void {
		$this->options = array( Settings::OPTION => array( 'media_source' => true ) );

		$error = $this->rest->list_photos( new Photo_Rest_Request() );

		$this->assertSame( 'profotograaf_not_connected', $error->get_error_code() );
		$this->assertSame( 409, $error->data['status'] );
	}

	public function test_find_many_returns_nothing_for_no_ids(): void {
		$this->assertSame( array(), ( new Photo_Importer( new Settings() ) )->find_many( array() ) );
	}

	public function test_it_filters_by_gallery_and_lists_the_galleries_with_counts(): void {
		$this->platform_has(
			array(
				$this->photo( 'p-1' ),
				$this->photo(
					'p-2',
					array(
						'gallery_id'    => 'g-2',
						'gallery_title' => 'Autumn walk',
					)
				),
				$this->photo( 'p-3' ),
			)
		);

		$all = $this->rest->list_photos( new Photo_Rest_Request() );
		$this->assertSame(
			array(
				array(
					'id'    => 'g-1',
					'title' => 'Spring wedding',
					'count' => 2,
				),
				array(
					'id'    => 'g-2',
					'title' => 'Autumn walk',
					'count' => 1,
				),
			),
			$all['galleries']
		);

		$one = $this->rest->list_photos( new Photo_Rest_Request( array( 'gallery' => 'g-2' ) ) );
		$this->assertSame( array( 'p-2' ), array_column( $one['items'], 'sourceId' ) );
		$this->assertSame( 1, $one['totalItems'] );
		$this->assertCount( 2, $one['galleries'] );

		$searched = $this->rest->list_photos( new Photo_Rest_Request( array( 'search' => 'autumn' ) ) );
		$this->assertSame( array( 'g-2' ), array_column( $searched['galleries'], 'id' ) );
	}

	public function test_import_refuses_while_the_setting_is_off(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );

		$error = $this->rest->import_photos( new Photo_Rest_Request( array( 'ids' => array( 'p-1' ) ) ) );

		$this->assertSame( 'profotograaf_media_source_off', $error->get_error_code() );
		$this->assertSame( 403, $error->data['status'] );
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_import_needs_at_least_one_id(): void {
		$error = $this->rest->import_photos( new Photo_Rest_Request( array( 'ids' => array( '', ' ' ) ) ) );

		$this->assertSame( 'profotograaf_import_no_ids', $error->get_error_code() );
		$this->assertSame( 400, $error->data['status'] );
	}

	public function test_import_takes_at_most_fifty_ids(): void {
		$ids = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$ids[] = 'p-' . $i;
		}

		$error = $this->rest->import_photos( new Photo_Rest_Request( array( 'ids' => $ids ) ) );

		$this->assertSame( 'profotograaf_import_too_many', $error->get_error_code() );
		$this->assertSame( 'Import at most 50 photos at a time.', $error->get_error_message() );
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( array(), $this->downloads );

		$fifty = array_slice( $ids, 0, 50 );
		$this->platform_has_many();
		$ok = $this->rest->import_photos( new Photo_Rest_Request( array( 'ids' => $fifty ) ) );
		$this->assertCount( 45, array_filter( $ok['results'], fn( $r ) => isset( $r['attachment_id'] ) ) );
		$this->assertCount( 5, array_filter( $ok['results'], fn( $r ) => isset( $r['error'] ) ) );
	}

	public function test_import_uses_catalogue_data_and_reports_each_photo(): void {
		$this->platform_has(
			array(
				$this->photo( 'p-1' ),
				$this->photo( 'p-2', array( 'full_url' => 'https://profotograaf.nl/share/img/b/broken-0123456789ab.jpg' ) ),
				$this->photo( 'p-3' ),
			)
		);
		$this->imported = array( 'p-3' => 73 );

		$result = $this->rest->import_photos(
			new Photo_Rest_Request(
				array(
					'ids' => array( 'p-1', 'p-2', 'p-3', 'p-404', 'p-1', 'p-1' ),
					'url' => 'https://evil.example/x.jpg',
				)
			)
		)['results'];

		$this->assertSame( array( 'p-1', 'p-2', 'p-3', 'p-404' ), array_column( $result, 'id' ) );
		$this->assertSame( 900, $result[0]['attachment_id'] );
		$this->assertSame( 'The photo could not be downloaded from Profotograaf. Try again later.', $result[1]['error'] );
		$this->assertSame( 73, $result[2]['attachment_id'] );
		$this->assertStringContainsString( 'not in your Profotograaf library', $result[3]['error'] );
		$this->assertSame(
			array(
				'https://profotograaf.nl/share/img/a/web-0123456789ab.jpg',
				'https://profotograaf.nl/share/img/b/broken-0123456789ab.jpg',
			),
			$this->downloads
		);
	}

	public function test_import_passes_a_catalogue_failure_on(): void {
		$this->http->reply( 500, array( 'error' => 'boom' ) );

		$error = $this->rest->import_photos( new Photo_Rest_Request( array( 'ids' => array( 'p-1' ) ) ) );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 502, $error->data['status'] );
	}
}
