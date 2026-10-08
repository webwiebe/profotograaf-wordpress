<?php
/**
 * Signed photo file route, URL signer and attachment hook tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Logger;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_File_Route;
use Profotograaf\Photo_Importer;
use Profotograaf\Photo_Url_Signer;
use Profotograaf\Settings;

/**
 * Just enough of a REST request.
 */
class Photo_File_Request {

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

class Photo_File_Route_Test extends Gallery_Test_Case {

	private Photo_File_Route $route;

	private Photo_Url_Signer $signer;

	private int $user = 7;

	/**
	 * Capability the current user has.
	 */
	private string $capability = 'upload_files';

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Attachment ids of earlier imports by photo id.
	 *
	 * @var array<string,int>
	 */
	private array $imported = array();

	/**
	 * URLs passed to download_url.
	 *
	 * @var array<int,string>
	 */
	private array $downloads = array();

	/**
	 * What download_url answers.
	 *
	 * @var string|\WP_Error|null
	 */
	private $download_result = null;

	/**
	 * Temporary file with the downloaded bytes.
	 */
	private string $tmp = '';

	/**
	 * Actions fired, by name.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $fired = array();

	protected function setUp(): void {
		parent::setUp();
		Logger::configure( false );
		$this->connect();
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );

		Functions\when( 'wp_salt' )->justReturn( 'salt-for-tests' );
		Functions\when( 'get_current_user_id' )->alias( fn() => $this->user );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $cap === $this->capability );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://site.test/wp-json/' . $path );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'wp_get_attachment_url' )->alias( fn( $id ) => 'https://site.test/uploads/' . $id . '.jpg' );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'wp_delete_file' )->alias(
			function ( $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);
		Functions\when( 'get_posts' )->alias(
			function ( $args ) {
				$id = $this->imported[ $args['meta_value'] ] ?? null;
				return null === $id ? array() : array( $id );
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( $name, ...$args ) {
				$this->fired[] = array(
					'name' => $name,
					'args' => $args,
				);
			}
		);

		$this->tmp = (string) tempnam( sys_get_temp_dir(), 'pffile' );
		file_put_contents( $this->tmp, "\xFF\xD8\xFF\xE0jpegbytes" );
		Functions\when( 'download_url' )->alias(
			function ( $url ) {
				$this->downloads[] = $url;
				return $this->download_result ?? $this->tmp;
			}
		);

		$settings     = new Settings();
		$this->signer = new Photo_Url_Signer( $this->clock() );
		$this->route  = new Photo_File_Route( new Photo_Catalogue( $this->api, $settings, $this->clock() ), new Photo_Importer( $settings ), $settings, $this->signer );
	}

	protected function tearDown(): void {
		Logger::configure( null );
		if ( is_file( $this->tmp ) ) {
			unlink( $this->tmp );
		}
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
	 * A request with a valid signature for the current user.
	 *
	 * @param string              $id    Photo id.
	 * @param array<string,mixed> $extra Params to replace.
	 */
	private function request( string $id = 'p-1', array $extra = array() ): Photo_File_Request {
		$signed = $this->signer->sign( $id, $this->user );
		return new Photo_File_Request(
			array_merge(
				array(
					'id'   => $id,
					'name' => Photo_File_Route::file_name( $id ),
					'exp'  => $signed['exp'],
					'sig'  => $signed['sig'],
				),
				$extra
			)
		);
	}

