<?php
/**
 * The queue that sends Media Library files to Profotograaf.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use Profotograaf\Leads\Queue;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Meta-backed queue for the opt-in upload (docs/media-lifecycle.md, rule 4).
 *
 * A person assigns a file to a linked gallery (assign()). A WP-Cron single
 * event (Upload_Queue::HOOK) then sends one file per run and schedules the
 * next run, so no upload happens inside an admin request. Nothing here runs
 * on a front-end request.
 *
 * Per attachment (post meta, kept when the plugin is deleted like the other
 * `_profotograaf_*` keys):
 *
 * - `_profotograaf_upload_state`: `queued`, `uploading`, `uploaded` or `failed`.
 * - `_profotograaf_upload_gallery`, `_profotograaf_upload_error`,
 *   `_profotograaf_upload_attempts`, `_profotograaf_uploaded_at`,
 *   `_profotograaf_upload_next_at`, `_profotograaf_upload_started_at`.
 * - `_profotograaf_upload_check`: set after a failure that may have saved the
 *   file. The next attempt looks for the file by name before it sends again.
 * - `_profotograaf_text_push`: the title, caption or alt text changed after
 *   the upload and waits to be sent with PATCH.
 *
 * Before the bytes leave, the GPS position is removed (Location_Stripper) and
 * the rest of the EXIF data stays. Failures: 413 is final (storage full),
 * 404 disables the link, 400 is final for the file, 403 keeps the file queued
 * and shows the reconnect state, 5xx, 429 and network errors retry with the
 * backoff of the enquiry queue.
 */
class Upload_Queue {

	use Upload_Queue_Outcome;

	public const HOOK = 'profotograaf_upload_queue';

	public const META_GALLERY    = '_profotograaf_upload_gallery';
	public const META_STATE      = '_profotograaf_upload_state';
	public const META_ERROR      = '_profotograaf_upload_error';
	public const META_ATTEMPTS   = '_profotograaf_upload_attempts';
	public const META_UPLOADED   = '_profotograaf_uploaded_at';
	public const META_NEXT_AT    = '_profotograaf_upload_next_at';
	public const META_STARTED    = '_profotograaf_upload_started_at';
	public const META_CHECK      = '_profotograaf_upload_check';
	public const META_TEXT_PUSH  = '_profotograaf_text_push';
	public const META_TEXT_TRIES = '_profotograaf_text_attempts';

	public const STATE_QUEUED    = 'queued';
	public const STATE_UPLOADING = 'uploading';
	public const STATE_UPLOADED  = 'uploaded';
	public const STATE_FAILED    = 'failed';

	/**
	 * Run lock option, removed with the other `profotograaf_` options.
	 */
	public const LOCK = 'profotograaf_upload_lock';

	/** Seconds after which a lock counts as abandoned. */
	public const LOCK_TTL = 600;

	/** Attempts per file, and per text push, before the queue gives up. */
	public const MAX_ATTEMPTS = 8;

	/** Seconds after which a file in `uploading` counts as an upload whose answer was lost. */
	public const STALE_AFTER = 900;

	/** Seconds between two runs while work is due. */
	public const RUN_GAP = 5;

	/** Media types the queue sends. HEIC is left out because its metadata cannot be cleaned. */
	public const MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Linked galleries.
	 *
	 * @var Upload_Links
	 */
	private Upload_Links $links;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Stored connection state.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings   Settings.
	 * @param Upload_Links  $links      Linked galleries.
	 * @param Api_Client    $api        API client.
	 * @param Connection    $connection Connection state.
	 * @param callable|null $clock      Returns the current unix time.
	 */
	public function __construct( Settings $settings, Upload_Links $links, Api_Client $api, Connection $connection, ?callable $clock = null ) {
		$this->settings   = $settings;
		$this->links      = $links;
		$this->api        = $api;
		$this->connection = $connection;
		$this->clock      = $clock ?? static fn(): int => time();
	}

	/**
	 * The upload state of an attachment, empty when it was never assigned.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function state( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_STATE, true );
	}

	/**
	 * Whether the queue can send this attachment's file type.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function sendable( int $attachment_id ): bool {
		return in_array( (string) get_post_mime_type( $attachment_id ), self::MIME_TYPES, true );
	}

	/**
	 * Assigns a file to a linked gallery and queues it. A person chooses this,
	 * so nothing else calls it except the automatic send of a link that has
	 * that switched on. Callers check the capabilities.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $gallery_id    Linked gallery.
	 * @return true|WP_Error
	 */
	public function assign( int $attachment_id, string $gallery_id ) {
		if ( ! $this->settings->media_upload_enabled() ) {
			return Api_Errors::make( 'profotograaf_upload_off', __( 'Sending files to Profotograaf is switched off in the plugin settings.', 'profotograaf' ), 403, false );
		}
		$link = $this->links->find( $gallery_id );
		if ( null === $link || $link['disabled'] ) {
			return Api_Errors::make( 'profotograaf_upload_no_link', __( 'This gallery is not linked for sending files.', 'profotograaf' ), 400, false );
		}
		if ( ! $this->sendable( $attachment_id ) ) {
			return Api_Errors::make( 'profotograaf_upload_type', __( 'Only JPEG, PNG, WebP and GIF images can be sent to Profotograaf.', 'profotograaf' ), 400, false );
		}
		if ( '' !== (string) get_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, true ) ) {
			return Api_Errors::make( 'profotograaf_upload_known', __( 'This file is on Profotograaf already.', 'profotograaf' ), 409, false );
		}
		if ( in_array( $this->state( $attachment_id ), array( self::STATE_QUEUED, self::STATE_UPLOADING ), true ) ) {
			return true;
		}

