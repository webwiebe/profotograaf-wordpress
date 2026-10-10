<?php
/**
 * Reconcile engine for imported copies.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which sync state each imported attachment is in (docs/media-lifecycle.md,
 * rules 1 and 2).
 *
 * The plan() method is pure: it takes what the platform listed and what the site holds and
 * returns state changes with reasons. It reads no option, calls no API and
 * writes nothing. apply() stores the changes in the plugin's own
 * `_profotograaf_sync_*` meta and keeps `profotograaf_sync_counts` in step.
 * Neither deletes a file, an attachment or any content.
 *
 * Nothing is flagged from a stale catalogue, a missing input or a paused
 * account. A photo whose absence cannot be confirmed keeps its state.
 */
final class Reconcile {

	public const META_STATE          = '_profotograaf_sync_state';
	public const META_REASON         = '_profotograaf_sync_reason';
	public const META_SINCE          = '_profotograaf_sync_since';
	public const META_REMOTE_VERSION = '_profotograaf_remote_version';

	public const COUNTS_OPTION = 'profotograaf_sync_counts';

	public const STATE_SYNCED   = 'synced';
	public const STATE_CHANGED  = 'changed';
	public const STATE_MISSING  = 'missing';
	public const STATE_CONFLICT = 'conflict';
	public const STATE_KEPT     = 'kept';

	public const REASON_DELETED         = 'deleted';
	public const REASON_LEFT_GALLERY    = 'left_gallery';
	public const REASON_GALLERY_DELETED = 'gallery_deleted';
	public const REASON_UNAVAILABLE     = 'unavailable';
	public const REASON_UNKNOWN         = 'unknown';

	/** Rows the gallery list holds at most. At this size absence from it proves nothing. */
	public const GALLERY_LIST_CAP = 500;

	/** Reasons that clear on their own when the photo is listed again. */
	private const SELF_CLEARING = array( self::REASON_UNAVAILABLE, self::REASON_LEFT_GALLERY );