	public function test_a_signature_binds_photo_user_and_expiry(): void {
		$signed = $this->signer->sign( 'p-1', 7 );

		$this->assertSame( $this->now + Photo_Url_Signer::LIFETIME, $signed['exp'] );
		$this->assertTrue( $this->signer->verify( 'p-1', 7, $signed['exp'], $signed['sig'] ) );
		$this->assertFalse( $this->signer->verify( 'p-2', 7, $signed['exp'], $signed['sig'] ), 'other photo' );
		$this->assertFalse( $this->signer->verify( 'p-1', 8, $signed['exp'], $signed['sig'] ), 'other user' );
		$this->assertFalse( $this->signer->verify( 'p-1', 7, $signed['exp'] + 1, $signed['sig'] ), 'moved expiry' );
		$this->assertFalse( $this->signer->verify( 'p-1', 7, $signed['exp'], 'forged' ), 'forged signature' );
		$this->assertFalse( $this->signer->verify( 'p-1', 0, $signed['exp'], $signed['sig'] ), 'no user' );
	}

	public function test_a_signature_expires(): void {
		$signed = $this->signer->sign( 'p-1', 7 );
		$later  = new Photo_Url_Signer( fn() => $this->now + Photo_Url_Signer::LIFETIME + 1 );
		$edge   = new Photo_Url_Signer( fn() => $this->now + Photo_Url_Signer::LIFETIME );

		$this->assertFalse( $later->verify( 'p-1', 7, $signed['exp'], $signed['sig'] ) );
		$this->assertTrue( $edge->verify( 'p-1', 7, $signed['exp'], $signed['sig'] ) );
	}

	public function test_a_signature_without_a_clock_override_uses_the_current_time(): void {
		$signer = new Photo_Url_Signer();
		$signed = $signer->sign( 'p-1', 7 );

		$this->assertGreaterThan( time(), $signed['exp'] );
		$this->assertTrue( $signer->verify( 'p-1', 7, $signed['exp'], $signed['sig'] ) );
	}

	public function test_the_route_needs_upload_files_and_has_the_file_path(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $space, $route, $args ) use ( &$routes ) {
				$routes[ $route ] = array_merge( array( 'namespace' => $space ), $args );
			}
		);

		$this->route->register_routes();

