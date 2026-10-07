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

		$settings   = new Settings();
		$this->rest = new Photo_Rest( new Photo_Catalogue( $this->api, $settings, $this->clock() ), new Photo_Importer( $settings ), $settings );
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

		$this->assertSame( array( '/photos' ), array_keys( $routes ) );
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
}
