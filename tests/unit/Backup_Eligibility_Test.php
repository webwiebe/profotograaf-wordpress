<?php
/**
 * Backup eligibility: scopes, types, original files, skip reasons, the
 * filter and the queue state.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Profotograaf\Backup_Eligibility;
use Profotograaf\Backup_State;
use Profotograaf\Backup_Usage;
use Profotograaf\Photo_Importer;
use Profotograaf\Settings;

class Backup_Eligibility_Test extends Wp_Test_Case {

	/**
	 * Posts by id: attachments and published content.
	 *
	 * @var array<int,\WP_Post>
	 */
	private array $posts = array();

	/**
	 * Post meta by post id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Attached file per attachment id.
	 *
	 * @var array<int,string>
	 */
	private array $attached = array();

	/**
	 * Original image (before scaling) per attachment id.
	 *
	 * @var array<int,string>
	 */
	private array $originals = array();

	/**
	 * Temporary directory of this test.
	 */
	private string $dir = '';

	/**
	 * Arguments of every get_posts call.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		$this->now = 1700000000;
		$this->dir = sys_get_temp_dir() . '/pfbackup-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );

		Functions\when( 'get_post' )->alias( fn( $id ) => $this->posts[ (int) $id ] ?? null );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ (int) $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ (int) $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'get_attached_file' )->alias( fn( $id ) => $this->attached[ (int) $id ] ?? false );
		Functions\when( 'wp_get_original_image_path' )->alias( fn( $id ) => $this->originals[ (int) $id ] ?? ( $this->attached[ (int) $id ] ?? false ) );
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'       => 'post',
				'page'       => 'page',
				'attachment' => 'attachment',
			)
		);
		Functions\when( 'get_posts' )->alias( fn( $args ) => $this->query( $args ) );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
		parent::tearDown();
	}

	/**
	 * The fake get_posts: published content in pages, or attachments by meta.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<int,mixed>
	 */
	private function query( array $args ): array {
		$this->queries[] = $args;
		if ( 'attachment' === $args['post_type'] ) {
			$ids = array();
			foreach ( $this->meta as $id => $meta ) {
				if ( ( $meta[ $args['meta_key'] ] ?? null ) === $args['meta_value'] ) {
					$ids[] = $id;
				}
			}
			sort( $ids );
			return array_slice( $ids, 0, $args['posts_per_page'] );
		}
		$found = array_values(
			array_filter(
				$this->posts,
				fn( $post ) => in_array( $post->post_type, (array) $args['post_type'], true ) && $args['post_status'] === $post->post_status
			)
		);
		return array_slice( $found, ( $args['paged'] - 1 ) * $args['posts_per_page'], $args['posts_per_page'] );
	}

	/**
	 * Adds an attachment, with a file on disk unless $bytes is null.
	 *
	 * @param int      $id    Attachment id.
	 * @param string   $mime  MIME type.
	 * @param int|null $bytes File size, null for a missing file.
	 */
	private function attachment( int $id, string $mime = 'image/jpeg', ?int $bytes = 10 ): void {
		$post                  = new \WP_Post();
		$post->ID              = $id;
		$post->post_type       = 'attachment';
		$post->post_status     = 'inherit';
		$post->post_mime_type  = $mime;
		$this->posts[ $id ]    = $post;
		$path                  = $this->dir . '/file-' . $id;
		$this->attached[ $id ] = $path;
		if ( null !== $bytes ) {
			$handle = fopen( $path, 'w' );
			ftruncate( $handle, $bytes );
			fclose( $handle );
		}
	}

	/**
	 * Adds published (or other) content.
	 *
	 * @param int    $id      Post id.
	 * @param string $content Post content.
	 * @param string $type    Post type.
	 * @param string $status  Post status.
	 */
	private function content( int $id, string $content, string $type = 'post', string $status = 'publish' ): void {
		$post               = new \WP_Post();
		$post->ID           = $id;
		$post->post_type    = $type;
		$post->post_status  = $status;
		$post->post_content = $content;
		$this->posts[ $id ] = $post;
	}

	private function eligibility( string $scope ): Backup_Eligibility {
		$this->options[ Settings::OPTION ] = array(
			'backup'       => true,
			'backup_scope' => $scope,
		);
		return new Backup_Eligibility( new Settings(), $this->state() );
	}

	private function state(): Backup_State {
		return new Backup_State( fn() => $this->now );
	}

	/**
	 * The library the scope tests share.
	 *
	 * 1 image in a published post, 2 featured image of a published page,
	 * 3 image nobody uses, 4 image marked and unused, 5 PDF in a post,
	 * 6 photo imported from Profotograaf in a post, 7 image in a post whose
	 * file is gone, 8 image in a post above the size cap, 9 video in a post,
	 * 10 RAW file marked, 11 SVG in a post, 12 image only in a draft.
	 */
	private function library(): void {
		foreach ( array( 1, 2, 3, 4, 6 ) as $id ) {
			$this->attachment( $id );
		}
		$this->attachment( 5, 'application/pdf' );
		$this->attachment( 7, 'image/jpeg', null );
		$this->attachment( 8, 'image/jpeg', Backup_Eligibility::MAX_BYTES + 1 );
		$this->attachment( 9, 'video/mp4' );
		$this->attachment( 10, 'image/x-canon-cr3' );
		$this->attachment( 11, 'image/svg+xml' );
		$this->attachment( 12 );
		$this->meta[4][ Backup_State::META_MARKED ]     = '1';
		$this->meta[10][ Backup_State::META_MARKED ]    = '1';
		$this->meta[6][ Photo_Importer::META_PHOTO_ID ] = 'p-6';
		$this->content( 100, '<!-- wp:image {"id":1,"sizeSlug":"large"} --><figure><img class="wp-image-1" /></figure><!-- /wp:image -->[gallery ids="5,6"]' );
		$this->content( 101, '<p><img class="wp-image-7" /><img class="wp-image-8" /></p><!-- wp:video {"id":9} --><!-- /wp:video --><!-- wp:image {"id":11} /-->' );
		$this->content( 102, 'About us', 'page' );
		$this->meta[102]['_thumbnail_id'] = '2';
		$this->content( 103, '<!-- wp:image {"id":12} /-->', 'post', 'draft' );
	}

	/**
	 * Decision per attachment id of the library.
	 *
	 * @return array<int,string> Id => decision, with the reason after a colon.
	 */
	private function decisions( Backup_Eligibility $eligibility ): array {
		$out = array();
		foreach ( range( 1, 12 ) as $id ) {
			$result     = $eligibility->check( $id );
			$out[ $id ] = Backup_Eligibility::QUEUE === $result['decision'] ? 'queue' : $result['decision'] . ':' . $result['reason'];
		}
		return $out;
	}

	public function test_scope_used_takes_published_and_marked_files(): void {
		$this->library();

		$this->assertSame(
			array(
				1  => 'queue',
				2  => 'queue',
				3  => 'ignore:out_of_scope',
				4  => 'queue',
				5  => 'ignore:unsupported_type',
				6  => 'skip:imported',
				7  => 'skip:missing_file',
				8  => 'skip:too_large',
				9  => 'queue',
				10 => 'queue',
				11 => 'ignore:unsupported_type',
				12 => 'ignore:out_of_scope',
			),
			$this->decisions( $this->eligibility( 'used' ) )
		);
	}

	public function test_scope_all_takes_every_supported_file(): void {
		$this->library();

		$this->assertSame(
			array(
				1  => 'queue',
				2  => 'queue',
				3  => 'queue',
				4  => 'queue',
				5  => 'ignore:unsupported_type',
				6  => 'skip:imported',
				7  => 'skip:missing_file',
				8  => 'skip:too_large',
				9  => 'queue',
				10 => 'queue',
				11 => 'ignore:unsupported_type',
				12 => 'queue',
			),
			$this->decisions( $this->eligibility( 'all' ) )
		);
		$this->assertSame( array(), array_filter( $this->queries, fn( $args ) => 'attachment' !== $args['post_type'] ), 'Scope all reads no posts.' );
	}

	public function test_scope_marked_takes_only_marked_files(): void {
		$this->library();

		$this->assertSame(
			array(
				1  => 'ignore:out_of_scope',
				2  => 'ignore:out_of_scope',
				3  => 'ignore:out_of_scope',
				4  => 'queue',
				5  => 'ignore:unsupported_type',
				6  => 'ignore:out_of_scope',
				7  => 'ignore:out_of_scope',
				8  => 'ignore:out_of_scope',
				9  => 'ignore:out_of_scope',
				10 => 'queue',
				11 => 'ignore:unsupported_type',
				12 => 'ignore:out_of_scope',
			),
			$this->decisions( $this->eligibility( 'marked' ) )
		);
	}

	public function test_a_marked_imported_photo_is_skipped_with_its_reason(): void {
		$this->attachment( 1 );
		$this->meta[1][ Backup_State::META_MARKED ]     = '1';
		$this->meta[1][ Photo_Importer::META_PHOTO_ID ] = 'p-1';

		$this->assertSame( 'skipped', $this->eligibility( 'marked' )->apply( 1 ) );
		$this->assertSame( 'imported', $this->meta[1][ Backup_State::META_REASON ] );
	}

	public function test_a_non_attachment_is_ignored(): void {
		$this->content( 100, 'Hello' );

		$this->assertSame( 'not_attachment', $this->eligibility( 'all' )->check( 100 )['reason'] );
		$this->assertSame( 'not_attachment', $this->eligibility( 'all' )->check( 999 )['reason'] );
	}

	public function test_the_supported_types_are_images_raw_files_and_videos(): void {
		foreach ( array( 'image/jpeg', 'IMAGE/PNG', 'image/webp', 'image/gif', 'image/avif', 'image/heic', 'image/tiff', 'image/x-nikon-nef', 'image/x-adobe-dng', 'video/mp4', 'video/quicktime' ) as $type ) {
			$this->assertTrue( Backup_Eligibility::supported_type( $type ), $type );
		}
		foreach ( array( 'image/svg+xml', 'image/x-icon', 'audio/mpeg', 'application/pdf', 'text/plain', 'video/', '' ) as $type ) {
			$this->assertFalse( Backup_Eligibility::supported_type( $type ), $type );
		}
	}

	public function test_the_file_sent_is_the_original_of_a_scaled_image(): void {
		$this->attachment( 1 );
		$original           = $this->dir . '/original-1';
		$this->originals[1] = $original;
		file_put_contents( $original, 'original bytes' );
		$eligibility = $this->eligibility( 'all' );

		$this->assertSame( $original, $eligibility->original_file( 1 ) );
		$this->assertSame( $original, $eligibility->check( 1 )['file'] );

		unlink( $original );
		$this->assertSame( $this->attached[1], $eligibility->original_file( 1 ), 'Without the original the attached file is sent.' );

		unlink( $this->attached[1] );
		$this->assertSame( '', $eligibility->original_file( 1 ) );
	}

	public function test_the_size_check_reads_the_original_file(): void {
		$this->attachment( 1 );
		$original           = $this->dir . '/original-1';
		$this->originals[1] = $original;
		$handle             = fopen( $original, 'w' );
		ftruncate( $handle, Backup_Eligibility::MAX_BYTES + 1 );
		fclose( $handle );

		$this->assertSame( 'too_large', $this->eligibility( 'all' )->check( 1 )['reason'] );

		$handle = fopen( $original, 'w' );
		ftruncate( $handle, Backup_Eligibility::MAX_BYTES );
		fclose( $handle );
		$this->assertSame( Backup_Eligibility::QUEUE, $this->eligibility( 'all' )->check( 1 )['decision'], 'A file of exactly the cap is sent.' );
	}

	public function test_the_filter_can_exclude_an_attachment(): void {
		$this->attachment( 1 );
		$this->attachment( 2 );
		Filters\expectApplied( Backup_Eligibility::FILTER )->twice()->andReturnUsing( fn( $upload, $id ) => 2 !== $id );
		$eligibility = $this->eligibility( 'all' );

		$this->assertSame( 'queued', $eligibility->apply( 1 ) );
		$this->assertSame( 'skipped', $eligibility->apply( 2 ) );
		$this->assertSame( 'excluded', $this->meta[2][ Backup_State::META_REASON ] );
	}

	public function test_the_filter_does_not_run_for_files_the_rules_skip(): void {
		$this->attachment( 1, 'image/jpeg', null );
		Filters\expectApplied( Backup_Eligibility::FILTER )->never();

		$this->assertSame( 'missing_file', $this->eligibility( 'all' )->check( 1 )['reason'] );
	}

	public function test_apply_records_the_state_and_keeps_done_and_failed_items(): void {
		$this->library();
		$eligibility = $this->eligibility( 'used' );

		$states = array();
		foreach ( range( 1, 12 ) as $id ) {
			$states[ $id ] = $eligibility->apply( $id );
		}

		$this->assertSame( array( 1, 2, 4, 9, 10 ), array_keys( array_filter( $states, fn( $s ) => 'queued' === $s ) ) );
		$this->assertSame( array( 6, 7, 8 ), array_keys( array_filter( $states, fn( $s ) => 'skipped' === $s ) ) );
		$this->assertSame( array( 3, 5, 11, 12 ), array_keys( array_filter( $states, fn( $s ) => '' === $s ) ) );
		$this->assertSame( 'missing_file', $this->meta[7][ Backup_State::META_REASON ] );
		$this->assertSame( 'too_large', $this->meta[8][ Backup_State::META_REASON ] );
		$this->assertArrayNotHasKey( 3, $this->meta, 'An ignored item gets no meta.' );
		$this->assertSame( array( 1, 2, 4 ), $this->state()->queued( 3 ) );

		$state = $this->state();
		$state->done( 1, 'ph-1', 'g-1', 'abc' );
		$state->fail( 2, 'http_500' );
		unlink( $this->attached[1] );
		unlink( $this->attached[2] );
		$this->assertSame( 'done', $eligibility->apply( 1 ), 'A done item is copied once and keeps its state.' );
		$this->assertSame( 'failed', $eligibility->apply( 2 ), 'A failed item waits for a retry.' );
	}

	public function test_apply_reevaluates_queued_and_skipped_items(): void {
		$this->attachment( 1 );
		$this->attachment( 2, 'image/jpeg', null );
		$this->meta[1][ Backup_State::META_MARKED ] = '1';
		$this->meta[2][ Backup_State::META_MARKED ] = '1';
		$eligibility                                = $this->eligibility( 'marked' );

		$this->assertSame( 'queued', $eligibility->apply( 1 ) );
		$this->assertSame( 'skipped', $eligibility->apply( 2 ) );

		$this->now = 1700000500;
		$this->assertSame( 'queued', $eligibility->apply( 1 ) );
		$this->assertSame( 1700000000, $this->meta[1][ Backup_State::META_AT ], 'A queued item that stays queued is left alone.' );
		$this->assertSame( 'skipped', $eligibility->apply( 2 ) );
		$this->assertSame( 1700000000, $this->meta[2][ Backup_State::META_AT ], 'The same skip is not written again.' );

		file_put_contents( $this->attached[2], 'back' );
		$this->assertSame( 'queued', $eligibility->apply( 2 ) );
		$this->assertArrayNotHasKey( Backup_State::META_REASON, $this->meta[2] );

		$this->state()->set_marked( 1, false );
		$this->assertSame( '', $eligibility->apply( 1 ), 'An unmarked file leaves the queue.' );
		$this->assertSame( array(), $this->meta[1] );
	}

	public function test_the_state_records_failures_and_the_result_of_an_upload(): void {
		$state = $this->state();

		$this->assertSame( '', $state->state( 1 ) );
		$state->queue( 1 );
		$this->assertSame( 'queued', $state->state( 1 ) );
		$state->fail( 1, 'timeout' );
		$state->fail( 1, 'http_503' );
		$this->assertSame( 'failed', $state->state( 1 ) );
		$this->assertSame( 'http_503', $state->reason( 1 ) );
		$this->assertSame( 2, $state->failures( 1 ) );

		$state->done( 1, 'ph-1', 'g-1', str_repeat( 'a', 64 ) );
		$this->assertSame( 'done', $state->state( 1 ) );
		$this->assertSame( 'ph-1', $this->meta[1][ Backup_State::META_PHOTO_ID ] );
		$this->assertSame( 'g-1', $this->meta[1][ Backup_State::META_GALLERY_ID ] );
		$this->assertSame( str_repeat( 'a', 64 ), $this->meta[1][ Backup_State::META_SHA256 ] );
		$this->assertSame( '', $state->reason( 1 ) );
		$this->assertSame( 0, $state->failures( 1 ) );

		$state->forget( 1 );
		$this->assertSame( 'done', $state->state( 1 ), 'A done item keeps its record.' );

		$this->assertFalse( $state->is_marked( 3 ) );
		$state->set_marked( 3, true );
		$this->assertTrue( $state->is_marked( 3 ) );
		$this->assertSame( '1', $this->meta[3][ Backup_State::META_MARKED ] );

		$this->meta[2][ Backup_State::META_STATE ] = 'uploading-somehow';
		$this->assertSame( '', $state->state( 2 ), 'An unknown state reads as none.' );
	}

	public function test_every_meta_key_starts_with_the_prefix_uninstall_removes(): void {
		$keys = array_filter( ( new \ReflectionClass( Backup_State::class ) )->getConstants(), fn( $name ) => 0 === strpos( $name, 'META_' ), ARRAY_FILTER_USE_KEY );

		$this->assertCount( 8, $keys );
		foreach ( $keys as $key ) {
			$this->assertStringStartsWith( '_profotograaf_backup_', $key );
		}
		$this->assertSame( array( 'queued', 'done', 'skipped', 'failed' ), Backup_State::STATES );
	}

	public function test_the_queue_lists_queued_attachments_lowest_id_first(): void {
		$state = $this->state();
		foreach ( array( 9, 3, 5 ) as $id ) {
			$state->queue( $id );
		}
		$state->skip( 4, 'too_large' );

		$this->assertSame( array( 3, 5 ), $state->queued( 2 ) );
		$args = end( $this->queries );
		$this->assertSame( 'attachment', $args['post_type'] );
		$this->assertSame( 'ids', $args['fields'] );
		$this->assertSame( 'ID', $args['orderby'] );
		$this->assertSame( 'ASC', $args['order'] );
	}

	public function test_references_cover_classes_blocks_and_the_gallery_shortcode(): void {
		$content = implode(
			"\n",
			array(
				'<img class="alignleft wp-image-12 size-full" />',
				'<img class="wp-image-13x" />',
				'<!-- wp:image {"id":21,"style":{"border":{"radius":"4px"}}} --><figure></figure><!-- /wp:image -->',
				'<!-- wp:core/cover {"url":"x.jpg","id":22} --><div></div><!-- /wp:core/cover -->',
				'<!-- wp:video {"id":23} /-->',
				'<!-- wp:media-text {"mediaId":24,"mediaType":"image"} --><!-- /wp:media-text -->',
				'<!-- wp:gallery {"ids":[25,"26"],"linkTo":"none"} --><!-- /wp:gallery -->',
				'<!-- wp:gallery {"linkTo":"none"} --><!-- wp:image {"id":27} /--><!-- /wp:gallery -->',
				'<!-- wp:file {"id":28} /-->',
				'<!-- wp:profotograaf/gallery {"id":29} /-->',
				'<!-- wp:image {broken json} /-->',
				'[gallery columns="3" ids="31, 32,33"]',
				'<img class="wp-image-12" />',
			)
		);

		$this->assertSame( array( 12, 21, 22, 23, 24, 25, 26, 27, 31, 32, 33 ), Backup_Usage::references( $content ) );
		$this->assertSame( array(), Backup_Usage::references( '<p>No media here, wp-image- and {"id":4}.</p>' ) );
	}

	public function test_used_ids_walk_published_posts_in_pages_and_are_cached(): void {
		for ( $id = 1; $id <= Backup_Usage::PAGE + 5; $id++ ) {
			$this->content( 1000 + $id, '<img class="wp-image-' . $id . '" />', 0 === $id % 2 ? 'post' : 'page' );
		}
		$this->content( 2000, '<img class="wp-image-500" />', 'post', 'draft' );
		$this->content( 2001, '<img class="wp-image-501" />', 'attachment', 'publish' );
		$this->meta[1001]['_thumbnail_id'] = '600';
		$usage                             = new Backup_Usage();

		$used = $usage->used_ids();

		$this->assertCount( Backup_Usage::PAGE + 6, $used );
		$this->assertTrue( $usage->is_used( 600 ), 'A featured image counts.' );
		$this->assertFalse( $usage->is_used( 500 ), 'A draft does not count.' );
		$this->assertFalse( $usage->is_used( 501 ), 'Attachments are not content.' );
		$this->assertSame( array( 1, 2 ), array_column( $this->queries, 'paged' ) );
		$this->assertSame( array( 'post', 'page' ), $this->queries[0]['post_type'] );
		$this->assertSame( 'publish', $this->queries[0]['post_status'] );
		$this->assertSame( $used, $this->transients[ Backup_Usage::CACHE ] );

		$this->queries = array();
		$usage->is_used( 1 );
		$this->assertSame( array(), $this->queries, 'The cached ids are used.' );

		Backup_Usage::forget();
		$this->assertArrayNotHasKey( Backup_Usage::CACHE, $this->transients );
	}

	public function test_the_post_types_filter_adds_a_portfolio_type(): void {
		$this->content( 1, '<img class="wp-image-7" />', 'portfolio' );
		Filters\expectApplied( Backup_Usage::POST_TYPES_FILTER )->once()->andReturnUsing( fn( $types ) => array_merge( $types, array( 'portfolio', '' ) ) );

		$this->assertTrue( ( new Backup_Usage() )->is_used( 7 ) );
		$this->assertSame( array( 'post', 'page', 'portfolio' ), $this->queries[0]['post_type'] );
	}

	public function test_in_post_adds_the_featured_image_once(): void {
		$this->content( 1, '<img class="wp-image-9" /><img class="wp-image-3" />' );
		$this->meta[1]['_thumbnail_id'] = '5';
		$this->content( 2, '<img class="wp-image-5" />' );
		$this->meta[2]['_thumbnail_id'] = '5';

		$usage = new Backup_Usage();
		$this->assertSame( array( 3, 5, 9 ), $usage->in_post( $this->posts[1] ) );
		$this->assertSame( array( 5 ), $usage->in_post( $this->posts[2] ) );
	}
}
