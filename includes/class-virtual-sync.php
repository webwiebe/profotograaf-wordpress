<?php
/**
 * Change feed sync of virtual attachments.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps virtual attachments in step with the platform through the change feed
 * `GET /api/v1/embed/changes` (issue #153, docs/virtual-attachments.md).
 *
 * The cursor lives in its own option, OPTION_CURSOR, apart from the cursor of
 * Change_Poller, so each reader advances only past the pages it applied.
 *
 * Per page:
 *
 * - A photo listed in `photos.deleted` marks the virtual attachments of that
 *   photo gone, when the entry has no gallery (deleted from the library) or
 *   names the attachment's gallery (taken out of it).
 * - A gallery listed in `galleries.deleted` or `galleries.unavailable` marks
 *   the virtual attachments of that gallery gone: its images stop serving.
 * - A photo listed as created or updated, and a gallery listed as created,
 *   updated or `available`, refreshes the virtual attachments concerned from
 *   the photo list. A photo the list carries gets fresh URLs and loses a gone
 *   mark, also when the same page listed it as deleted (the later entry wins).
 *
 * `"resync": true` runs the full sync (Virtual_Attachments::sync()) over the
 * whole photo list and then stores the new cursor.
 *
 * A failed or malformed feed page, and a photo list that is stale or failed,
 * change nothing: no attachment is marked and the cursor stays, so the page is
 * read again on the next run. Every run reads at most MAX_PAGES pages.
 */
final class Virtual_Sync {

	public const OPTION_CURSOR  = 'profotograaf_virtual_changes_cursor';
	public const OPTION_LAST_OK = 'profotograaf_virtual_changes_last_ok';

	public const MAX_PAGES  = 20;
	public const PAGE_LIMIT = 500;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Photo catalogue.
	 *
	 * @var Photo_Catalogue
	 */
	private Photo_Catalogue $catalogue;

	/**
	 * Virtual attachments.
	 *
	 * @var Virtual_Attachments
	 */
	private Virtual_Attachments $virtual;

	/**
	 * Photo list of the current run, by photo id. Null until it is read.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private ?array $listed = null;

	/**
	 * Constructor.
	 *
	 * @param Api_Client          $api       API client.
	 * @param Photo_Catalogue     $catalogue Photo catalogue.
	 * @param Virtual_Attachments $virtual   Virtual attachments.
	 */
	public function __construct( Api_Client $api, Photo_Catalogue $catalogue, Virtual_Attachments $virtual ) {
		$this->api       = $api;
		$this->catalogue = $catalogue;
		$this->virtual   = $virtual;
	}

	/**
	 * Reads the feed from the stored cursor and applies it.
	 *
	 * @return array{ok:bool,pages:int,updated:int,gone:int,resync:bool,error:string}
	 */
	public function run(): array {
		$result       = array(
			'ok'      => true,
			'pages'   => 0,
			'updated' => 0,
			'gone'    => 0,
			'resync'  => false,
			'error'   => '',
		);
		$this->listed = null;
		$attachments  = $this->attachments();
		if ( array() === $attachments ) {
			// Nothing to keep in step. The first attachment kept later starts from a resync.
			delete_option( self::OPTION_CURSOR );
			return $result;
		}

		$cursor = (string) get_option( self::OPTION_CURSOR, '' );
		while ( $result['pages'] < self::MAX_PAGES ) {
			$page = $this->api->changes( $cursor, self::PAGE_LIMIT );
			if ( is_wp_error( $page ) ) {
				return $this->fail( $result, $page );
			}
			++$result['pages'];

			if ( $page['resync'] ) {
				$catalogue = $this->catalogue();
				if ( is_wp_error( $catalogue ) ) {
					return $this->fail( $result, $catalogue );
				}
				$synced             = $this->virtual->sync( $catalogue );
				$result['updated'] += $synced['updated'];
				$result['gone']    += $synced['gone'];
				$result['resync']   = true;
				update_option( self::OPTION_CURSOR, $page['cursor'], false );
				break;
			}

			$applied = $this->apply( $page, $attachments );
			if ( is_wp_error( $applied ) ) {
				return $this->fail( $result, $applied );
			}
			$result['updated'] += $applied['updated'];
			$result['gone']    += $applied['gone'];
			update_option( self::OPTION_CURSOR, $page['cursor'], false );
			$cursor = $page['cursor'];
			if ( ! $page['has_more'] ) {
				break;
			}
		}//end while

		update_option( self::OPTION_LAST_OK, time(), false );
		return $result;
	}

	/**
	 * Forgets the cursor, so a later connection starts from a resync.
	 */
	public static function forget(): void {
		delete_option( self::OPTION_CURSOR );
		delete_option( self::OPTION_LAST_OK );
	}

	/**
	 * Catalogue rows by photo id.
	 *
	 * @param array<int,array<string,mixed>> $photos Catalogue rows.
	 * @return array<string,array<string,mixed>>
	 */
	public static function by_id( array $photos ): array {
		$by_id = array();
		foreach ( $photos as $row ) {
			if ( isset( $row['id'] ) && is_scalar( $row['id'] ) ) {
				$by_id[ (string) $row['id'] ] = $row;
			}
		}
		return $by_id;
	}

