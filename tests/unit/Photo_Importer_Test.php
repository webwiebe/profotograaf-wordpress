<?php
/**
 * Photo importer tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Profotograaf\Photo_Importer;
use Profotograaf\Settings;

class Photo_Importer_Test extends Wp_Test_Case {

	private Photo_Importer $importer;

	/**
	 * Post meta written, by attachment id then key.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Arguments of each media_handle_sideload call.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $sideloads = array();

	/**
	 * URLs passed to download_url.
	 *
	 * @var array<int,string>
	 */
	private array $downloads = array();

	/**
	 * Attachment ids already in the library, by photo id.
	 *
	 * @var array<string,int>
	 */
	private array $existing = array();

	private int $next_id = 501;

	/**
	 * Callbacks registered with add_filter, by hook.
	 *
	 * @var array<string,callable>
	 */
	private array $filters = array();

	private int $pauses = 0;

	/**
	 * Runs on each wait for a held lock.
	 *
	 * @var callable|null
	 */
	private $on_pause = null;

	private function lock_name(): string {
		return 'profotograaf_import_lock_' . md5( 'p-1' );
	}

	private string $tmp = '';

	/**
	 * The stored original the baseline hashes.
	 */
	private string $original = '';

	protected function setUp(): void {
		parent::setUp();
		$this->options['profotograaf_settings'] = array( 'media_source' => true );

		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'upload_files' === $cap );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'wp_delete_file' )->alias(
			function ( $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);
		Functions\when( 'get_posts' )->alias(
			function ( $args ) {
				$this->assertSame( 'attachment', $args['post_type'] );
				$this->assertSame( '_profotograaf_photo_id', $args['meta_key'] );
				$id = $this->existing[ $args['meta_value'] ] ?? null;
				return null === $id ? array() : array( $id );
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'media_handle_sideload' )->alias(
			function ( $file, $post_id, $desc, $data ) {
				$this->sideloads[] = array(
					'file' => $file,
					'post' => $post_id,
					'desc' => $desc,
					'data' => $data,
				);
				return $this->next_id++;
			}
		);

		$this->tmp = (string) tempnam( sys_get_temp_dir(), 'pfimp' );
		$tmp       = $this->tmp;
		Functions\when( 'download_url' )->alias(
			function ( $url ) use ( $tmp ) {
				$this->downloads[] = $url;
				return $tmp;
			}
		);

		$this->original = (string) tempnam( sys_get_temp_dir(), 'pforg' );
		file_put_contents( $this->original, 'stored original' );
		$original = $this->original;
		Functions\when( 'wp_get_original_image_path' )->alias( fn() => $original );
		Functions\when( 'get_attached_file' )->alias( fn() => $original );

		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) {
				$this->filters[ $hook ] = $callback;
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( $hook ) {
				unset( $this->filters[ $hook ] );
				return true;
			}
		);

		$this->importer = new Photo_Importer(
			new Settings(),
			function (): void {
				++$this->pauses;
				if ( null !== $this->on_pause ) {
					( $this->on_pause )();
				}
			}
		);
	}

	protected function tearDown(): void {
		if ( is_file( $this->tmp ) ) {
			unlink( $this->tmp );
		}
		if ( is_file( $this->original ) ) {
			unlink( $this->original );
		}
		parent::tearDown();
	}

	/**
	 * A catalogue item.
	 *
	 * @param array<string,mixed> $overrides Fields to replace.
	 * @return array<string,mixed>
	 */
	private function photo( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'         => 'p-1',
				'gallery_id' => 'g-1',
				'title'      => 'Bride',
				'caption'    => 'First dance',
				'alt'        => 'A bride dancing',
				'web_url'    => 'https://profotograaf.nl/share/img/a1/web-abc123def456.jpg',
				'version'    => 'abc123def456',
			),
			$overrides
		);
	}

	public function test_it_downloads_the_web_variant_and_stores_the_photo_with_its_meta(): void {
		Actions\expectDone( 'profotograaf_photo_imported' )->once()->with( 501, $this->photo() );

		$result = $this->importer->import( $this->photo() );

		$this->assertSame( 501, $result );
		$this->assertSame( array( 'https://profotograaf.nl/share/img/a1/web-abc123def456.jpg' ), $this->downloads );
		$this->assertSame( 'profotograaf-p-1.jpg', $this->sideloads[0]['file']['name'] );
		$this->assertSame( $this->tmp, $this->sideloads[0]['file']['tmp_name'] );
		$this->assertSame( 0, $this->sideloads[0]['post'] );
		$this->assertSame( 'Bride', $this->sideloads[0]['data']['post_title'] );
		$this->assertSame( 'First dance', $this->sideloads[0]['data']['post_excerpt'] );
		$this->assertSame(
			array(
				'_profotograaf_photo_id'   => 'p-1',
				'_profotograaf_gallery_id' => 'g-1',
				'_profotograaf_version'    => 'abc123def456',
				'_wp_attachment_image_alt' => 'A bride dancing',
				'_profotograaf_origin'     => 'import',
				'_profotograaf_text_hash'  => sha1( (string) json_encode( array( 'Bride', 'First dance', 'A bride dancing' ) ) ),
				'_profotograaf_file_hash'  => sha1( 'stored original' ),
			),
			$this->meta[501]
		);
	}

	public function test_the_text_baseline_hashes_the_stored_alt_with_the_title_fallback(): void {
		$this->importer->import( $this->photo( array( 'alt' => '' ) ) );

		$this->assertSame( sha1( (string) json_encode( array( 'Bride', 'First dance', 'Bride' ) ) ), $this->meta[501]['_profotograaf_text_hash'] );
	}

	public function test_an_unreadable_original_gets_no_file_hash(): void {
		Functions\when( 'wp_get_original_image_path' )->justReturn( false );
		Functions\when( 'get_attached_file' )->justReturn( '/nowhere/missing.jpg' );

		$this->importer->import( $this->photo() );

		$this->assertArrayNotHasKey( '_profotograaf_file_hash', $this->meta[501] );
		$this->assertSame( 'import', $this->meta[501]['_profotograaf_origin'] );
	}

	public function test_the_alt_text_falls_back_to_the_title(): void {
		$this->importer->import( $this->photo( array( 'alt' => '' ) ) );

		$this->assertSame( 'Bride', $this->meta[501]['_wp_attachment_image_alt'] );
	}

	public function test_a_second_import_of_the_same_photo_returns_the_same_attachment(): void {
		Actions\expectDone( 'profotograaf_photo_imported' )->once();
		$first                 = $this->importer->import( $this->photo() );
		$this->existing['p-1'] = $first;

		$second = $this->importer->import( $this->photo() );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->downloads );
		$this->assertCount( 1, $this->sideloads );
	}

	public function test_it_refuses_without_the_upload_files_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = $this->importer->import( $this->photo() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_import_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_it_refuses_while_the_media_source_is_off(): void {
		$this->options['profotograaf_settings'] = array( 'media_source' => false );

		$result = $this->importer->import( $this->photo() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_media_source_off', $result->get_error_code() );
		$this->assertSame( array(), $this->downloads );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function foreign_urls(): array {
		return array(
			'other host'       => array( 'https://evil.example/share/img/a1/web-abc.jpg' ),
			'lookalike suffix' => array( 'https://profotograaf.nl.evil.example/a.jpg' ),
			'userinfo trick'   => array( 'https://profotograaf.nl@evil.example/a.jpg' ),
			'other scheme'     => array( 'file:///etc/passwd' ),
			'empty'            => array( '' ),
		);
	}

	/**
	 * @dataProvider foreign_urls
	 */
	public function test_it_refuses_a_url_outside_the_platform_host( string $url ): void {
		$result = $this->importer->import( $this->photo( array( 'web_url' => $url ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_import_host', $result->get_error_code() );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_it_follows_a_platform_url_override(): void {
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => 'profotograaf_platform_url' === $hook ? 'https://platform.test' : $value );

		$result = $this->importer->import( $this->photo( array( 'web_url' => 'https://platform.test/share/img/a1/web-abc.jpg' ) ) );

		$this->assertSame( 501, $result );
	}

	public function test_it_refuses_a_photo_without_an_id(): void {
		$result = $this->importer->import( $this->photo( array( 'id' => '' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_import_invalid', $result->get_error_code() );
	}

	public function test_a_download_failure_returns_an_error_and_creates_nothing(): void {
		Functions\when( 'download_url' )->justReturn( new \WP_Error( 'http_404', 'Not Found' ) );
		Actions\expectDone( 'profotograaf_photo_imported' )->never();

		$result = $this->importer->import( $this->photo() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_import_download', $result->get_error_code() );
		$this->assertTrue( $result->data['retryable'] );
		$this->assertSame( array(), $this->sideloads );
		$this->assertSame( array(), $this->meta );
	}

	public function test_a_sideload_failure_removes_the_temp_file_and_returns_an_error(): void {
		Functions\when( 'media_handle_sideload' )->justReturn( new \WP_Error( 'upload_error', 'Disk full' ) );
		Actions\expectDone( 'profotograaf_photo_imported' )->never();

		$result = $this->importer->import( $this->photo() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_import_failed', $result->get_error_code() );
		$this->assertFileDoesNotExist( $this->tmp );
		$this->assertSame( array(), $this->meta );
	}

	public function test_a_held_lock_makes_the_import_return_the_attachment_the_other_request_created(): void {
		$this->options[ $this->lock_name() ] = time();
		$this->on_pause                      = function (): void {
			$this->existing['p-1'] = 777;
		};

		$result = $this->importer->import( $this->photo() );

		$this->assertSame( 777, $result );
		$this->assertSame( 1, $this->pauses );
		$this->assertSame( array(), $this->downloads );
		$this->assertSame( array(), $this->sideloads );
		$this->assertArrayHasKey( $this->lock_name(), $this->options, 'the other request still owns the lock' );
	}

	public function test_a_lock_that_is_released_while_waiting_lets_the_import_continue(): void {
		$this->options[ $this->lock_name() ] = time();
		$this->on_pause                      = function (): void {
			unset( $this->options[ $this->lock_name() ] );
		};

		$this->assertSame( 501, $this->importer->import( $this->photo() ) );
		$this->assertSame( 1, $this->pauses );
	}

	public function test_a_lock_that_stays_held_ends_in_a_retryable_error_and_creates_nothing(): void {
		$this->options[ $this->lock_name() ] = time();

		$result = $this->importer->import( $this->photo() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_import_busy', $result->get_error_code() );
		$this->assertTrue( $result->data['retryable'] );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_a_stale_lock_is_replaced(): void {
		$this->options[ $this->lock_name() ] = time() - Photo_Importer::LOCK_TTL - 1;

		$this->assertSame( 501, $this->importer->import( $this->photo() ) );
		$this->assertSame( 0, $this->pauses );
	}

	public function test_the_lock_is_not_autoloaded_and_is_released_after_success(): void {
		$this->importer->import( $this->photo() );

		$this->assertArrayNotHasKey( $this->lock_name(), $this->options );
		$this->assertFalse( $this->autoload[ $this->lock_name() ] );
	}

	public function test_the_lock_is_released_after_a_download_failure(): void {
		Functions\when( 'download_url' )->justReturn( new \WP_Error( 'http_404', 'Not Found', array( 'code' => 404 ) ) );

		$this->importer->import( $this->photo() );

		$this->assertArrayNotHasKey( $this->lock_name(), $this->options );
	}

	public function test_the_lock_is_released_after_a_sideload_failure(): void {
		Functions\when( 'media_handle_sideload' )->justReturn( new \WP_Error( 'upload_error', 'Disk full' ) );

		$this->importer->import( $this->photo() );

		$this->assertArrayNotHasKey( $this->lock_name(), $this->options );
	}

	public function test_the_lock_is_released_when_the_import_throws(): void {
		Functions\when( 'media_handle_sideload' )->alias(
			static function (): void {
				throw new \RuntimeException( 'boom' );
			}
		);

		try {
			$this->importer->import( $this->photo() );
			$this->fail( 'The exception should propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertArrayNotHasKey( $this->lock_name(), $this->options );
	}

	public function test_downloads_run_with_redirects_switched_off_and_the_filter_is_removed_afterwards(): void {
		$seen = null;
		Functions\when( 'download_url' )->alias(
			function () use ( &$seen ) {
				$seen = isset( $this->filters['http_request_args'] ) ? ( $this->filters['http_request_args'] )( array( 'redirection' => 5 ) ) : null;
				return $this->tmp;
			}
		);

		$this->importer->import( $this->photo() );

		$this->assertSame( array( 'redirection' => 0 ), $seen );
		$this->assertArrayNotHasKey( 'http_request_args', $this->filters );
	}

	public function test_a_redirect_is_refused_and_leaves_no_attachment_or_lock(): void {
		Functions\when( 'download_url' )->justReturn( new \WP_Error( 'http_404', 'Found', array( 'code' => 302 ) ) );
		Actions\expectDone( 'profotograaf_photo_imported' )->never();

		$result = $this->importer->import( $this->photo() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profotograaf_import_redirect', $result->get_error_code() );
		$this->assertSame( array(), $this->sideloads );
		$this->assertSame( array(), $this->meta );
		$this->assertArrayNotHasKey( $this->lock_name(), $this->options );
	}
}
