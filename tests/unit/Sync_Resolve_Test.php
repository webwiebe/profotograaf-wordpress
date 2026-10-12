<?php
/**
 * Resolving a conflict: Keep mine, Use Profotograaf's version, Keep both and Update text.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Media_Baseline;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Reconcile;
use Profotograaf\Sync_Actions_Rest;
use Profotograaf\Sync_Resolve;

class Sync_Resolve_Test extends Wp_Test_Case {

	private const ID = 21;

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Title and caption by attachment id.
	 *
	 * @var array<int,array{title:string,caption:string}>
	 */
	private array $posts = array();

	/**
	 * Catalogue rows the fake catalogue returns.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $photos = array();

	/**
	 * Attachment ids the re-import was asked for.
	 *
	 * @var array<int,int>
	 */
	private array $reimported = array();

	/**
	 * What the re-import answers.
	 *
	 * @var int|\WP_Error|null
	 */
	private $reimport_result = null;

	/**
	 * Attachment ids wp_delete_attachment() was called with.
	 *
	 * @var array<int,int>
	 */
	private array $deleted = array();

	/**
	 * Post arrays wp_insert_attachment() received.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $inserted = array();

	private string $file = '';

	protected function setUp(): void {
		parent::setUp();
		$this->file = (string) tempnam( sys_get_temp_dir(), 'pfrs' );
		file_put_contents( $this->file, 'local bytes' );
		$file = $this->file;

		$this->posts[ self::ID ]                   = array(
			'title'   => 'My title',
			'caption' => 'My caption',
		);
		$this->meta[ self::ID ]                    = array(
			Photo_Importer::META_PHOTO_ID  => 'p21',
			Photo_Importer::META_VERSION   => 'v1',
			Reconcile::META_STATE          => Reconcile::STATE_CONFLICT,
			Reconcile::META_REASON         => '',
			Reconcile::META_SINCE          => 5,
			Reconcile::META_REMOTE_VERSION => 'v2',
			'_wp_attachment_image_alt'     => 'My alt',
			Media_Baseline::META_FILE_HASH => 'old-file-hash',
			Media_Baseline::META_TEXT_HASH => 'old-text-hash',
		);
		$this->options[ Reconcile::COUNTS_OPTION ] = array(
			'changed'  => 1,
			'missing'  => 0,
			'conflict' => 2,
		);
		$this->photos                              = array(
			array(
				'id'        => 'p21',
				'title'     => 'Their title',
				'caption'   => 'Their caption',
				'alt'       => '',
				'version'   => 'v2',
				'web_url'   => 'https://app.profotograaf.nl/web.jpg',
				'thumb_url' => 'https://app.profotograaf.nl/thumb.jpg',
			),
		);

		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
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
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				if ( ! isset( $this->posts[ $id ] ) ) {
					return null;
				}
				$post                 = new \WP_Post();
				$post->ID             = $id;
				$post->post_title     = $this->posts[ $id ]['title'];
				$post->post_excerpt   = $this->posts[ $id ]['caption'];
				$post->post_content   = '';
				$post->post_mime_type = 'image/jpeg';
				return $post;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( $args ) {
				if ( isset( $args['post_title'] ) ) {
					$this->posts[ $args['ID'] ]['title'] = $args['post_title'];
				}
				if ( isset( $args['post_excerpt'] ) ) {
					$this->posts[ $args['ID'] ]['caption'] = $args['post_excerpt'];
				}
				return $args['ID'];
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( (string) $value ) );
		Functions\when( 'wp_get_original_image_path' )->alias( fn() => $file );
		Functions\when( 'get_attached_file' )->alias( fn() => $file );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/medium.jpg' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/file.jpg' );
		Functions\when( 'wp_delete_attachment' )->alias(
			function ( $id ) {
				$this->deleted[] = $id;
				return true;
			}
		);
		Functions\when( 'wp_delete_file' )->justReturn( true );
		Functions\when( 'wp_unique_filename' )->alias( fn( $dir, $name ) => 'copy-' . $name );
		Functions\when( 'wp_insert_attachment' )->alias(
			function ( $args ) {
				$this->inserted[] = $args;
				return 99;
			}
		);
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( array( 'sizes' => array() ) );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
	}

	protected function tearDown(): void {
		foreach ( array( $this->file, dirname( $this->file ) . '/copy-' . basename( $this->file ) ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tearDown();
	}

	private function resolve(): Sync_Resolve {
		$catalogue = $this->createMock( Photo_Catalogue::class );
		$catalogue->method( 'get' )->willReturnCallback(
			fn() => array(
				'photos'     => $this->photos,
				'stale'      => false,
				'fetched_at' => 1,
				'dropped'    => 0,
			)
		);
		return new Sync_Resolve(
			function ( int $id ) {
				$this->reimported[] = $id;
				return $this->reimport_result ?? $id;
			},
			$catalogue
		);
	}

	public function test_the_dialog_data_names_the_images_the_file_state_and_the_differing_fields(): void {
		$this->meta[ self::ID ][ Media_Baseline::META_FILE_HASH ] = sha1( 'something else' );

		$data = $this->resolve()->compare( self::ID );

		$this->assertSame( 'https://example.com/medium.jpg', $data['site_url'] );
		$this->assertSame( 'https://app.profotograaf.nl/thumb.jpg', $data['platform_thumb'] );
		$this->assertTrue( $data['file_changed'] );
		$this->assertTrue( $data['file_edited'] );
		$this->assertSame( array( 'title', 'caption', 'alt' ), array_column( $data['fields'], 'key' ) );
		$alt = $data['fields'][2];
		$this->assertSame( 'My alt', $alt['site'] );
		$this->assertSame( '', $alt['platform'] );
		$this->assertFalse( $alt['takeable'] );
		$this->assertTrue( $data['fields'][0]['takeable'] );
	}

	public function test_a_field_that_matches_is_not_listed(): void {
		$this->posts[ self::ID ]['title'] = 'Their title';

		$keys = array_column( $this->resolve()->compare( self::ID )['fields'], 'key' );

		$this->assertSame( array( 'caption', 'alt' ), $keys );
	}

	public function test_keep_mine_accepts_the_platform_version_and_the_local_values_and_clears_the_flag(): void {
		$result = $this->resolve()->keep_mine( self::ID );

		$this->assertSame( Reconcile::STATE_SYNCED, $result['state'] );
		$this->assertSame( 'v2', $this->meta[ self::ID ][ Photo_Importer::META_VERSION ] );
		$this->assertSame( sha1( 'local bytes' ), $this->meta[ self::ID ][ Media_Baseline::META_FILE_HASH ] );
		$this->assertSame( Media_Baseline::text_hash( 'My title', 'My caption', 'My alt' ), $this->meta[ self::ID ][ Media_Baseline::META_TEXT_HASH ] );
		$this->assertSame( Sync_Resolve::remote_text( $this->photos[0] )['hash'], $this->meta[ self::ID ][ Reconcile::META_ACCEPTED_TEXT ] );
		$this->assertArrayNotHasKey( Reconcile::META_STATE, $this->meta[ self::ID ] );
		$this->assertArrayNotHasKey( Reconcile::META_REMOTE_VERSION, $this->meta[ self::ID ] );
		$this->assertSame( 1, $this->options[ Reconcile::COUNTS_OPTION ]['conflict'] );
		$this->assertSame( array(), $this->reimported );
	}

	public function test_the_text_kept_with_keep_mine_does_not_flag_again_until_the_platform_changes_it_once_more(): void {
		$this->resolve()->keep_mine( self::ID );

		$rows = Reconcile::rows( array( self::ID ), array( 'p21' => $this->photos[0] ) );
		$plan = Reconcile::plan(
			array(
				'rows'      => $rows,
				'catalogue' => array(
					'photos'  => $this->photos,
					'stale'   => false,
					'dropped' => 0,
				),
				'now'       => 100,
			)
		);
		$this->assertSame( array(), $plan['changes'] );

		$this->photos[0]['caption'] = 'Their newer caption';
		$rows                       = Reconcile::rows( array( self::ID ), array( 'p21' => $this->photos[0] ) );
		$plan                       = Reconcile::plan(
			array(
				'rows'      => $rows,
				'catalogue' => array(
					'photos'  => $this->photos,
					'stale'   => false,
					'dropped' => 0,
				),
				'now'       => 100,
			)
		);
		$this->assertSame( Reconcile::STATE_CHANGED, $plan['changes'][ self::ID ]['state'] );
	}

	public function test_use_remote_re_imports_and_clears_the_flag(): void {
		$result = $this->resolve()->use_remote( self::ID );

		$this->assertSame( array( self::ID ), $this->reimported );
		$this->assertSame( Reconcile::STATE_SYNCED, $result['state'] );
		$this->assertArrayNotHasKey( Reconcile::META_STATE, $this->meta[ self::ID ] );
		$this->assertSame( 1, $this->options[ Reconcile::COUNTS_OPTION ]['conflict'] );
	}

	public function test_a_failed_re_import_keeps_the_flag_and_the_counts(): void {
		$this->reimport_result = new \WP_Error( 'profotograaf_import_download', 'No download' );

		$result = $this->resolve()->use_remote( self::ID );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( Reconcile::STATE_CONFLICT, $this->meta[ self::ID ][ Reconcile::META_STATE ] );
		$this->assertSame( 2, $this->options[ Reconcile::COUNTS_OPTION ]['conflict'] );
	}

	public function test_keep_both_copies_the_file_without_the_platform_link_then_re_imports_the_original(): void {
		$result = $this->resolve()->keep_both( self::ID );

		$this->assertSame( 99, $result['copy_id'] );
		$this->assertSame( array( self::ID ), $this->reimported );
		$this->assertSame( 'My title', $this->inserted[0]['post_title'] );
		$this->assertSame( 'My caption', $this->inserted[0]['post_excerpt'] );
		$this->assertSame( 'My alt', $this->meta[99]['_wp_attachment_image_alt'] );
		$this->assertArrayNotHasKey( Photo_Importer::META_PHOTO_ID, $this->meta[99] );
		$this->assertArrayNotHasKey( Reconcile::META_STATE, $this->meta[99] );
		$this->assertFileExists( dirname( $this->file ) . '/copy-' . basename( $this->file ) );
		$this->assertSame( array(), $this->deleted );
		$this->assertArrayNotHasKey( Reconcile::META_STATE, $this->meta[ self::ID ] );
	}

	public function test_keep_both_removes_its_copy_when_the_re_import_fails(): void {
		$this->reimport_result = new \WP_Error( 'profotograaf_import_download', 'No download' );

		$result = $this->resolve()->keep_both( self::ID );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 99 ), $this->deleted );
		$this->assertSame( Reconcile::STATE_CONFLICT, $this->meta[ self::ID ][ Reconcile::META_STATE ] );
	}

	public function test_keep_both_changes_nothing_when_the_file_cannot_be_copied(): void {
		unlink( $this->file );

		$result = $this->resolve()->keep_both( self::ID );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->reimported );
		$this->assertSame( array(), $this->inserted );
	}

	public function test_update_text_takes_only_the_chosen_fields_and_never_an_empty_platform_value(): void {
		$result = $this->resolve()->update_text( self::ID, array( 'title', 'alt' ) );

		$this->assertSame( array( 'title' ), $result['updated'] );
		$this->assertSame( 'Their title', $this->posts[ self::ID ]['title'] );
		$this->assertSame( 'My caption', $this->posts[ self::ID ]['caption'] );
		$this->assertSame( 'My alt', $this->meta[ self::ID ]['_wp_attachment_image_alt'] );
		$this->assertSame( Media_Baseline::text_hash( 'Their title', 'My caption', 'My alt' ), $this->meta[ self::ID ][ Media_Baseline::META_TEXT_HASH ] );
	}

	public function test_update_text_leaves_a_pending_file_change_flagged(): void {
		$result = $this->resolve()->update_text( self::ID, array( 'caption' ) );

		// The file hash on disk differs from the stored baseline, so the file edit still conflicts.
		$this->assertSame( Reconcile::STATE_CONFLICT, $result['state'] );
		$this->assertSame( Reconcile::STATE_CONFLICT, $this->meta[ self::ID ][ Reconcile::META_STATE ] );
		$this->assertSame( 'v2', $this->meta[ self::ID ][ Reconcile::META_REMOTE_VERSION ] );
	}

	public function test_update_text_on_a_changed_copy_without_a_file_change_syncs_it(): void {
		$this->meta[ self::ID ][ Reconcile::META_STATE ] = Reconcile::STATE_CHANGED;
		unset( $this->meta[ self::ID ][ Reconcile::META_REMOTE_VERSION ] );

		$result = $this->resolve()->update_text( self::ID, array( 'title', 'caption' ) );

		$this->assertSame( Reconcile::STATE_SYNCED, $result['state'] );
		$this->assertArrayNotHasKey( Reconcile::META_STATE, $this->meta[ self::ID ] );
		$this->assertSame( 'Their caption', $this->posts[ self::ID ]['caption'] );
		$this->assertSame( 0, $this->options[ Reconcile::COUNTS_OPTION ]['changed'] );
	}

	public function test_a_file_that_is_in_sync_or_no_copy_is_refused(): void {
		$this->meta[ self::ID ][ Reconcile::META_STATE ] = Reconcile::STATE_MISSING;
		$this->assertInstanceOf( \WP_Error::class, $this->resolve()->keep_mine( self::ID ) );

		$this->meta[ self::ID ] = array();
		$this->assertInstanceOf( \WP_Error::class, $this->resolve()->use_remote( self::ID ) );
		$this->assertSame( array(), $this->reimported );
	}

	public function test_a_photo_that_left_the_catalogue_cannot_be_compared_or_updated(): void {
		$this->photos = array();

		$this->assertInstanceOf( \WP_Error::class, $this->resolve()->compare( self::ID ) );
		$this->assertInstanceOf( \WP_Error::class, $this->resolve()->update_text( self::ID, array( 'title' ) ) );
	}

	public function test_keep_mine_works_without_the_photo_in_the_catalogue(): void {
		$this->photos = array();

		$result = $this->resolve()->keep_mine( self::ID );

		$this->assertSame( Reconcile::STATE_SYNCED, $result['state'] );
		$this->assertSame( 'v2', $this->meta[ self::ID ][ Photo_Importer::META_VERSION ] );
		$this->assertArrayNotHasKey( Reconcile::META_ACCEPTED_TEXT, $this->meta[ self::ID ] );
	}

	public function test_the_routes_need_the_resolver_and_replacing_the_file_needs_the_confirmation(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $space, $path, $args ) use ( &$routes ) {
				$routes[ $path ] = $args;
				return true;
			}
		);
		$rest = new Sync_Actions_Rest( $this->resolve() );
		$rest->register_routes();
		$this->assertArrayHasKey( '/sync/compare', $routes );
		$this->assertArrayHasKey( '/sync/resolve', $routes );
		$this->assertArrayHasKey( '/sync/update-text', $routes );
		$this->assertSame( array( 'keep_mine', 'use_remote', 'keep_both' ), $routes['/sync/resolve']['args']['choice']['enum'] );

		$request = fn( array $params ) => new Sync_Actions_Request( $params + array( 'attachment_id' => self::ID ) );

		$unconfirmed = $rest->resolve( $request( array( 'choice' => 'use_remote' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $unconfirmed );
		$this->assertSame( 'profotograaf_confirm_required', $unconfirmed->get_error_code() );
		$this->assertSame( array(), $this->reimported );

		$this->assertSame( array( self::ID ), array( $rest->resolve( $request( array( 'choice' => 'use_remote', 'confirm' => true ) ) )['attachment_id'] ) );
		$this->assertSame( array( self::ID ), $this->reimported );
	}

	public function test_the_compare_keep_mine_and_update_text_callbacks_run_the_resolver(): void {
		$rest    = new Sync_Actions_Rest( $this->resolve() );
		$request = fn( array $params ) => new Sync_Actions_Request( $params + array( 'attachment_id' => self::ID ) );

		$this->assertSame( self::ID, $rest->compare( $request( array() ) )['attachment_id'] );
		$this->assertSame( array( 'title' ), $rest->update_text( $request( array( 'fields' => array( 'title' ) ) ) )['updated'] );
		$this->meta[ self::ID ][ Reconcile::META_STATE ] = Reconcile::STATE_CONFLICT;
		$this->assertSame( Reconcile::STATE_SYNCED, $rest->resolve( $request( array( 'choice' => 'keep_mine' ) ) )['state'] );
	}

	public function test_without_a_resolver_the_resolution_routes_answer_unavailable(): void {
		$rest    = new Sync_Actions_Rest();
		$request = new Sync_Actions_Request( array( 'attachment_id' => self::ID, 'choice' => 'keep_mine' ) );

		foreach ( array( $rest->compare( $request ), $rest->resolve( $request ), $rest->update_text( $request ) ) as $result ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'profotograaf_unavailable', $result->get_error_code() );
		}
	}
}