	/**
	 * Marks a virtual attachment gone, once. The stored URLs stay, so the
	 * attachment keeps pointing at the platform until it is converted.
	 *
	 * @param int $attachment_id Virtual attachment.
	 * @return bool Whether the mark was new.
	 */
	public static function mark_gone( int $attachment_id ): bool {
		if ( '' !== (string) get_post_meta( $attachment_id, Virtual_Attachments::META_GONE, true ) ) {
			return false;
		}
		update_post_meta( $attachment_id, Virtual_Attachments::META_GONE, time() );

		/**
		 * Fires once when the platform no longer serves a virtual attachment's
		 * photo: it left the photo list, or the change feed reported the photo
		 * deleted or its gallery deleted or unavailable.
		 *
		 * @param int $attachment_id Attachment id.
		 */
		do_action( 'profotograaf_virtual_attachment_gone', $attachment_id );
		return true;
	}

	/**
	 * Applies one feed page. Reads the photo list first when the page needs
	 * it, so a failed list leaves every attachment as it was.
	 *
	 * @param array<string,mixed>                                 $page        Feed page from Api_Client::changes().
	 * @param array<int,array{photo_id:string,gallery_id:string}> $attachments Virtual attachments by id.
	 * @return array{updated:int,gone:int}|WP_Error
	 */
	private function apply( array $page, array $attachments ) {
		$by_photo   = array();
		$by_gallery = array();
		foreach ( $attachments as $id => $row ) {
			$by_photo[ $row['photo_id'] ][] = $id;
			if ( '' !== $row['gallery_id'] ) {
				$by_gallery[ $row['gallery_id'] ][] = $id;
			}
		}

		$gone = array();
		foreach ( $page['photos']['deleted'] as $entry ) {
			foreach ( $by_photo[ $entry['id'] ] ?? array() as $id ) {
				if ( '' === $entry['gallery_id'] || $entry['gallery_id'] === $attachments[ $id ]['gallery_id'] ) {
					$gone[ $id ] = true;
				}
			}
		}
		$closed = array_merge( $page['galleries']['deleted'], array_column( $page['galleries']['unavailable'], 'id' ) );
		foreach ( $closed as $gallery_id ) {
			foreach ( $by_gallery[ $gallery_id ] ?? array() as $id ) {
				$gone[ $id ] = true;
			}
		}

		$refresh = array();
		foreach ( array_merge( $page['photos']['created'], $page['photos']['updated'] ) as $entry ) {
			foreach ( $by_photo[ $entry['id'] ] ?? array() as $id ) {
				$refresh[ $id ] = true;
			}
		}
		foreach ( array_merge( $page['galleries']['created'], $page['galleries']['updated'], $page['galleries']['available'] ) as $gallery_id ) {
			foreach ( $by_gallery[ $gallery_id ] ?? array() as $id ) {
				$refresh[ $id ] = true;
			}
		}

		$result = array(
			'updated' => 0,
			'gone'    => 0,
		);
		if ( array() !== $refresh ) {
			$catalogue = null === $this->listed ? $this->catalogue() : null;
			if ( is_wp_error( $catalogue ) ) {
				return $catalogue;
			}
			foreach ( array_keys( $refresh ) as $id ) {
				$photo = $this->listed[ $attachments[ $id ]['photo_id'] ] ?? null;
				if ( null !== $photo && $this->virtual->refresh_from( $id, $photo ) ) {
					++$result['updated'];
					unset( $gone[ $id ] );
				}
			}
		}
		foreach ( array_keys( $gone ) as $id ) {
			if ( self::mark_gone( $id ) ) {
				++$result['gone'];
			}
		}
		return $result;
	}

	/**
	 * Refreshes the photo list and keeps it for the rest of the run. A stale
	 * list is an error, so nothing is decided from it.
	 *
	 * @return array{photos:array<int,array<string,mixed>>,stale:bool,fetched_at:int,dropped:int}|WP_Error
	 */
	private function catalogue() {
		$catalogue = $this->catalogue->refresh_for_sync();
		if ( ! is_wp_error( $catalogue ) && $catalogue['stale'] ) {
			$catalogue = Api_Errors::make( 'profotograaf_catalogue_stale', __( 'The photo list could not be refreshed.', 'profotograaf' ), 0, true );
		}
		if ( ! is_wp_error( $catalogue ) ) {
			$this->listed = self::by_id( $catalogue['photos'] );
		}
		return $catalogue;
	}

	/**
	 * Photo and gallery id of every virtual attachment, by attachment id.
	 *
	 * @return array<int,array{photo_id:string,gallery_id:string}>
	 */
	private function attachments(): array {
		$rows = array();
		foreach ( $this->virtual->virtual_ids() as $id ) {
			$photo_id = (string) get_post_meta( $id, Photo_Importer::META_PHOTO_ID, true );
			if ( '' !== $photo_id ) {
				$rows[ $id ] = array(
					'photo_id'   => $photo_id,
					'gallery_id' => (string) get_post_meta( $id, Photo_Importer::META_GALLERY_ID, true ),
				);
			}
		}
		return $rows;
	}

	/**
	 * Logs a failed run. The cursor stays, so the same page is read again.
	 *
	 * @param array{ok:bool,pages:int,updated:int,gone:int,resync:bool,error:string} $result Result so far.
	 * @param WP_Error                                                               $error  Error.
	 * @return array{ok:bool,pages:int,updated:int,gone:int,resync:bool,error:string}
	 */
	private function fail( array $result, WP_Error $error ): array {
		$data = $error->get_error_data();
		Logger::warning(
			'The virtual attachment sync failed.',
			array(
				'code'   => $error->get_error_code(),
				'status' => is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0,
			)
		);
		$result['ok']    = false;
		$result['error'] = (string) $error->get_error_code();
		return $result;
	}
}
