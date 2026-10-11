<?php
/**
 * Outcome handling, text push and housekeeping of the upload queue.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use Profotograaf\Leads\Queue;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The second half of Upload_Queue, kept apart to keep that file short: what
 * happens after a request (retry, fail, succeed), the title, caption and alt
 * push, and the queries, lock and scheduling helpers. It uses the
 * properties and constants of Upload_Queue.
 */
trait Upload_Queue_Outcome {
	/**
	 * Records the outcome of a failed request.
	 *
	 * @param int      $id         Attachment id.
	 * @param string   $gallery_id Gallery id.
	 * @param int      $attempts   Attempts made, this one included.
	 * @param WP_Error $error      The error.
	 */
	private function handle_failure( int $id, string $gallery_id, int $attempts, WP_Error $error ): void {
		$code   = $error->get_error_code();
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

		if ( 'profotograaf_reconnect' === $code ) {
			// Stays queued: the reconnect state tells the owner, and a new pairing resumes the queue.
			update_post_meta( $id, self::META_STATE, self::STATE_QUEUED );
			update_post_meta( $id, self::META_ATTEMPTS, max( 0, $attempts - 1 ) );
			return;
		}
		if ( 'profotograaf_storage_full' === $code ) {
			$this->fail( $id, __( 'Your Profotograaf storage is full. Free up space or upgrade your plan to send more files.', 'profotograaf' ) );
			return;
		}
		if ( 404 === $status ) {
			$this->links->disable( $gallery_id );
			$this->fail( $id, __( 'Profotograaf does not know the gallery this file was meant for. The link was switched off. Link a gallery again to send it.', 'profotograaf' ) );
			return;
		}
		if ( 400 === $status ) {
			$this->fail( $id, __( 'Profotograaf does not accept this kind of file.', 'profotograaf' ) );
			return;
		}
		if ( is_array( $data ) && ! empty( $data['retryable'] ) ) {
			if ( $attempts >= self::MAX_ATTEMPTS ) {
				$this->fail( $id, __( 'Profotograaf could not be reached after several attempts. The file may exist on Profotograaf. Look in the gallery, and choose Upload again if the photo is not there.', 'profotograaf' ), true );
				return;
			}
			// The platform may have saved the file before the answer was lost.
			update_post_meta( $id, self::META_CHECK, '1' );
			$this->retry( $id, $attempts, $error );
			return;
		}
		$this->fail( $id, $error->get_error_message() );
	}

	/**
	 * Puts a file back in the queue for a later attempt.
	 *
	 * @param int      $id       Attachment id.
	 * @param int      $attempts Attempts made so far.
	 * @param WP_Error $error    The error that ended the attempt.
	 */
	private function retry( int $id, int $attempts, WP_Error $error ): void {
		$data        = $error->get_error_data();
		$retry_after = is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0;
		update_post_meta( $id, self::META_ATTEMPTS, $attempts );
		update_post_meta( $id, self::META_STATE, self::STATE_QUEUED );
		update_post_meta( $id, self::META_NEXT_AT, $this->now() + max( Queue::backoff( $attempts ), min( $retry_after, Queue::MAX_DELAY ) ) );
	}

	/**
	 * Ends a file as failed, with a message the person can read.
	 *
	 * @param int    $id         Attachment id.
	 * @param string $message    Translated message.
	 * @param bool   $maybe_sent Whether the file may be on Profotograaf already, see maybe_sent().
	 */
	private function fail( int $id, string $message, bool $maybe_sent = false ): void {
		update_post_meta( $id, self::META_STATE, self::STATE_FAILED );
		update_post_meta( $id, self::META_ERROR, $message );
		if ( $maybe_sent ) {
			update_post_meta( $id, self::META_MAYBE_SENT, '1' );
		} else {
			delete_post_meta( $id, self::META_MAYBE_SENT );
		}
		delete_post_meta( $id, self::META_CHECK );
		delete_post_meta( $id, self::META_NEXT_AT );
		Logger::warning( 'A file could not be sent to Profotograaf.', array( 'attachment' => $id ) );
	}

	/**
	 * Records a sent file and queues the first text push.
	 *
	 * @param int    $id         Attachment id.
	 * @param string $gallery_id Gallery id.
	 * @param string $photo_id   Platform photo id.
	 */
	private function succeed( int $id, string $gallery_id, string $photo_id ): void {
		update_post_meta( $id, Photo_Importer::META_PHOTO_ID, $photo_id );
		update_post_meta( $id, Photo_Importer::META_GALLERY_ID, $gallery_id );
		Media_Baseline::record_upload( $id );
		update_post_meta( $id, self::META_STATE, self::STATE_UPLOADED );
		update_post_meta( $id, self::META_UPLOADED, $this->now() );
		delete_post_meta( $id, self::META_ERROR );
		delete_post_meta( $id, self::META_MAYBE_SENT );
		delete_post_meta( $id, self::META_CHECK );
		delete_post_meta( $id, self::META_NEXT_AT );
		// The upload route takes the file only, so the text is sent after it. 'initial' skips empty fields.
		update_post_meta( $id, self::META_TEXT_PUSH, 'initial' );
		delete_post_meta( $id, self::META_TEXT_TRIES );
		$this->push_text( $id );
	}