		update_post_meta( $attachment_id, self::META_GALLERY, $gallery_id );
		update_post_meta( $attachment_id, self::META_STATE, self::STATE_QUEUED );
		update_post_meta( $attachment_id, self::META_ATTEMPTS, 0 );
		delete_post_meta( $attachment_id, self::META_ERROR );
		delete_post_meta( $attachment_id, self::META_NEXT_AT );
		delete_post_meta( $attachment_id, self::META_CHECK );
		$this->schedule( $this->now() );
		return true;
	}

	/**
	 * Queues the attachment for the link that sends new uploads automatically.
	 * The run skips a file that turns out to be an imported photo, because the
	 * importer adds its photo id after the attachment exists.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function assign_automatic( int $attachment_id ): void {
		if ( ! $this->settings->media_upload_enabled() || ! current_user_can( 'upload_files' ) || ! $this->sendable( $attachment_id ) ) {
			return;
		}
		$link = $this->links->auto_link();
		if ( null === $link ) {
			return;
		}
		$this->assign( $attachment_id, $link['gallery_id'] );
	}

	/**
	 * Notes that an uploaded file's text changed and queues the push to the platform.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function note_text_edit( int $attachment_id ): void {
		if ( ! $this->settings->media_upload_enabled() || ! Media_Baseline::is_upload( $attachment_id ) || self::STATE_UPLOADED !== $this->state( $attachment_id ) ) {
			return;
		}
		$baseline = (string) get_post_meta( $attachment_id, Media_Baseline::META_TEXT_HASH, true );
		if ( Media_Baseline::current_text_hash( $attachment_id ) === $baseline ) {
			return;
		}
		update_post_meta( $attachment_id, self::META_TEXT_PUSH, '1' );
		delete_post_meta( $attachment_id, self::META_TEXT_TRIES );
		$this->schedule( $this->now() + 30 );
	}

	/**
	 * Schedules a run when files wait, for example after a reconnect gave the
	 * token the write scope.
	 */
	public function resume(): void {
		if ( $this->settings->media_upload_enabled() && null !== $this->next_job() ) {
			$this->schedule( $this->now() );
		}
	}

	/**
	 * Runs one unit of work: one file, or one text push. Called by WP-Cron.
	 */
	public function run(): void {
		if ( ! $this->settings->media_upload_enabled() || ! $this->connection->is_connected() ) {
			return;
		}
		// Files stay queued while the token lacks the write scope. A reconnect resumes them.
		if ( $this->connection->needs_write_reconnect( true ) ) {
			return;
		}
		if ( ! $this->acquire_lock() ) {
			return;
		}
		try {
			$job = $this->next_job();
			if ( null !== $job && $job['at'] <= $this->now() ) {
				if ( 'upload' === $job['kind'] ) {
					$this->process_upload( $job['id'] );
				} else {
					$this->push_text( $job['id'] );
				}
			}
		} finally {
			delete_option( self::LOCK );
		}

		$next = $this->next_job();
		if ( null !== $next ) {
			$this->schedule( max( $next['at'], $this->now() + self::RUN_GAP ) );
		}
	}

	/**
	 * Counts per state, for the settings page and Site Health.
	 *
	 * @return array{queued:int,uploading:int,failed:int,text:int}
	 */
	public function counts(): array {
		return array(
			'queued'    => count( $this->ids_with( self::META_STATE, self::STATE_QUEUED ) ),
			'uploading' => count( $this->ids_with( self::META_STATE, self::STATE_UPLOADING ) ),
			'failed'    => count( $this->ids_with( self::META_STATE, self::STATE_FAILED ) ),
			'text'      => count( $this->ids_with( self::META_TEXT_PUSH, null ) ),
		);
	}

	/**
	 * The error message stored for a failed file.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function error( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_ERROR, true );
	}

	/**
	 * Finds the next piece of work that is due.
	 *
	 * @return array{id:int,kind:string,at:int}|null `at` is when it is due, which is in the past for work to do now.
	 */
	private function next_job(): ?array {
		$now  = $this->now();
		$best = null;
		foreach ( array_merge( $this->ids_with( self::META_STATE, self::STATE_QUEUED ), $this->ids_with( self::META_STATE, self::STATE_UPLOADING ) ) as $id ) {
			$due = $this->upload_due_at( $id );
			if ( null === $best || $due < $best['at'] ) {
				$best = array(
					'id'   => $id,
					'kind' => 'upload',
					'at'   => $due,
				);
			}
			if ( $best['at'] <= $now ) {
				return $best;
			}
		}
		foreach ( $this->ids_with( self::META_TEXT_PUSH, null ) as $id ) {
			$due = (int) get_post_meta( $id, self::META_NEXT_AT, true );
			if ( null === $best || $due < $best['at'] ) {
				$best = array(
					'id'   => $id,
					'kind' => 'text',
					'at'   => $due,
				);
			}
			if ( $best['at'] <= $now ) {
				return $best;
			}
		}
		return $best;
	}

	/**
	 * When an upload is due: now for a fresh file, the retry time after a failure,
	 * and the end of the stale period for a file that was left in `uploading`.
	 *
	 * @param int $id Attachment id.
	 */
	private function upload_due_at( int $id ): int {
		if ( self::STATE_UPLOADING === $this->state( $id ) ) {
			return (int) get_post_meta( $id, self::META_STARTED, true ) + self::STALE_AFTER;
		}
		return (int) get_post_meta( $id, self::META_NEXT_AT, true );
	}

	/**
	 * Sends one file.
	 *
	 * @param int $id Attachment id.
	 */
	private function process_upload( int $id ): void {
		$gallery_id = (string) get_post_meta( $id, self::META_GALLERY, true );
		$link       = $this->links->find( $gallery_id );

		if ( '' !== (string) get_post_meta( $id, Photo_Importer::META_PHOTO_ID, true ) ) {
			// An imported photo that an automatic assignment caught, or a file that was sent already.
			$this->clear_upload_state( $id );
			return;
		}
		if ( null === $link || $link['disabled'] ) {
			$this->fail( $id, __( 'The gallery this file was meant for is no longer linked. Choose a linked gallery to send it again.', 'profotograaf' ) );
			return;
		}
		$path = wp_get_original_image_path( $id );
		$path = is_string( $path ) && '' !== $path ? $path : (string) get_attached_file( $id );
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			$this->fail( $id, __( 'The file could not be read from this site.', 'profotograaf' ) );
			return;
		}
		$size = (int) filesize( $path );
		if ( $size > $this->max_bytes() ) {
			$this->fail( $id, __( 'The file is too large for this site to send. Resize it and send it again.', 'profotograaf' ) );
			return;
		}

		$attempts = (int) get_post_meta( $id, self::META_ATTEMPTS, true ) + 1;
		$mime     = (string) get_post_mime_type( $id );
		$name     = wp_basename( $path );

		if ( '' !== (string) get_post_meta( $id, self::META_CHECK, true ) || self::STATE_UPLOADING === $this->state( $id ) ) {
			// An earlier attempt may have saved the file. Look before sending it again.
			$found = $this->api->find_photo_by_name( $gallery_id, $name );
			if ( is_string( $found ) ) {
				$this->succeed( $id, $gallery_id, $found );
				return;
			}
			if ( is_wp_error( $found ) ) {
				$data = $found->get_error_data();
				if ( is_array( $data ) && ! empty( $data['retryable'] ) && $attempts < self::MAX_ATTEMPTS ) {
					$this->retry( $id, $attempts, $found );
					return;
				}
				$this->fail( $id, __( 'The file may already exist on Profotograaf, because an earlier attempt did not get an answer and the gallery could not be checked. Look in the gallery before you send it again.', 'profotograaf' ) );
				return;
			}
		}

		update_post_meta( $id, self::META_ATTEMPTS, $attempts );
		update_post_meta( $id, self::META_STATE, self::STATE_UPLOADING );
		update_post_meta( $id, self::META_STARTED, $this->now() );

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( false === $contents ) {
			$this->fail( $id, __( 'The file could not be read from this site.', 'profotograaf' ) );
			return;
		}
		$temp = $this->temp_file();
		if ( '' === $temp || false === file_put_contents( $temp, Location_Stripper::strip( $contents ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temp file.
			$this->fail( $id, __( 'The file could not be prepared for sending.', 'profotograaf' ) );
			return;
		}
		unset( $contents );
		try {
			$result = $this->api->upload_photo( $gallery_id, $temp, $name, $mime );
		} finally {
			wp_delete_file( $temp );
		}

		if ( is_wp_error( $result ) ) {
			$this->handle_failure( $id, $gallery_id, $attempts, $result );
			return;
		}
		$this->succeed( $id, $gallery_id, $result['id'] );
	}
}