	/**
	 * Reads the rows plan() takes for imported attachments.
	 *
	 * @param array<int,int> $attachment_ids Attachment ids.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( array $attachment_ids ): array {
		$rows = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$photo_id      = (string) get_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, true );
			if ( '' === $photo_id ) {
				continue;
			}
			$file_base = (string) get_post_meta( $attachment_id, Media_Baseline::META_FILE_HASH, true );
			$text_base = (string) get_post_meta( $attachment_id, Media_Baseline::META_TEXT_HASH, true );
			$text_now  = self::current_text_hash( $attachment_id );
			$file_now  = '' !== $file_base ? Media_Baseline::file_hash( $attachment_id ) : '';
			$rows[]    = array(
				'attachment_id'  => $attachment_id,
				'photo_id'       => $photo_id,
				'gallery_id'     => (string) get_post_meta( $attachment_id, Photo_Importer::META_GALLERY_ID, true ),
				'version'        => (string) get_post_meta( $attachment_id, Photo_Importer::META_VERSION, true ),
				'state'          => self::stored_state( $attachment_id ),
				'reason'         => (string) get_post_meta( $attachment_id, self::META_REASON, true ),
				'since'          => (int) get_post_meta( $attachment_id, self::META_SINCE, true ),
				'remote_version' => (string) get_post_meta( $attachment_id, self::META_REMOTE_VERSION, true ),
				'text_base'      => $text_base,
				'text_now'       => $text_now,
				// A copy without a baseline counts as unedited. The file hash is empty when the file cannot be read.
				'file_edited'    => '' !== $file_base && '' !== $file_now && $file_base !== $file_now,
				'text_edited'    => '' !== $text_base && '' !== $text_now && $text_base !== $text_now,
			);
		}//end foreach
		return $rows;
	}

	/**
	 * Computes the state changes of one run.
	 *
	 * Input keys:
	 * - `rows`: from rows().
	 * - `catalogue`: `photos` (normalised rows), `stale` (bool), `dropped` (int).
	 * - `galleries`: rows of GET /api/v1/embed/galleries (`id`, `embeddable`, `available`), or null when the request failed.
	 * - `feed`: `photos_deleted` (list of `{id, gallery_id}`), `photos_changed` (photo ids created or updated), `galleries_deleted` (gallery ids), or null.
	 * - `gallery_photos`: photo ids per gallery id from GET /api/v1/embed/galleries/{id}/photos, `false` for a gallery that answered 404 or failed. Optional.
	 * - `after_pairing`: true for the first run after a pairing.
	 * - `paused`: true while the account pause is active.
	 * - `now`: unix time.
	 *
	 * @param array<string,mixed> $input Run input.
	 * @return array{changes:array<int,array<string,mixed>>,needs_lookup:array<int,string>,skipped:string}
	 *         `changes` by attachment id: `from`, `state`, `reason`, `since`, `remote_version`, `gallery_id`.
	 *         `needs_lookup` lists gallery ids whose photo list would settle unexplained absences.
	 *         `skipped` is empty, or says why the run decided nothing: `paused`, `stale_catalogue`, `account_changed`.
	 */
	public static function plan( array $input ): array {
		$none = array(
			'changes'      => array(),
			'needs_lookup' => array(),
			'skipped'      => '',
		);

		$rows      = is_array( $input['rows'] ?? null ) ? array_values( $input['rows'] ) : array();
		$catalogue = is_array( $input['catalogue'] ?? null ) ? $input['catalogue'] : array();
		$now       = (int) ( $input['now'] ?? time() );

		if ( ! empty( $input['paused'] ) ) {
			$none['skipped'] = 'paused';
			return $none;
		}
		if ( array() === $rows ) {
			return $none;
		}
		if ( ! empty( $catalogue['stale'] ) || ! is_array( $catalogue['photos'] ?? null ) ) {
			$none['skipped'] = 'stale_catalogue';
			return $none;
		}

		$listed = array();
		foreach ( $catalogue['photos'] as $photo ) {
			if ( is_array( $photo ) && isset( $photo['id'] ) ) {
				$listed[ (string) $photo['id'] ] = $photo;
			}
		}
		$complete = 0 === (int) ( $catalogue['dropped'] ?? 0 );

		if ( ! empty( $input['after_pairing'] ) && $complete ) {
			$any = false;
			foreach ( $rows as $row ) {
				if ( isset( $listed[ (string) $row['photo_id'] ] ) ) {
					$any = true;
					break;
				}
			}
			if ( ! $any ) {
				$none['skipped'] = 'account_changed';
				return $none;
			}
		}

		$galleries = self::gallery_index( $input['galleries'] ?? null );
		$feed      = self::feed_index( $input['feed'] ?? null );
		$lookups   = is_array( $input['gallery_photos'] ?? null ) ? $input['gallery_photos'] : array();

		$changes = array();
		$needs   = array();
		foreach ( $rows as $row ) {
			if ( self::STATE_KEPT === $row['state'] ) {
				continue;
			}
			$photo_id = (string) $row['photo_id'];
			$target   = isset( $listed[ $photo_id ] )
				? self::when_listed( $row, $listed[ $photo_id ], isset( $feed['changed'][ $photo_id ] ) )
				: self::when_unlisted( $row, $feed, $galleries, $complete, $lookups, $needs );
			if ( null === $target ) {
				continue;
			}
			$change = self::change( $row, $target, $now );
			if ( null !== $change ) {
				$changes[ (int) $row['attachment_id'] ] = $change;
			}
		}

		$none['changes']      = $changes;
		$none['needs_lookup'] = array_values( array_unique( $needs ) );
		return $none;
	}

