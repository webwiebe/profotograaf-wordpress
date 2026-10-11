<?php
/**
 * A fake Media Library for the backup tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Settings;

/**
 * Attachments with files on disk, post meta in memory, and the WordPress
 * functions the backup classes call. Use in a Wp_Test_Case.
 */
trait Backup_Fixtures {

	/**
	 * Posts by id.
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
	 * Single and recurring events: hook => array of [time, args, recurrence].
	 *
	 * @var array<string,array<int,array{0:int,1:array<int,mixed>,2:string}>>
	 */
	private array $events = array();

	private string $dir = '';

	/**
	 * Installs the stubs and a temporary directory.
	 */
	private function set_up_library(): void {
		$this->dir = sys_get_temp_dir() . '/pfworker-' . bin2hex( random_bytes( 4 ) );
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
		Functions\when( 'wp_get_original_image_path' )->alias( fn( $id ) => $this->attached[ (int) $id ] ?? false );
		Functions\when( 'get_post_mime_type' )->alias( fn( $id ) => isset( $this->posts[ (int) $id ] ) ? $this->posts[ (int) $id ]->post_mime_type : false );
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post' => 'post',
				'page' => 'page',
			)
		);
		Functions\when( 'get_posts' )->alias( fn( $args ) => $this->find_posts( $args ) );
		Functions\when( 'wp_basename' )->alias( fn( $path ) => basename( (string) $path ) );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook, $args = array() ) {
				foreach ( $this->events[ $hook ] ?? array() as $event ) {
					if ( $event[1] === $args ) {
						return $event[0];
					}
				}
				return false;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $time, $hook, $args = array() ) {
				$this->events[ $hook ][] = array( $time, $args, '' );
				return true;
			}
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $time, $recurrence, $hook ) {
				$this->events[ $hook ][] = array( $time, array(), $recurrence );
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				unset( $this->events[ $hook ] );
				return 0;
			}
		);
		Functions\when( 'wp_unschedule_hook' )->alias(
			function ( $hook ) {
				unset( $this->events[ $hook ] );
				return 0;
			}
		);
	}

	/**
	 * Removes the temporary files.
	 */
	private function tear_down_library(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
	}

	/**
	 * Switches the backup on with a scope.
	 *
	 * @param string $scope `used`, `all` or `marked`.
	 * @param bool   $on    Whether the backup is on.
	 */
	private function backup( string $scope = 'all', bool $on = true ): void {
		$this->options[ Settings::OPTION ] = array(
			'backup'       => $on,
			'backup_scope' => $scope,
		);
	}

	/**
	 * Adds an attachment with a file on disk.
	 *
	 * @param int    $id       Attachment id.
	 * @param string $contents File contents.
	 * @param string $date     Upload date.
	 * @param string $mime     MIME type.
	 */
	private function attachment( int $id, string $contents = '', string $date = '2026-10-03 09:00:00', string $mime = 'image/jpeg' ): void {
		$post                 = new \WP_Post();
		$post->ID             = $id;
		$post->post_type      = 'attachment';
		$post->post_status    = 'inherit';
		$post->post_mime_type = $mime;
		$post->post_date      = $date;
		$this->posts[ $id ]   = $post;
		$path                 = $this->dir . '/photo-' . $id . '.jpg';
		file_put_contents( $path, '' === $contents ? 'bytes of ' . $id : $contents );
		$this->attached[ $id ] = $path;
	}

	/**
	 * Adds an attachment that waits in the queue.
	 *
	 * @param int    $id       Attachment id.
	 * @param string $contents File contents.
	 * @param string $date     Upload date.
	 */
	private function queued( int $id, string $contents = '', string $date = '2026-10-03 09:00:00' ): void {
		$this->attachment( $id, $contents, $date );
		$this->meta[ $id ]['_profotograaf_backup_state'] = 'queued';
	}

	/**
	 * Adds published content.
	 *
	 * @param int    $id      Post id.
	 * @param string $content Post content.
	 * @param string $status  Post status.
	 * @param string $type    Post type.
	 */
	private function content( int $id, string $content, string $status = 'publish', string $type = 'post' ): void {
		$post               = new \WP_Post();
		$post->ID           = $id;
		$post->post_type    = $type;
		$post->post_status  = $status;
		$post->post_content = $content;
		$this->posts[ $id ] = $post;
	}

	/**
	 * The fake get_posts: attachments by one meta value, or content in pages.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<int,mixed>
	 */
	private function find_posts( array $args ): array {
		if ( 'attachment' === $args['post_type'] ) {
			$ids = array();
			foreach ( $this->meta as $id => $meta ) {
				if ( isset( $this->posts[ $id ] ) && ( $meta[ $args['meta_key'] ] ?? null ) === $args['meta_value'] ) {
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
	 * The backup state of an attachment.
	 *
	 * @param int $id Attachment id.
	 */
	private function state_of( int $id ): string {
		return (string) ( $this->meta[ $id ]['_profotograaf_backup_state'] ?? '' );
	}
}
