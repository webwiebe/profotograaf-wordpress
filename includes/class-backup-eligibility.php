<?php
/**
 * Which Media Library items the backup copies.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * The backup rules (docs/backup-and-pwa.md, "Which media", with the owner
 * decision on #141 to copy images, RAW files and videos).
 *
 * An attachment is checked in this order:
 *
 * 1. It is an attachment with a supported type: an image, a RAW file or a
 *    video. Other types (SVG, audio, PDF, documents) are ignored.
 * 2. The scope selects it: `all` takes every attachment, `marked` the ones
 *    the photographer marked, `used` the marked ones plus the ones published
 *    content shows (Backup_Usage). Items outside the scope are ignored.
 * 3. It was not imported from Profotograaf (`_profotograaf_photo_id`).
 *    Sending it back would store the photo twice. Reason `imported`.
 * 4. Its original file exists: wp_get_original_image_path() for a scaled
 *    image, else get_attached_file(). Reason `missing_file`.
 * 5. The file is at most MAX_BYTES. Reason `too_large`.
 * 6. The `profotograaf_backup_should_upload` filter keeps it. Reason
 *    `excluded`.
 *
 * Ignored items get no state, skipped ones get `skipped` with the reason,
 * and the rest is `queued` for the upload worker.
 */
final class Backup_Eligibility {

	/**
	 * Filter `profotograaf_backup_should_upload( bool $upload, int $attachment_id )`.
	 */
	public const FILTER = 'profotograaf_backup_should_upload';

	/** Largest file the backup sends, in bytes: the platform's upload cap. */
	public const MAX_BYTES = 100 * 1024 * 1024;

	public const QUEUE  = 'queue';
	public const SKIP   = 'skip';
	public const IGNORE = 'ignore';

	public const REASON_NOT_ATTACHMENT = 'not_attachment';
	public const REASON_UNSUPPORTED    = 'unsupported_type';
	public const REASON_OUT_OF_SCOPE   = 'out_of_scope';
	public const REASON_IMPORTED       = 'imported';
	public const REASON_MISSING_FILE   = 'missing_file';
	public const REASON_TOO_LARGE      = 'too_large';
	public const REASON_EXCLUDED       = 'excluded';

