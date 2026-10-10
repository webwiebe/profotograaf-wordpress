<?php
/**
 * Conflict baseline backfill tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Media_Baseline;
use Profotograaf\Modules\Media_Baseline_Backfill;
use Profotograaf\Plugin;

class Media_Baseline_Test extends Wp_Test_Case {

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Arguments of each get_posts call.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = array();

	/**
	 * Attachment ids get_posts returns.
	 *
	 * @var array<int,int>
	 */
	private array $found = array();

	private string $file = '';

	protected function setUp(): void {
		parent::setUp();
		$this->file = (string) tempnam( sys_get_temp_dir(), 'pfbl' );
		file_put_contents( $this->file, 'bytes' );
		$file = $this->file;

		Functions\when( 'get_posts' )->alias(
			function ( $args ) {
				$this->queries[] = $args;
				return $this->found;
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_get_original_image_path' )->alias( fn() => $file );
		Functions\when( 'get_attached_file' )->alias( fn() => $file );
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				$post               = new \WP_Post();
				$post->post_title   = 'Title ' . $id;
				$post->post_excerpt = 'Caption ' . $id;
				return $post;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		if ( is_file( $this->file ) ) {
			unlink( $this->file );
		}
		parent::tearDown();
	}

	public function test_backfill_asks_for_imported_copies_without_a_text_baseline(): void {
		Media_Baseline::backfill();

		$query = $this->queries[0]['meta_query'];
		$this->assertSame( '_profotograaf_photo_id', $query[0]['key'] );
		$this->assertSame( 'EXISTS', $query[0]['compare'] );
		$this->assertSame( '_profotograaf_text_hash', $query[1]['key'] );
		$this->assertSame( 'NOT EXISTS', $query[1]['compare'] );
		$this->assertSame( Media_Baseline::BACKFILL_BATCH, $this->queries[0]['posts_per_page'] );
	}

	public function test_backfill_records_the_current_values_as_the_baseline(): void {
		$this->found   = array( 7 );
		$this->meta[7] = array( '_wp_attachment_image_alt' => 'Alt 7' );

		$this->assertSame( 1, Media_Baseline::backfill() );

		$this->assertSame( 'import', $this->meta[7]['_profotograaf_origin'] );
		$this->assertSame( sha1( 'bytes' ), $this->meta[7]['_profotograaf_file_hash'] );
		$this->assertSame( sha1( (string) json_encode( array( 'Title 7', 'Caption 7', 'Alt 7' ) ) ), $this->meta[7]['_profotograaf_text_hash'] );
	}

	public function test_backfill_keeps_an_origin_and_file_hash_that_exist(): void {
		$this->found   = array( 7 );
		$this->meta[7] = array(
			'_profotograaf_origin'    => 'upload',
			'_profotograaf_file_hash' => 'earlier',
		);

		Media_Baseline::backfill();

		$this->assertSame( 'upload', $this->meta[7]['_profotograaf_origin'] );
		$this->assertSame( 'earlier', $this->meta[7]['_profotograaf_file_hash'] );
		$this->assertArrayHasKey( '_profotograaf_text_hash', $this->meta[7] );
	}

	public function test_backfill_leaves_the_file_hash_out_when_the_file_is_unreadable(): void {
		Functions\when( 'wp_get_original_image_path' )->justReturn( false );
		Functions\when( 'get_attached_file' )->justReturn( '/nowhere/gone.jpg' );
		$this->found = array( 7 );

		Media_Baseline::backfill();

		$this->assertArrayNotHasKey( '_profotograaf_file_hash', $this->meta[7] );
		$this->assertArrayHasKey( '_profotograaf_text_hash', $this->meta[7] );
	}

	public function test_the_module_runs_in_the_admin_and_marks_itself_done_on_a_short_batch(): void {
		$hooked = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$hooked ) {
				$hooked[ $hook ] = $callback;
			}
		);
		$module = new Media_Baseline_Backfill();
		$module->register( new Plugin() );
		$this->assertSame( array( $module, 'run' ), $hooked['admin_init'] );

		$this->found = array( 7 );
		$module->run();

		$this->assertSame( 'done', $this->options[ Media_Baseline::BACKFILL_OPTION ] );
	}

	public function test_the_module_keeps_going_while_batches_are_full(): void {
		$this->found = range( 1, Media_Baseline::BACKFILL_BATCH );
		( new Media_Baseline_Backfill() )->run();

		$this->assertArrayNotHasKey( Media_Baseline::BACKFILL_OPTION, $this->options );
	}

	public function test_the_module_does_nothing_once_done(): void {
		$this->options[ Media_Baseline::BACKFILL_OPTION ] = 'done';

		( new Media_Baseline_Backfill() )->run();

		$this->assertSame( array(), $this->queries );
	}
}