	/**
	 * Sends the title, caption and alt text of an uploaded file.
	 *
	 * @param int $id Attachment id.
	 */
	private function push_text( int $id ): void {
		$flag     = (string) get_post_meta( $id, self::META_TEXT_PUSH, true );
		$photo_id = (string) get_post_meta( $id, Photo_Importer::META_PHOTO_ID, true );
		$post     = get_post( $id );
		if ( '' === $flag || '' === $photo_id || ! $post instanceof \WP_Post ) {
			delete_post_meta( $id, self::META_TEXT_PUSH );
			return;
		}
		$alt    = get_post_meta( $id, '_wp_attachment_image_alt', true );
		$fields = array(
			'title'   => (string) $post->post_title,
			'caption' => (string) $post->post_excerpt,
			'alt'     => is_scalar( $alt ) ? (string) $alt : '',
		);
		if ( 'initial' === $flag ) {
			// The platform keeps the file name as the title until told otherwise, and an empty field would clear it.
			$fields = array_filter( $fields, static fn( string $value ): bool => '' !== $value );
		}
		if ( array() === $fields ) {
			delete_post_meta( $id, self::META_TEXT_PUSH );
			return;
		}

		$result = $this->api->update_photo( $photo_id, $fields );
		if ( ! is_wp_error( $result ) ) {
			Media_Baseline::record_current_text( $id );
			delete_post_meta( $id, self::META_TEXT_PUSH );
			delete_post_meta( $id, self::META_TEXT_TRIES );
			delete_post_meta( $id, self::META_NEXT_AT );
			return;
		}
		if ( 'profotograaf_reconnect' === $result->get_error_code() ) {
			return;
		}
		$tries = (int) get_post_meta( $id, self::META_TEXT_TRIES, true ) + 1;
		if ( $tries >= self::MAX_ATTEMPTS ) {
			// The file is on Profotograaf. Only its text did not follow, and the next edit queues it again.
			delete_post_meta( $id, self::META_TEXT_PUSH );
			delete_post_meta( $id, self::META_TEXT_TRIES );
			delete_post_meta( $id, self::META_NEXT_AT );
			Logger::warning( 'The text of a sent file could not be updated on Profotograaf.', array( 'attachment' => $id ) );
			return;
		}
		// The platform processes a new photo in the background, so the first pushes can meet a 404.
		update_post_meta( $id, self::META_TEXT_TRIES, $tries );
		update_post_meta( $id, self::META_NEXT_AT, $this->now() + Queue::backoff( $tries ) );
	}

	/**
	 * Removes the queue state of a file that does not need sending.
	 *
	 * @param int $id Attachment id.
	 */
	private function clear_upload_state( int $id ): void {
		foreach ( array( self::META_STATE, self::META_GALLERY, self::META_ATTEMPTS, self::META_NEXT_AT, self::META_STARTED, self::META_CHECK, self::META_ERROR ) as $key ) {
			delete_post_meta( $id, $key );
		}
	}

	/**
	 * Attachment ids that have a meta key, optionally with a value.
	 *
	 * @param string      $key   Meta key.
	 * @param string|null $value Meta value, null for any.
	 * @return array<int,int>
	 */
	private function ids_with( string $key, ?string $value ): array {
		$clause = array( 'key' => $key );
		if ( null === $value ) {
			$clause['compare'] = 'EXISTS';
		} else {
			$clause['value'] = $value;
		}
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'meta_query'             => array( $clause ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the queue is small, and these are its only lookups.
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => 50,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Largest file the queue reads into memory. The file is held three times
	 * while it is cleaned and wrapped in the multipart body.
	 */
	private function max_bytes(): int {
		$limit = defined( 'WP_MEMORY_LIMIT' ) ? (int) wp_convert_hr_to_bytes( (string) constant( 'WP_MEMORY_LIMIT' ) ) : 0;
		$limit = $limit > 0 ? intdiv( $limit, 5 ) : 20 * 1024 * 1024;

		/**
		 * Filters the largest file, in bytes, that is sent to Profotograaf.
		 *
		 * @param int $limit Default: a fifth of the WordPress memory limit.
		 */
		return (int) apply_filters( 'profotograaf_upload_max_bytes', $limit );
	}

	/**
	 * A temp file for the cleaned copy, empty when none can be made.
	 */
	private function temp_file(): string {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$path = wp_tempnam( 'profotograaf-upload' );
		return $path;
	}

	/**
	 * Takes the run lock, replacing one older than LOCK_TTL.
	 */
	private function acquire_lock(): bool {
		if ( add_option( self::LOCK, $this->now(), '', false ) ) {
			return true;
		}
		$since = get_option( self::LOCK, 0 );
		if ( is_numeric( $since ) && $this->now() - (int) $since >= self::LOCK_TTL ) {
			delete_option( self::LOCK );
			return (bool) add_option( self::LOCK, $this->now(), '', false );
		}
		return false;
	}

	/**
	 * Schedules the next run unless one is waiting already.
	 *
	 * @param int $at Unix time.
	 */
	private function schedule( int $at ): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( $at, self::HOOK );
		}
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->clock )();
	}
}
