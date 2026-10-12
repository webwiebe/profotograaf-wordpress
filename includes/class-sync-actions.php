<?php
/**
 * What an author can do with a flagged imported copy.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Keep, stop watching, watch again and remove (docs/media-lifecycle.md, rule 1).
 *
 * Every method here runs from a click of a person with `edit_post` on the
 * attachment, through Sync_Actions_Rest. Nothing calls them from cron, a REST
 * poll or an activation hook, and the route checks the confirmation before it
 * calls remove().
 */
final class Sync_Actions {

	public const META_DECISION = '_profotograaf_sync_decision';

	/** Most copies one bulk removal request handles. */
	public const BATCH = 20;

	/** Most posts the used-in search reports. */
	public const USAGE_CAP = 100;

	/**
	 * Whether the attachment is an imported copy the sync can act on: linked
	 * to a platform photo, not sent from this site and not a virtual one.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function is_copy( int $attachment_id ): bool {
		return $attachment_id > 0
			&& 'attachment' === get_post_type( $attachment_id )
			&& '' !== (string) get_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, true )
			&& ! Media_Baseline::is_upload( $attachment_id )
			&& ! Virtual_Attachments::is_marked( $attachment_id );
	}

	/**
	 * Sets the copy to `kept`: the site's own file from now on. Also the
	 * "Stop watching" action for a copy that is in sync.
	 *
	 * @param int $attachment_id Attachment id.
	 * @param int $user_id       User who decided.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function keep( int $attachment_id, int $user_id ) {
		if ( ! self::is_copy( $attachment_id ) ) {
			return self::not_a_copy();
		}
		$from = self::state( $attachment_id );
		if ( Reconcile::STATE_KEPT !== $from ) {
			self::lower_count( $from );
			self::clear_flag( $attachment_id );
			update_post_meta( $attachment_id, Reconcile::META_STATE, Reconcile::STATE_KEPT );
			update_post_meta(
				$attachment_id,
				self::META_DECISION,
				array(
					'action' => 'keep',
					'user'   => $user_id,
					'at'     => time(),
				)
			);
		}
		return array(
			'attachment_id' => $attachment_id,
			'state'         => Reconcile::STATE_KEPT,
		);
	}

	/**
	 * Sets a kept copy back to `synced`, so the next poll compares it again.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function watch_again( int $attachment_id ) {
		if ( ! self::is_copy( $attachment_id ) ) {
			return self::not_a_copy();
		}
		if ( Reconcile::STATE_KEPT === self::state( $attachment_id ) ) {
			self::clear_flag( $attachment_id );
			delete_post_meta( $attachment_id, self::META_DECISION );
		}
		return array(
			'attachment_id' => $attachment_id,
			'state'         => Reconcile::STATE_SYNCED,
		);
	}

	/**
	 * The posts that use the copy, found by a bounded search of the post
	 * content and the featured image meta. Run only when the author asks.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{count:int,capped:bool,trashes:bool}|\WP_Error
	 */
	public static function usage( int $attachment_id ) {
		if ( ! self::is_copy( $attachment_id ) ) {
			return self::not_a_copy();
		}
		global $wpdb;
		$patterns = array();
		foreach ( array( '"id":' . $attachment_id . ',', '"id":' . $attachment_id . '}', 'wp-image-' . $attachment_id . '"', 'wp-image-' . $attachment_id . ' ' ) as $needle ) {
			$patterns[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}
		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft') AND post_type NOT IN ('attachment','revision') AND ("
			. implode( ' OR ', array_fill( 0, count( $patterns ), 'post_content LIKE %s' ) )
			. " OR ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s)) LIMIT %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded lookup the author asks for.
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $patterns, array( (string) $attachment_id, self::USAGE_CAP + 1 ) ) ) );
		$rows = is_array( $rows ) ? $rows : array();
		return array(
			'count'   => min( count( $rows ), self::USAGE_CAP ),
			'capped'  => count( $rows ) > self::USAGE_CAP,
			'trashes' => self::trashes(),
		);
	}

	/**
	 * Whether removal moves the file to the trash instead of deleting it.
	 */
	public static function trashes(): bool {
		return defined( 'MEDIA_TRASH' ) && MEDIA_TRASH;
	}

	/**
	 * Removes the copy from the Media Library. The file on Profotograaf stays.
	 * The caller has checked the confirmation and the capability.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function remove( int $attachment_id ) {
		if ( ! self::is_copy( $attachment_id ) ) {
			return self::not_a_copy();
		}
		$from = self::state( $attachment_id );
		if ( ! wp_delete_attachment( $attachment_id ) ) {
			return new \WP_Error( 'profotograaf_remove_failed', __( 'The photo could not be removed.', 'profotograaf' ), array( 'status' => 500 ) );
		}
		self::lower_count( $from );
		return array(
			'attachment_id' => $attachment_id,
			'trashed'       => self::trashes(),
		);
	}

	/**
	 * Removes up to BATCH copies that are flagged missing, for the ones the
	 * user may edit. The caller repeats until `remaining` is zero or the
	 * author stops.
	 *
	 * @param callable $can_edit Receives an attachment id, answers whether the user may edit it.
	 * @return array{removed:int,skipped:int,remaining:int,trashed:bool}
	 */
	public static function remove_missing_batch( callable $can_edit ): array {
		$ids     = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_key'       => Reconcile::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the flagged copies.
				'meta_value'     => Reconcile::STATE_MISSING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => self::BATCH,
				'no_found_rows'  => true,
			)
		);
		$removed = 0;
		$skipped = 0;
		foreach ( $ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( ! $can_edit( $attachment_id ) || is_wp_error( self::remove( $attachment_id ) ) ) {
				++$skipped;
				continue;
			}
			++$removed;
		}
		return array(
			'removed'   => $removed,
			'skipped'   => $skipped,
			'remaining' => max( 0, Reconcile::counts()['missing'] - $skipped ),
			'trashed'   => self::trashes(),
		);
	}

	/**
	 * Stored state of a copy, empty when in sync.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private static function state( int $attachment_id ): string {
		$state = (string) get_post_meta( $attachment_id, Reconcile::META_STATE, true );
		return Reconcile::STATE_SYNCED === $state ? '' : $state;
	}

	/**
	 * Removes the flag meta of a copy.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private static function clear_flag( int $attachment_id ): void {
		foreach ( array( Reconcile::META_STATE, Reconcile::META_REASON, Reconcile::META_SINCE, Reconcile::META_REMOTE_VERSION ) as $key ) {
			delete_post_meta( $attachment_id, $key );
		}
	}

	/**
	 * Takes one copy out of the stored count of the state it leaves. In-sync
	 * and kept copies have no count.
	 *
	 * @param string $from State it leaves, empty for in sync.
	 */
	private static function lower_count( string $from ): void {
		$counts = Reconcile::counts();
		if ( isset( $counts[ $from ] ) ) {
			$counts[ $from ] = max( 0, $counts[ $from ] - 1 );
			update_option( Reconcile::COUNTS_OPTION, $counts, false );
		}
	}

	/**
	 * Error for an attachment the actions do not apply to.
	 */
	private static function not_a_copy(): \WP_Error {
		return new \WP_Error( 'profotograaf_not_a_copy', __( 'This file is not a copy imported from Profotograaf.', 'profotograaf' ), array( 'status' => 404 ) );
	}
}