	/**
	 * Writes the planned changes to the attachments' own sync meta and keeps
	 * the counts option in step. Deletes nothing but those meta keys.
	 *
	 * @param array<int,array<string,mixed>> $changes Changes from plan().
	 * @return array{changed:int,missing:int,conflict:int}
	 */
	public static function apply( array $changes ): array {
		$counts = self::counts();
		foreach ( $changes as $attachment_id => $change ) {
			$attachment_id = (int) $attachment_id;
			// The stored state is the truth, not the state the plan saw. Applying a plan twice then leaves the counts as they are,
			// and a "Keep" click made while the run was busy is not overwritten.
			$from = self::stored_state( $attachment_id );
			$to   = (string) $change['state'];
			if ( self::STATE_KEPT === $from || ( $from === $to && self::same_stored( $attachment_id, $change ) ) ) {
				continue;
			}
			$from = '' === $from ? self::STATE_SYNCED : $from;
			if ( isset( $counts[ $from ] ) ) {
				$counts[ $from ] = max( 0, $counts[ $from ] - 1 );
			}
			if ( isset( $counts[ $to ] ) ) {
				++$counts[ $to ];
			}
			if ( self::STATE_SYNCED === $to ) {
				delete_post_meta( $attachment_id, self::META_STATE );
				delete_post_meta( $attachment_id, self::META_REASON );
				delete_post_meta( $attachment_id, self::META_SINCE );
				delete_post_meta( $attachment_id, self::META_REMOTE_VERSION );
			} else {
				update_post_meta( $attachment_id, self::META_STATE, $to );
				update_post_meta( $attachment_id, self::META_REASON, (string) $change['reason'] );
				update_post_meta( $attachment_id, self::META_SINCE, (int) $change['since'] );
				update_post_meta( $attachment_id, self::META_REMOTE_VERSION, (string) $change['remote_version'] );
			}
			if ( '' !== (string) ( $change['gallery_id'] ?? '' ) ) {
				update_post_meta( $attachment_id, Photo_Importer::META_GALLERY_ID, (string) $change['gallery_id'] );
			}
		}//end foreach
		if ( array() !== $changes ) {
			update_option( self::COUNTS_OPTION, $counts, false );
		}
		return $counts;
	}

	/**
	 * Whether the attachment already holds the reason and remote version of a change.
	 *
	 * @param int                 $attachment_id Attachment id.
	 * @param array<string,mixed> $change        Change from plan().
	 */
	private static function same_stored( int $attachment_id, array $change ): bool {
		return (string) get_post_meta( $attachment_id, self::META_REASON, true ) === (string) $change['reason']
			&& (string) get_post_meta( $attachment_id, self::META_REMOTE_VERSION, true ) === (string) $change['remote_version'];
	}

	/**
	 * The stored counts.
	 *
	 * @return array{changed:int,missing:int,conflict:int}
	 */
	public static function counts(): array {
		$stored = get_option( self::COUNTS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'changed'  => max( 0, (int) ( $stored['changed'] ?? 0 ) ),
			'missing'  => max( 0, (int) ( $stored['missing'] ?? 0 ) ),
			'conflict' => max( 0, (int) ( $stored['conflict'] ?? 0 ) ),
		);
	}

	/**
	 * Target state of a photo the catalogue lists.
	 *
	 * @param array<string,mixed> $row           Imported copy.
	 * @param array<string,mixed> $photo         Catalogue row.
	 * @param bool                $feed_returned Whether a created or updated feed entry names the photo.
	 * @return array<string,mixed>|null Null leaves the copy as it is.
	 */
	private static function when_listed( array $row, array $photo, bool $feed_returned ): ?array {
		$gallery = (string) ( $photo['gallery_id'] ?? '' );
		$state   = (string) $row['state'];

		if ( self::STATE_MISSING === $state && ! $feed_returned && ! in_array( (string) $row['reason'], self::SELF_CLEARING, true ) ) {
			// Deleted, gallery_deleted and unknown wait for the author unless the feed names the photo again.
			return null;
		}

		$remote  = (string) ( $photo['version'] ?? '' );
		$file    = '' !== $remote && '' !== (string) $row['version'] && $remote !== (string) $row['version'];
		$text    = self::text_changed( $row, $photo );
		$changed = $file || $text;
		$edited  = ! empty( $row['file_edited'] ) || ! empty( $row['text_edited'] );

		if ( ! $changed ) {
			return array(
				'state'          => self::STATE_SYNCED,
				'reason'         => '',
				'remote_version' => '',
				'gallery_id'     => $gallery,
			);
		}
		return array(
			'state'          => $edited ? self::STATE_CONFLICT : self::STATE_CHANGED,
			'reason'         => '',
			'remote_version' => $file ? $remote : '',
			'gallery_id'     => $gallery,
		);
	}

