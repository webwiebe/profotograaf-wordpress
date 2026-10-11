<?php
/**
 * Backup state of each Media Library item.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the backup state that lives in post meta on each
 * attachment (docs/backup-and-pwa.md, "Where the backup lands").
 *
 * - `_profotograaf_backup_state`: `queued`, `uploading`, `done`, `skipped`,
 *   `failed` or `needs_check`. No meta means the item is not part of the
 *   backup. `uploading` lasts for one request. `needs_check` is an upload
 *   whose answer was lost: the file may be on Profotograaf, so the worker
 *   never sends it again on its own.
 * - `_profotograaf_backup_reason`: why an item was skipped or failed.
 * - `_profotograaf_backup_failures`: how often the upload failed.
 * - `_profotograaf_backup_photo_id`, `_profotograaf_backup_gallery_id`,
 *   `_profotograaf_backup_sha256`: where a done item went, and its checksum.
 * - `_profotograaf_backup_at`: when the state last changed (Unix time).
 * - `_profotograaf_backup_marked`: the photographer marked the item for the
 *   backup (scopes `marked` and `used`).
 *
 * uninstall.php removes every `_profotograaf_backup_` key. The copies on
 * Profotograaf stay.
 *
 * A copied file also gets a record that uninstall keeps, like the other
 * `_profotograaf_` meta of the plugin: `_profotograaf_copy_sha256`,
 * `_profotograaf_copy_photo_id` and `_profotograaf_copy_gallery_id`. The
 * platform has no lookup for a file in a private gallery and no duplicate
 * check on upload, so this record is what keeps a reinstall, or a second
 * attachment with the same bytes, from storing the file twice.
 */
final class Backup_State {

	public const META_STATE      = '_profotograaf_backup_state';
	public const META_REASON     = '_profotograaf_backup_reason';
	public const META_FAILURES   = '_profotograaf_backup_failures';
	public const META_PHOTO_ID   = '_profotograaf_backup_photo_id';
	public const META_GALLERY_ID = '_profotograaf_backup_gallery_id';
	public const META_SHA256     = '_profotograaf_backup_sha256';
	public const META_AT         = '_profotograaf_backup_at';
	public const META_MARKED     = '_profotograaf_backup_marked';

	public const META_COPY_SHA256  = '_profotograaf_copy_sha256';
	public const META_COPY_PHOTO   = '_profotograaf_copy_photo_id';
	public const META_COPY_GALLERY = '_profotograaf_copy_gallery_id';

	public const QUEUED  = 'queued';
	public const DONE    = 'done';
	public const SKIPPED = 'skipped';
	public const FAILED  = 'failed';

	public const UPLOADING   = 'uploading';
	public const NEEDS_CHECK = 'needs_check';

	public const STATES = array( self::QUEUED, self::UPLOADING, self::DONE, self::SKIPPED, self::FAILED, self::NEEDS_CHECK );

	/**
	 * States that the eligibility check leaves alone: the file was sent, may
	 * have been sent, or waits for the photographer.
	 */
	public const SETTLED = array( self::UPLOADING, self::DONE, self::FAILED, self::NEEDS_CHECK );

	/**
	 * Clock, so tests can fix the time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Returns the Unix time, `time` by default.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? 'time';
	}

	/**
	 * The state of an attachment, empty when it is not part of the backup.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function state( int $attachment_id ): string {
		$state = (string) get_post_meta( $attachment_id, self::META_STATE, true );
		return in_array( $state, self::STATES, true ) ? $state : '';
	}

	/**
	 * Why an attachment was skipped or failed, empty otherwise.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function reason( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_REASON, true );
	}

	/**
	 * How often the upload of an attachment failed.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function failures( int $attachment_id ): int {
		return (int) get_post_meta( $attachment_id, self::META_FAILURES, true );
	}

	/**
	 * Puts an attachment in the queue and clears an old skip reason.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function queue( int $attachment_id ): void {
		$this->set( $attachment_id, self::QUEUED );
		delete_post_meta( $attachment_id, self::META_REASON );
	}

	/**
	 * Records a skipped attachment and the reason.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $reason        One of the Backup_Eligibility reasons.
	 */
	public function skip( int $attachment_id, string $reason ): void {
		$this->set( $attachment_id, self::SKIPPED );
		update_post_meta( $attachment_id, self::META_REASON, $reason );
	}

	/**
	 * Records a failed upload and counts it.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $reason        Error code.
	 */
	public function fail( int $attachment_id, string $reason ): void {
		$this->set( $attachment_id, self::FAILED );
		update_post_meta( $attachment_id, self::META_REASON, $reason );
		update_post_meta( $attachment_id, self::META_FAILURES, $this->failures( $attachment_id ) + 1 );
	}