	/** Image types the backup copies. SVG and icons are left out. */
	public const IMAGE_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/gif',
		'image/avif',
		'image/heic',
		'image/heif',
		'image/tiff',
	);

	/** RAW types, as upload plugins register them. */
	public const RAW_TYPES = array(
		'image/x-adobe-dng',
		'image/dng',
		'image/x-canon-cr2',
		'image/x-canon-cr3',
		'image/x-canon-crw',
		'image/x-nikon-nef',
		'image/x-nikon-nrw',
		'image/x-sony-arw',
		'image/x-sony-sr2',
		'image/x-fuji-raf',
		'image/x-olympus-orf',
		'image/x-panasonic-rw2',
		'image/x-pentax-pef',
		'image/x-samsung-srw',
		'image/x-raw',
	);

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Backup state.
	 *
	 * @var Backup_State
	 */
	private Backup_State $state;

	/**
	 * Used attachments.
	 *
	 * @var Backup_Usage
	 */
	private Backup_Usage $usage;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings, for the scope.
	 * @param Backup_State|null $state    Backup state.
	 * @param Backup_Usage|null $usage    Used attachments.
	 */
	public function __construct( Settings $settings, ?Backup_State $state = null, ?Backup_Usage $usage = null ) {
		$this->settings = $settings;
		$this->state    = $state ?? new Backup_State();
		$this->usage    = $usage ?? new Backup_Usage();
	}

	/**
	 * Whether the backup copies files of this type: images, RAW files and videos.
	 *
	 * @param string $mime_type MIME type.
	 */
	public static function supported_type( string $mime_type ): bool {
		$mime_type = strtolower( trim( $mime_type ) );
		return in_array( $mime_type, self::IMAGE_TYPES, true )
			|| in_array( $mime_type, self::RAW_TYPES, true )
			|| ( 0 === strpos( $mime_type, 'video/' ) && strlen( $mime_type ) > 6 );
	}

	/**
	 * Whether the scope selects an attachment.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $scope         `used`, `all` or `marked`.
	 */
	public function in_scope( int $attachment_id, string $scope ): bool {
		if ( 'all' === $scope ) {
			return true;
		}
		if ( $this->state->is_marked( $attachment_id ) ) {
			return true;
		}
		return 'used' === $scope && $this->usage->is_used( $attachment_id );
	}

	/**
	 * Path of the file the backup sends: the original upload when WordPress
	 * scaled the image and kept the original, else the attached file. Empty
	 * when neither can be read.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function original_file( int $attachment_id ): string {
		$candidates = array();
		if ( function_exists( 'wp_get_original_image_path' ) ) {
			$candidates[] = wp_get_original_image_path( $attachment_id );
		}
		$candidates[] = get_attached_file( $attachment_id );
		foreach ( $candidates as $path ) {
			if ( is_string( $path ) && '' !== $path && is_file( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Applies the rules to one attachment without writing anything.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{decision:string,reason:string,file:string} The decision
	 *         (QUEUE, SKIP or IGNORE), the reason for SKIP and IGNORE, and the
	 *         file to send for QUEUE.
	 */
	public function check( int $attachment_id ): array {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return self::result( self::IGNORE, self::REASON_NOT_ATTACHMENT );
		}
		if ( ! self::supported_type( (string) $post->post_mime_type ) ) {
			return self::result( self::IGNORE, self::REASON_UNSUPPORTED );
		}
		if ( ! $this->in_scope( $attachment_id, $this->settings->backup_scope() ) ) {
			return self::result( self::IGNORE, self::REASON_OUT_OF_SCOPE );
		}
		if ( '' !== (string) get_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, true ) ) {
			return self::result( self::SKIP, self::REASON_IMPORTED );
		}
		$file = $this->original_file( $attachment_id );
		if ( '' === $file ) {
			return self::result( self::SKIP, self::REASON_MISSING_FILE );
		}
		// A long-running backfill sees files change; read the size from disk.
		clearstatcache( true, $file );
		$bytes = filesize( $file );
		if ( false === $bytes ) {
			return self::result( self::SKIP, self::REASON_MISSING_FILE );
		}
		if ( $bytes > self::MAX_BYTES ) {
			return self::result( self::SKIP, self::REASON_TOO_LARGE );
		}
		/**
		 * Filters whether the backup copies an attachment that passed every
		 * rule. Return false to leave it out.
		 *
		 * @param bool $upload        Whether to copy it.
		 * @param int  $attachment_id Attachment id.
		 */
		if ( true !== (bool) apply_filters( 'profotograaf_backup_should_upload', true, $attachment_id ) ) {
			return self::result( self::SKIP, self::REASON_EXCLUDED );
		}
		return array(
			'decision' => self::QUEUE,
			'reason'   => '',
			'file'     => $file,
		);
	}

	/**
	 * Checks one attachment and records the outcome in its backup state.
	 *
	 * A `done` item keeps its state, because the backup copies a file once.
	 * A `failed` or `needs_check` item keeps its state until the photographer
	 * retries it, and an `uploading` one belongs to a running upload. An
	 * item the rules now ignore leaves the queue.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string The state afterwards, empty when it is not part of the backup.
	 */
	public function apply( int $attachment_id ): string {
		$current = $this->state->state( $attachment_id );
		if ( in_array( $current, Backup_State::SETTLED, true ) ) {
			return $current;
		}
		$result = $this->check( $attachment_id );
		if ( self::QUEUE === $result['decision'] ) {
			if ( Backup_State::QUEUED !== $current ) {
				$this->state->queue( $attachment_id );
			}
		} elseif ( self::SKIP === $result['decision'] ) {
			if ( Backup_State::SKIPPED !== $current || $this->state->reason( $attachment_id ) !== $result['reason'] ) {
				$this->state->skip( $attachment_id, $result['reason'] );
			}
		} else {
			$this->state->forget( $attachment_id );
		}
		return $this->state->state( $attachment_id );
	}

	/**
	 * A result without a file.
	 *
	 * @param string $decision QUEUE, SKIP or IGNORE.
	 * @param string $reason   Reason.
	 * @return array{decision:string,reason:string,file:string}
	 */
	private static function result( string $decision, string $reason ): array {
		return array(
			'decision' => $decision,
			'reason'   => $reason,
			'file'     => '',
		);
	}
}