	/**
	 * Target state of a photo the catalogue does not list.
	 *
	 * @param array<string,mixed>                    $row       Imported copy.
	 * @param array<string,mixed>                    $feed      From feed_index().
	 * @param array<string,array{serving:bool}>|null $galleries From gallery_index(), null when unknown.
	 * @param bool                                   $complete  Whether the catalogue holds every photo.
	 * @param array<string,mixed>                    $lookups   Per-gallery photo ids.
	 * @param array<int,string>                      $needs     Gallery ids that need a lookup, appended to.
	 * @return array<string,mixed>|null Null leaves the copy as it is.
	 */
	private static function when_unlisted( array $row, array $feed, ?array $galleries, bool $complete, array $lookups, array &$needs ): ?array {
		$photo_id = (string) $row['photo_id'];
		$gallery  = (string) $row['gallery_id'];

		// A created or updated entry for an unlisted photo says nothing about absence. Treat it like no entry.
		$deleted = ! isset( $feed['changed'][ $photo_id ] ) && isset( $feed['deleted'][ $photo_id ] ) ? $feed['deleted'][ $photo_id ] : null;

		$reason = null;
		if ( null !== $deleted && '' === $deleted['gallery_id'] ) {
			$reason = self::REASON_DELETED;
		} elseif ( '' !== $gallery && isset( $feed['galleries_deleted'][ $gallery ] ) ) {
			$reason = self::REASON_GALLERY_DELETED;
		} elseif ( '' !== $gallery && null !== $galleries && count( $galleries ) < self::GALLERY_LIST_CAP && ! isset( $galleries[ $gallery ] ) ) {
			$reason = self::REASON_GALLERY_DELETED;
		} elseif ( '' !== $gallery && isset( $galleries[ $gallery ] ) && ! $galleries[ $gallery ]['serving'] ) {
			$reason = self::REASON_UNAVAILABLE;
		} elseif ( null !== $deleted && $complete ) {
			$reason = self::REASON_LEFT_GALLERY;
		} elseif ( '' !== $gallery ) {
			// Unexplained absence. One complete answer for the gallery decides.
			if ( ! array_key_exists( $gallery, $lookups ) ) {
				$needs[] = $gallery;
				return null;
			}
			if ( is_array( $lookups[ $gallery ] ) && ! in_array( $photo_id, array_map( 'strval', $lookups[ $gallery ] ), true ) ) {
				$reason = self::REASON_UNKNOWN;
			}
		}

		if ( null === $reason ) {
			return null;
		}
		return array(
			'state'          => self::STATE_MISSING,
			'reason'         => $reason,
			'remote_version' => '',
			'gallery_id'     => '',
		);
	}

	/**
	 * Turns a target into a change, or null when the copy already is there.
	 *
	 * @param array<string,mixed> $row    Imported copy.
	 * @param array<string,mixed> $target Target from when_listed() or when_unlisted().
	 * @param int                 $now    Unix time.
	 * @return array<string,mixed>|null
	 */
	private static function change( array $row, array $target, int $now ): ?array {
		$from       = '' === (string) $row['state'] ? self::STATE_SYNCED : (string) $row['state'];
		$gallery    = (string) ( $target['gallery_id'] ?? '' );
		$gallery_up = '' !== $gallery && $gallery !== (string) $row['gallery_id'];

		$same = $from === $target['state']
			&& (string) $row['reason'] === (string) $target['reason']
			&& ( self::STATE_SYNCED === $from || (string) $row['remote_version'] === (string) $target['remote_version'] );
		if ( $same && ! $gallery_up ) {
			return null;
		}
		// The first run that set the state stays its date while the state holds.
		$since = $from === $target['state'] && (int) $row['since'] > 0 ? (int) $row['since'] : $now;

		return array(
			'from'           => $from,
			'state'          => (string) $target['state'],
			'reason'         => (string) $target['reason'],
			'since'          => $since,
			'remote_version' => (string) $target['remote_version'],
			'gallery_id'     => $gallery_up ? $gallery : '',
		);
	}