		$path = '/photos/(?P<id>[A-Za-z0-9_-]+)/file/(?P<name>[A-Za-z0-9_-]+\.jpg)';
		$this->assertSame( array( $path ), array_keys( $routes ) );
		$this->assertSame( 'profotograaf/v1', $routes[ $path ]['namespace'] );
		$this->assertSame( 'GET', $routes[ $path ]['methods'] );
		$this->assertSame( array( $this->route, 'serve' ), $routes[ $path ]['callback'] );
		$this->assertTrue( $this->route->can_upload() );
		$this->capability = 'edit_posts';
		$this->assertFalse( $this->route->can_upload() );
	}

	public function test_register_adds_the_hooks(): void {
		$this->route->register();

		$this->assertNotFalse( has_action( 'rest_api_init', array( $this->route, 'register_routes' ) ) );
		$this->assertNotFalse( has_filter( 'profotograaf_photo_rest_item', array( $this->route, 'decorate_item' ) ) );
		$this->assertNotFalse( has_action( 'rest_after_insert_attachment', array( $this->route, 'tag_upload' ) ) );
	}

	public function test_the_file_name_carries_the_photo_id(): void {
		$this->assertSame( 'profotograaf-p-1.jpg', Photo_File_Route::file_name( 'p-1' ) );
		$this->assertSame( 'profotograaf-a-b.jpg', Photo_File_Route::file_name( 'a/b' ) );
	}

	public function test_an_item_gets_a_signed_same_origin_url(): void {
		$item = $this->route->decorate_item( array( 'url' => 'https://profotograaf.nl/x.jpg' ), $this->photo( 'p-1' ) );

		$signed = $this->signer->sign( 'p-1', $this->user );
		$this->assertSame(
			'https://site.test/wp-json/profotograaf/v1/photos/p-1/file/profotograaf-p-1.jpg?exp=' . $signed['exp'] . '&sig=' . $signed['sig'] . '&_wpnonce=nonce123',
			$item['url']
		);
	}

	public function test_plain_permalinks_put_the_file_name_in_the_path(): void {
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://site.test/index.php?rest_route=' . rawurlencode( '/' . $path ) );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '&' . http_build_query( $args ) );

		$item = $this->route->decorate_item( array( 'url' => 'https://profotograaf.nl/x.jpg' ), $this->photo( 'p-1' ) );

		$this->assertStringStartsWith( 'https://site.test/index.php/profotograaf-p-1.jpg?rest_route=', $item['url'] );
		$this->assertStringContainsString( '&sig=', $item['url'] );
	}

	public function test_an_imported_item_points_at_the_local_file(): void {
		$item = $this->route->decorate_item(
			array(
				'url' => 'https://profotograaf.nl/x.jpg',
				'id'  => 71,
			),
			$this->photo( 'p-1' )
		);

		$this->assertSame( 'https://site.test/uploads/71.jpg', $item['url'] );
	}

	public function test_an_imported_item_keeps_its_url_when_the_attachment_has_none(): void {
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );

		$item = $this->route->decorate_item(
			array(
				'url' => 'https://profotograaf.nl/x.jpg',
				'id'  => 71,
			),
			$this->photo( 'p-1' )
		);

		$this->assertSame( 'https://profotograaf.nl/x.jpg', $item['url'] );
	}

	public function test_items_are_left_alone_when_they_cannot_be_signed(): void {
		$original = array( 'url' => 'https://profotograaf.nl/x.jpg' );

		$this->assertSame( $original, $this->route->decorate_item( $original, $this->photo( 'a/b' ) ), 'unsafe id' );
		$this->assertSame( $original, $this->route->decorate_item( $original, array( 'id' => array() ) ), 'no scalar id' );
		$this->assertSame( 'nope', $this->route->decorate_item( 'nope', $this->photo( 'p-1' ) ), 'not an item' );

		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		$this->assertSame( $original, $this->route->decorate_item( $original, $this->photo( 'p-1' ) ), 'setting off' );
	}

	public function test_it_serves_the_web_variant_with_the_right_headers_and_streams_the_bytes(): void {
		$this->platform_has( array( $this->photo( 'p-1' ) ) );

		$response = $this->route->serve( $this->request() );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->status );
		$this->assertSame( 'image/jpeg', $response->headers['Content-Type'] );
		$this->assertSame( (string) filesize( $this->tmp ), $response->headers['Content-Length'] );
		$this->assertSame( 'nosniff', $response->headers['X-Content-Type-Options'] );
		$this->assertSame( array( 'https://profotograaf.nl/share/img/a/web-0123456789ab.jpg' ), $this->downloads );

		ob_start();
		$served = $this->route->stream( false );
		$body   = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( "\xFF\xD8\xFF\xE0jpegbytes", $body );
		$this->assertFileDoesNotExist( $this->tmp, 'the temporary file is removed' );
		$this->assertFalse( $this->route->stream( false ), 'a second call serves nothing' );
	}

	public function test_it_refuses_while_the_setting_is_off(): void {
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );

		$error = $this->route->serve( $this->request() );

		$this->assertSame( 'profotograaf_media_source_off', $error->get_error_code() );
		$this->assertSame( 403, $error->data['status'] );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_a_forged_expired_or_foreign_signature_is_refused(): void {
		$this->platform_has( array( $this->photo( 'p-1' ) ) );
		$good = $this->request();

		$cases = array(
			'forged'       => $this->request( 'p-1', array( 'sig' => 'forged' ) ),
			'no signature' => $this->request( 'p-1', array( 'sig' => '' ) ),
			'expired'      => $this->request( 'p-1', array( 'exp' => $this->now - 1 ) ),
			'other photo'  => new Photo_File_Request(
				array(
					'id'   => 'p-2',
					'name' => Photo_File_Route::file_name( 'p-2' ),
					'exp'  => $good->get_param( 'exp' ),
					'sig'  => $good->get_param( 'sig' ),
				)
			),
			'other name'   => $this->request( 'p-1', array( 'name' => 'profotograaf-p-2.jpg' ) ),
		);
		foreach ( $cases as $label => $request ) {
			$error = $this->route->serve( $request );
			$this->assertInstanceOf( \WP_Error::class, $error, $label );
			$this->assertSame( 'profotograaf_file_signature', $error->get_error_code(), $label );
			$this->assertSame( 403, $error->data['status'], $label );
		}

		$this->user = 8;
		$error      = $this->route->serve( $good );
		$this->assertSame( 'profotograaf_file_signature', $error->get_error_code(), 'signed for another user' );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_a_photo_outside_the_catalogue_is_a_404(): void {
		$this->platform_has( array( $this->photo( 'p-1' ) ) );

		$error = $this->route->serve( $this->request( 'p-9' ) );

		$this->assertSame( 'profotograaf_file_missing', $error->get_error_code() );
		$this->assertSame( 404, $error->data['status'] );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_a_catalogue_failure_is_a_translated_error(): void {
		$this->http->reply( 500, array( 'error' => 'boom' ) );

		$error = $this->route->serve( $this->request() );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 502, $error->data['status'] );
	}

	public function test_it_never_downloads_from_another_host(): void {
		$this->platform_has( array( $this->photo( 'p-1', array( 'full_url' => 'https://evil.example/share/img/a/web-0123456789ab.jpg' ) ) ) );

		$error = $this->route->serve( $this->request() );

		$this->assertSame( 'profotograaf_file_host', $error->get_error_code() );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_it_refuses_credentials_and_other_schemes_in_the_url(): void {
		foreach ( array( 'ftp://profotograaf.nl/a.jpg', 'https://user:pw@profotograaf.nl/a.jpg', 'not a url' ) as $url ) {
			unset( $this->options[ Photo_Catalogue::OPTION ] );
			$this->platform_has( array( $this->photo( 'p-1', array( 'full_url' => $url ) ) ) );

			$error = $this->route->serve( $this->request() );

			$this->assertSame( 'profotograaf_file_host', $error->get_error_code(), $url );
			$this->assertSame( array(), $this->downloads, $url );
		}
	}

	public function test_a_failed_download_is_a_retryable_translated_error(): void {
		$this->platform_has( array( $this->photo( 'p-1' ) ) );
		$this->download_result = new \WP_Error( 'http_404', 'Not Found' );

		$error = $this->route->serve( $this->request() );

		$this->assertSame( 'profotograaf_file_download', $error->get_error_code() );
		$this->assertSame( 502, $error->data['status'] );
		$this->assertTrue( $error->data['retryable'] );
		$this->assertSame( 'The photo could not be downloaded from Profotograaf. Try again later.', $error->get_error_message() );
	}

	public function test_something_other_than_a_jpeg_is_refused_and_removed(): void {
		$this->platform_has( array( $this->photo( 'p-1' ) ) );
		file_put_contents( $this->tmp, '<html>not an image</html>' );

		$error = $this->route->serve( $this->request() );

		$this->assertSame( 'profotograaf_file_type', $error->get_error_code() );
		$this->assertFileDoesNotExist( $this->tmp );
	}

	public function test_an_unreadable_download_is_refused(): void {
		$this->platform_has( array( $this->photo( 'p-1' ) ) );
		$this->download_result = $this->tmp . '-missing';

		$error = $this->route->serve( $this->request() );

		$this->assertSame( 'profotograaf_file_type', $error->get_error_code() );
	}

	/**
	 * A catalogue with p-1 and an upload of it.
	 *
	 * @param string $file Stored file name.
	 * @param int    $id   Attachment id.
	 */
	private function uploaded( string $file, int $id = 91 ): object {
		$this->platform_has( array( $this->photo( 'p-1', array( 'alt' => '' ) ) ) );
		$this->meta[ $id ]['_wp_attached_file'] = '2026/10/' . $file;
		return (object) array( 'ID' => $id );
	}

	public function test_an_upload_with_a_photo_file_name_gets_the_meta(): void {
		$attachment = $this->uploaded( 'profotograaf-p-1.jpg' );

		$this->route->tag_upload( $attachment, null, true );

		$this->assertSame( 'p-1', $this->meta[91][ Photo_Importer::META_PHOTO_ID ] );
		$this->assertSame( 'g-1', $this->meta[91][ Photo_Importer::META_GALLERY_ID ] );
		$this->assertSame( '0123456789ab', $this->meta[91][ Photo_Importer::META_VERSION ] );
		$this->assertSame( 'Photo p-1', $this->meta[91]['_wp_attachment_image_alt'] );
		$this->assertSame( 'profotograaf_photo_imported', $this->fired[0]['name'] );
		$this->assertSame( 91, $this->fired[0]['args'][0] );
	}

	public function test_a_name_with_a_wordpress_suffix_still_maps_back(): void {
		$attachment = $this->uploaded( 'profotograaf-p-1-2.jpg' );

		$this->route->tag_upload( $attachment, null, true );

		$this->assertSame( 'p-1', $this->meta[91][ Photo_Importer::META_PHOTO_ID ] );
	}

	public function test_the_platform_alt_wins_over_the_title(): void {
		$this->platform_has( array( $this->photo( 'p-1', array( 'alt' => 'A bride' ) ) ) );
		$this->meta[91]['_wp_attached_file'] = 'profotograaf-p-1.jpg';

		$this->route->tag_upload( (object) array( 'ID' => 91 ), null, true );

		$this->assertSame( 'A bride', $this->meta[91]['_wp_attachment_image_alt'] );
	}

	public function test_other_uploads_are_left_alone(): void {
		$this->route->tag_upload( $this->uploaded( 'holiday.jpg' ), null, true );
		$this->route->tag_upload( $this->uploaded( 'profotograaf-p-9.jpg', 92 ), null, true );
		$this->route->tag_upload( 'nope', null, true );
		$this->route->tag_upload( (object) array(), null, true );
		$this->route->tag_upload( $this->uploaded( 'profotograaf-p-1.jpg', 93 ), null, false );

		$this->assertArrayNotHasKey( Photo_Importer::META_PHOTO_ID, $this->meta[91] );
		$this->assertArrayNotHasKey( Photo_Importer::META_PHOTO_ID, $this->meta[92] );
		$this->assertArrayNotHasKey( Photo_Importer::META_PHOTO_ID, $this->meta[93] );
		$this->assertSame( array(), $this->fired );
	}

	public function test_a_user_without_upload_files_or_with_the_setting_off_tags_nothing(): void {
		$attachment = $this->uploaded( 'profotograaf-p-1.jpg' );

		$this->capability = 'edit_posts';
		$this->route->tag_upload( $attachment, null, true );
		$this->capability                  = 'upload_files';
		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		$this->route->tag_upload( $attachment, null, true );

		$this->assertArrayNotHasKey( Photo_Importer::META_PHOTO_ID, $this->meta[91] );
	}

	public function test_an_upload_does_not_steal_the_meta_of_an_earlier_import(): void {
		$attachment     = $this->uploaded( 'profotograaf-p-1.jpg' );
		$this->imported = array( 'p-1' => 71 );

		$this->route->tag_upload( $attachment, null, true );

		$this->assertArrayNotHasKey( Photo_Importer::META_PHOTO_ID, $this->meta[91] );
	}

	public function test_an_upload_is_left_alone_when_the_catalogue_fails(): void {
		$this->http->reply( 500, array( 'error' => 'boom' ) );
		$this->meta[91]['_wp_attached_file'] = 'profotograaf-p-1.jpg';

		$this->route->tag_upload( (object) array( 'ID' => 91 ), null, true );

		$this->assertArrayNotHasKey( Photo_Importer::META_PHOTO_ID, $this->meta[91] );
	}
}