	/**
	 * Records a finished upload: where it went and the checksum of the file sent.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $photo_id      Photo id on Profotograaf.
	 * @param string $gallery_id    Gallery id on Profotograaf.
	 * @param string $sha256        SHA-256 of the file sent.
	 */
	public function done( int $attachment_id, string $photo_id, string $gallery_id, string $sha256 ): void {
		$this->set( $attachment_id, self::DONE );
		update_post_meta( $attachment_id, self::META_PHOTO_ID, $photo_id );
		update_post_meta( $attachment_id, self::META_GALLERY_ID, $gallery_id );
		update_post_meta( $attachment_id, self::META_SHA256, $sha256 );
		delete_post_meta( $attachment_id, self::META_REASON );
		delete_post_meta( $attachment_id, self::META_FAILURES );
	}

	/**
	 * Marks the start of an upload, just before the request leaves.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function uploading( int $attachment_id ): void {
		$this->set( $attachment_id, self::UPLOADING );
	}

	/**
	 * Records an upload whose answer was lost. The file may be on
	 * Profotograaf, so it is not sent again until the photographer says so.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $reason        Why the answer is unknown.
	 */
	public function needs_check( int $attachment_id, string $reason ): void {
		$this->set( $attachment_id, self::NEEDS_CHECK );
		update_post_meta( $attachment_id, self::META_REASON, $reason );
	}

	/**
	 * Puts an item back in the queue without clearing its failure count,
	 * after an attempt that never reached the platform.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function requeue( int $attachment_id ): void {
		$this->set( $attachment_id, self::QUEUED );
	}

	/**
	 * Keeps the record of a copied file, which uninstall leaves in place.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $sha256        SHA-256 of the file sent.
	 * @param string $photo_id      Photo id on Profotograaf.
	 * @param string $gallery_id    Gallery id on Profotograaf.
	 */
	public function record_copy( int $attachment_id, string $sha256, string $photo_id, string $gallery_id ): void {
		update_post_meta( $attachment_id, self::META_COPY_SHA256, $sha256 );
		update_post_meta( $attachment_id, self::META_COPY_PHOTO, $photo_id );
		update_post_meta( $attachment_id, self::META_COPY_GALLERY, $gallery_id );
	}

	/**
	 * Finds an earlier copy of a file with these bytes: on this attachment
	 * first, then on any other one.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $sha256        SHA-256 of the file.
	 * @return array{photo_id:string,gallery_id:string}|null
	 */
	public function find_copy( int $attachment_id, string $sha256 ): ?array {
		$candidates = array( $attachment_id );
		if ( (string) get_post_meta( $attachment_id, self::META_COPY_SHA256, true ) !== $sha256 ) {
			$candidates = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'meta_key'       => self::META_COPY_SHA256, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- runs in WP-Cron only.
					'meta_value'     => $sha256, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- runs in WP-Cron only.
				)
			);
		}
		foreach ( (array) $candidates as $id ) {
			$photo = (string) get_post_meta( (int) $id, self::META_COPY_PHOTO, true );
			if ( '' !== $photo && (string) get_post_meta( (int) $id, self::META_COPY_SHA256, true ) === $sha256 ) {
				return array(
					'photo_id'   => $photo,
					'gallery_id' => (string) get_post_meta( (int) $id, self::META_COPY_GALLERY, true ),
				);
			}
		}
		return null;
	}

	/**
	 * Takes an attachment out of the backup. A done item keeps its state,
	 * because its copy exists on Profotograaf.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function forget( int $attachment_id ): void {
		if ( self::DONE === $this->state( $attachment_id ) ) {
			return;
		}
		foreach ( array( self::META_STATE, self::META_REASON, self::META_FAILURES, self::META_AT ) as $key ) {
			delete_post_meta( $attachment_id, $key );
		}
	}

	/**
	 * Whether the photographer marked an attachment for the backup.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function is_marked( int $attachment_id ): bool {
		return '1' === (string) get_post_meta( $attachment_id, self::META_MARKED, true );
	}

	/**
	 * Marks or unmarks an attachment for the backup.
	 *
	 * @param int  $attachment_id Attachment id.
	 * @param bool $marked        Whether it is marked.
	 */
	public function set_marked( int $attachment_id, bool $marked ): void {
		if ( $marked ) {
			update_post_meta( $attachment_id, self::META_MARKED, '1' );
			return;
		}
		delete_post_meta( $attachment_id, self::META_MARKED );
	}

	/**
	 * The ids of queued attachments, lowest id first.
	 *
	 * @param int $limit How many at most.
	 * @return array<int,int>
	 */
	public function queued( int $limit ): array {
		return $this->in_state( self::QUEUED, $limit );
	}

	/**
	 * The ids of attachments in a state, lowest id first.
	 *
	 * @param string $state One of STATES.
	 * @param int    $limit How many at most.
	 * @return array<int,int>
	 */
	public function in_state( string $state, int $limit ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => max( 1, $limit ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_key'       => self::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- runs in WP-Cron only.
				'meta_value'     => $state, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- runs in WP-Cron only.
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Writes the state and the time.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $state         State.
	 */
	private function set( int $attachment_id, string $state ): void {
		update_post_meta( $attachment_id, self::META_STATE, $state );
		update_post_meta( $attachment_id, self::META_AT, (int) ( $this->clock )() );
	}
}