	/**
	 * Whether the platform text differs from what the plugin wrote.
	 *
	 * The platform text goes through the importer's rules: plain text, and the
	 * title stands in for an empty alt. It counts as a change only when it
	 * differs from both the baseline and the copy as it is now. The second
	 * test keeps a title that WordPress rewrote on insert from flagging forever.
	 *
	 * @param array<string,mixed> $row   Imported copy.
	 * @param array<string,mixed> $photo Catalogue row.
	 */
	private static function text_changed( array $row, array $photo ): bool {
		if ( '' === (string) $row['text_base'] ) {
			return false;
		}
		$title   = sanitize_text_field( (string) ( $photo['title'] ?? '' ) );
		$caption = sanitize_text_field( (string) ( $photo['caption'] ?? '' ) );
		$alt     = sanitize_text_field( (string) ( $photo['alt'] ?? '' ) );
		$hash    = Media_Baseline::text_hash( $title, $caption, '' !== $alt ? $alt : $title );
		return $hash !== (string) $row['text_base'] && $hash !== (string) $row['text_now'];
	}

	/**
	 * Gallery rows by id with a `serving` flag.
	 *
	 * @param mixed $rows Gallery list rows, or null when the request failed.
	 * @return array<string,array{serving:bool}>|null
	 */
	private static function gallery_index( $rows ): ?array {
		if ( ! is_array( $rows ) ) {
			return null;
		}
		$index = array();
		foreach ( $rows as $gallery ) {
			if ( ! is_array( $gallery ) || empty( $gallery['id'] ) ) {
				continue;
			}
			$index[ (string) $gallery['id'] ] = array(
				'serving' => ! empty( $gallery['embeddable'] ) && ! empty( $gallery['available'] ),
			);
		}
		return $index;
	}

	/**
	 * Feed entries by id.
	 *
	 * @param mixed $feed Feed input, or null.
	 * @return array{deleted:array<string,array{gallery_id:string}>,changed:array<string,bool>,galleries_deleted:array<string,bool>}
	 */
	private static function feed_index( $feed ): array {
		$index = array(
			'deleted'           => array(),
			'changed'           => array(),
			'galleries_deleted' => array(),
		);
		if ( ! is_array( $feed ) ) {
			return $index;
		}
		foreach ( (array) ( $feed['photos_deleted'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
				$index['deleted'][ (string) $entry['id'] ] = array( 'gallery_id' => (string) ( $entry['gallery_id'] ?? '' ) );
			}
		}
		foreach ( (array) ( $feed['photos_changed'] ?? array() ) as $id ) {
			if ( is_scalar( $id ) && '' !== (string) $id ) {
				$index['changed'][ (string) $id ] = true;
			}
		}
		foreach ( (array) ( $feed['galleries_deleted'] ?? array() ) as $id ) {
			if ( is_scalar( $id ) && '' !== (string) $id ) {
				$index['galleries_deleted'][ (string) $id ] = true;
			}
		}
		return $index;
	}

	/**
	 * The stored state, empty for a synced copy.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private static function stored_state( int $attachment_id ): string {
		$state = (string) get_post_meta( $attachment_id, self::META_STATE, true );
		return in_array( $state, array( self::STATE_CHANGED, self::STATE_MISSING, self::STATE_CONFLICT, self::STATE_KEPT ), true ) ? $state : '';
	}

	/**
	 * Hash of the title, caption and alt text the attachment has now.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private static function current_text_hash( int $attachment_id ): string {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		return Media_Baseline::text_hash( (string) $post->post_title, (string) $post->post_excerpt, is_scalar( $alt ) ? (string) $alt : '' );
	}
}
