<?php
/**
 * The imported copies the change feed poll watches.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the imported copies and answers questions about them for
 * Change_Poller: which feed entries concern them, whether a gallery changed
 * what it serves, and whether an account lists any of them.
 */
final class Imported_Copies {

	/**
	 * Whether a gallery of an imported copy serves something else than the
	 * copy's state says: not serving while the copy is not flagged, or serving
	 * again while the copy is flagged `unavailable`.
	 *
	 * @param array<int,array{id:int,photo_id:string,gallery_id:string}> $imported  Imported copies.
	 * @param array<int,array<string,mixed>>|null                        $galleries Gallery list rows, null when unknown.
	 */
	public static function serving_changed( array $imported, ?array $galleries ): bool {
		if ( null === $galleries ) {
			return false;
		}
		$serving = array();
		foreach ( $galleries as $gallery ) {
			$serving[ (string) $gallery['id'] ] = ! empty( $gallery['embeddable'] ) && ! empty( $gallery['available'] );
		}
		$complete = count( $galleries ) < Reconcile::GALLERY_LIST_CAP;
		foreach ( $imported as $copy ) {
			$gallery = $copy['gallery_id'];
			$state   = (string) get_post_meta( $copy['id'], Reconcile::META_STATE, true );
			if ( '' === $gallery || Reconcile::STATE_KEPT === $state ) {
				continue;
			}
			$missing = Reconcile::STATE_MISSING === $state;
			if ( ! isset( $serving[ $gallery ] ) ) {
				if ( $complete && ! $missing ) {
					return true;
				}
				continue;
			}
			if ( ! $serving[ $gallery ] && ! $missing ) {
				return true;
			}
			if ( $serving[ $gallery ] && $missing && Reconcile::REASON_UNAVAILABLE === (string) get_post_meta( $copy['id'], Reconcile::META_REASON, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a complete catalogue lists none of the imported copies.
	 *
	 * @param array<string,mixed>                                        $catalogue Catalogue.
	 * @param array<int,array{id:int,photo_id:string,gallery_id:string}> $imported  Imported copies.
	 */
	public static function account_mismatch( array $catalogue, array $imported ): bool {
		if ( (int) ( $catalogue['dropped'] ?? 0 ) > 0 ) {
			return false;
		}
		$listed = array();
		foreach ( (array) $catalogue['photos'] as $photo ) {
			if ( is_array( $photo ) && isset( $photo['id'] ) ) {
				$listed[ (string) $photo['id'] ] = true;
			}
		}
		foreach ( $imported as $copy ) {
			if ( isset( $listed[ $copy['photo_id'] ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Keeps the feed entries that name an imported photo or a gallery of one.
	 * Backup galleries and their photos are left out, whatever the feed says.
	 *
	 * @param array<string,mixed>                                        $page     Feed page from Api_Client::changes().
	 * @param array<int,array{id:int,photo_id:string,gallery_id:string}> $imported Imported copies.
	 * @return array{photos_deleted:array<int,array{id:string,gallery_id:string}>,photos_changed:array<int,string>,galleries_deleted:array<int,string>,galleries_unavailable:array<int,string>,relevant:bool}
	 */
	public static function filter_feed( array $page, array $imported ): array {
		$photos    = array();
		$galleries = array();
		foreach ( $imported as $copy ) {
			if ( Backup_Galleries::contains( $copy['gallery_id'] ) ) {
				continue;
			}
			$photos[ $copy['photo_id'] ] = true;
			if ( '' !== $copy['gallery_id'] ) {
				$galleries[ $copy['gallery_id'] ] = true;
			}
		}
		$deleted = array();
		foreach ( $page['photos']['deleted'] as $entry ) {
			if ( isset( $photos[ $entry['id'] ] ) && ! Backup_Galleries::contains( (string) ( $entry['gallery_id'] ?? '' ) ) ) {
				$deleted[] = $entry;
			}
		}
		$changed = array();
		foreach ( array_merge( $page['photos']['created'], $page['photos']['updated'] ) as $entry ) {
			if ( isset( $photos[ $entry['id'] ] ) ) {
				$changed[] = $entry['id'];
			}
		}
		$gone = array_values( array_filter( $page['galleries']['deleted'], static fn( string $id ): bool => isset( $galleries[ $id ] ) ) );
		$live = array_filter( array_merge( $page['galleries']['created'], $page['galleries']['updated'] ), static fn( string $id ): bool => isset( $galleries[ $id ] ) );

		// The serving state of a gallery written since the cursor. A gallery in both lists counts as available.
		$back        = array_filter( $page['galleries']['available'], static fn( string $id ): bool => isset( $galleries[ $id ] ) );
		$unavailable = array();
		foreach ( $page['galleries']['unavailable'] as $entry ) {
			if ( isset( $galleries[ $entry['id'] ] ) && ! in_array( $entry['id'], $page['galleries']['available'], true ) ) {
				$unavailable[] = $entry['id'];
			}
		}
		$unavailable = array_values( array_unique( $unavailable ) );

		return array(
			'photos_deleted'        => $deleted,
			'photos_changed'        => array_values( array_unique( $changed ) ),
			'galleries_deleted'     => $gone,
			'galleries_unavailable' => $unavailable,
			'relevant'              => array() !== $deleted || array() !== $changed || array() !== $gone || array() !== $live || array() !== $unavailable || array() !== $back,
		);
	}

	/**
	 * The copies the sync watches: attachments linked to a platform photo that
	 * the site did not upload itself. An uploaded file diverges from the
	 * platform copy by design (docs/media-lifecycle.md, rule 2).
	 *
	 * @return array<int,array{id:int,photo_id:string,gallery_id:string}>
	 */
	public static function all(): array {
		$ids    = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- finds the imported copies once per run.
					'relation' => 'AND',
					array(
						'key'     => Photo_Importer::META_PHOTO_ID,
						'compare' => 'EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => Media_Baseline::META_ORIGIN,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => Media_Baseline::META_ORIGIN,
							'value'   => Media_Baseline::ORIGIN_UPLOAD,
							'compare' => '!=',
						),
					),
				),
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$copies = array();
		foreach ( $ids as $attachment_id ) {
			$photo_id = (string) get_post_meta( (int) $attachment_id, Photo_Importer::META_PHOTO_ID, true );
			if ( '' === $photo_id ) {
				continue;
			}
			$copies[] = array(
				'id'         => (int) $attachment_id,
				'photo_id'   => $photo_id,
				'gallery_id' => (string) get_post_meta( (int) $attachment_id, Photo_Importer::META_GALLERY_ID, true ),
			);
		}
		return $copies;
	}
}
