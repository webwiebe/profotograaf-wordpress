<?php
/**
 * Conflict baseline of imported attachments.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Records what the plugin wrote into an imported attachment, so a later sync
 * can tell a local edit from an untouched copy (docs/media-lifecycle.md,
 * rule 2).
 *
 * - `_profotograaf_origin`: `import` for photos pulled from the platform.
 * - `_profotograaf_file_hash`: sha1 of the stored original at write time.
 * - `_profotograaf_text_hash`: hash of title, caption and alt as written.
 *
 * Copies imported before the baseline existed get one from backfill(), which
 * records their current values and so cannot see an edit made earlier. The
 * meta stays with the attachments, like the other `_profotograaf_*` meta.
 */
final class Media_Baseline {

	public const META_ORIGIN    = '_profotograaf_origin';
	public const META_FILE_HASH = '_profotograaf_file_hash';
	public const META_TEXT_HASH = '_profotograaf_text_hash';

	public const ORIGIN_IMPORT = 'import';

	/**
	 * Option that holds `done` once every earlier copy has a baseline.
	 */
	public const BACKFILL_OPTION = 'profotograaf_baseline_backfill';

	/**
	 * Attachments handled per backfill run.
	 */
	public const BACKFILL_BATCH = 50;

	/**
	 * Hash of the three text fields as stored on the attachment.
	 *
	 * @param string $title   Attachment title.
	 * @param string $caption Attachment caption.
	 * @param string $alt     Stored alt text (the importer's title fallback already applied).
	 */
	public static function text_hash( string $title, string $caption, string $alt ): string {
		return sha1( (string) wp_json_encode( array( $title, $caption, $alt ) ) );
	}

	/**
	 * Sha1 of the stored original file, empty when it cannot be read.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function file_hash( int $attachment_id ): string {
		$path = wp_get_original_image_path( $attachment_id );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = get_attached_file( $attachment_id );
		}
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) ) {
			return '';
		}
		$hash = sha1_file( $path );
		return false === $hash ? '' : $hash;
	}

	/**
	 * Baseline for an attachment the importer just created.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $title         Title as written.
	 * @param string $caption       Caption as written.
	 * @param string $alt           Alt text as written.
	 */
	public static function record_import( int $attachment_id, string $title, string $caption, string $alt ): void {
		update_post_meta( $attachment_id, self::META_ORIGIN, self::ORIGIN_IMPORT );
		update_post_meta( $attachment_id, self::META_TEXT_HASH, self::text_hash( $title, $caption, $alt ) );
		self::record_file( $attachment_id );
	}

	/**
	 * Baseline after the importer replaced the file. The text stays as the
	 * site has it, so the text baseline is only set when it is missing and a
	 * local text edit is never absorbed.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function record_reimport( int $attachment_id ): void {
		self::record_file( $attachment_id );
		if ( '' === (string) get_post_meta( $attachment_id, self::META_ORIGIN, true ) ) {
			update_post_meta( $attachment_id, self::META_ORIGIN, self::ORIGIN_IMPORT );
		}
		if ( '' === (string) get_post_meta( $attachment_id, self::META_TEXT_HASH, true ) ) {
			self::record_current_text( $attachment_id );
		}
	}

	/**
	 * Gives copies imported before the baseline existed their baseline from
	 * their current values. One batch per call.
	 *
	 * @return int Copies handled. Less than BACKFILL_BATCH means none are left.
	 */
	public static function backfill(): int {
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- finds copies without a baseline, in batches.
					'relation' => 'AND',
					array(
						'key'     => Photo_Importer::META_PHOTO_ID,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_TEXT_HASH,
						'compare' => 'NOT EXISTS',
					),
				),
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => self::BACKFILL_BATCH,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( '' === (string) get_post_meta( $attachment_id, self::META_ORIGIN, true ) ) {
				update_post_meta( $attachment_id, self::META_ORIGIN, self::ORIGIN_IMPORT );
			}
			if ( '' === (string) get_post_meta( $attachment_id, self::META_FILE_HASH, true ) ) {
				self::record_file( $attachment_id );
			}
			self::record_current_text( $attachment_id );
		}
		return count( $ids );
	}

	/**
	 * Stores the hash of the stored original. A file that cannot be read gets
	 * no hash, so it is never compared against a wrong value.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private static function record_file( int $attachment_id ): void {
		$hash = self::file_hash( $attachment_id );
		if ( '' !== $hash ) {
			update_post_meta( $attachment_id, self::META_FILE_HASH, $hash );
		}
	}

	/**
	 * Stores the hash of the text as the attachment has it now.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private static function record_current_text( int $attachment_id ): void {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		update_post_meta( $attachment_id, self::META_TEXT_HASH, self::text_hash( (string) $post->post_title, (string) $post->post_excerpt, is_scalar( $alt ) ? (string) $alt : '' ) );
	}
}
