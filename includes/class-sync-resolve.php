<?php
/**
 * Resolving a copy that changed on Profotograaf.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * The three ways out of a conflict and the per-field text update
 * (docs/media-lifecycle.md, "Resolving a conflict").
 *
 * Every method runs from a click of a person with `edit_post` on the
 * attachment, through Sync_Actions_Rest. A remote change is applied only by
 * use_remote(), keep_both() and update_text(), never by a poll.
 */
final class Sync_Resolve {

	public const CHOICE_KEEP_MINE  = 'keep_mine';
	public const CHOICE_USE_REMOTE = 'use_remote';
	public const CHOICE_KEEP_BOTH  = 'keep_both';

	/** Text fields, by the name the dialog and the route use. */
	public const FIELDS = array( 'title', 'caption', 'alt' );

	/**
	 * Replaces the file of an attachment with the platform's current one:
	 * Photo_Importer::reimport(), built with a catalogue.
	 *
	 * @var \Closure
	 */
	private \Closure $reimport;

	/**
	 * Photo catalogue.
	 *
	 * @var Photo_Catalogue
	 */
	private Photo_Catalogue $catalogue;

	/**
	 * Constructor.
	 *
	 * @param callable        $reimport  Receives an attachment id, returns the id or a WP_Error.
	 * @param Photo_Catalogue $catalogue Catalogue.
	 */
	public function __construct( callable $reimport, Photo_Catalogue $catalogue ) {
		$this->reimport  = \Closure::fromCallable( $reimport );
		$this->catalogue = $catalogue;
	}

	/**
	 * What the dialog shows: the site copy next to the platform web image, and
	 * the fields whose values differ.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function compare( int $attachment_id ) {
		$photo = $this->photo( $attachment_id );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}
		$state  = (string) get_post_meta( $attachment_id, Reconcile::META_STATE, true );
		$remote = self::remote_text( $photo );
		$site   = self::site_text( $attachment_id );
		$labels = array(
			'title'   => __( 'Title', 'profotograaf' ),
			'caption' => __( 'Caption', 'profotograaf' ),
			'alt'     => __( 'Alt text', 'profotograaf' ),
		);
		$fields = array();
		foreach ( self::FIELDS as $field ) {
			$platform = 'alt' === $field ? $remote['raw_alt'] : $remote[ $field ];
			if ( $platform === $site[ $field ] ) {
				continue;
			}
			$fields[] = array(
				'key'      => $field,
				'label'    => $labels[ $field ],
				'site'     => $site[ $field ],
				'platform' => $platform,
				// An empty platform value never replaces a local one, so the dialog offers no choice there.
				'takeable' => '' !== $platform,
			);
		}
		$file_edited = $this->file_edited( $attachment_id );
		return array(
			'attachment_id'  => $attachment_id,
			'state'          => $state,
			'file_changed'   => '' !== (string) get_post_meta( $attachment_id, Reconcile::META_REMOTE_VERSION, true ),
			'file_edited'    => $file_edited,
			'site_url'       => (string) wp_get_attachment_image_url( $attachment_id, 'medium' ),
			'site_file_url'  => (string) wp_get_attachment_url( $attachment_id ),
			'platform_url'   => (string) ( $photo['web_url'] ?? '' ),
			'platform_thumb' => (string) ( $photo['thumb_url'] ?? '' ),
			'fields'         => $fields,
		);
	}

	/**
	 * Keep mine: the platform's current version counts as seen, the local file
	 * and text become the baseline, and the copy is in sync. Later platform
	 * changes flag again.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function keep_mine( int $attachment_id ) {
		$check = $this->check( $attachment_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$remote_version = (string) get_post_meta( $attachment_id, Reconcile::META_REMOTE_VERSION, true );
		$photo          = $this->photo( $attachment_id );
		if ( '' === $remote_version && ! is_wp_error( $photo ) ) {
			$remote_version = (string) ( $photo['version'] ?? '' );
		}
		if ( '' !== $remote_version ) {
			update_post_meta( $attachment_id, Photo_Importer::META_VERSION, $remote_version );
		}
		$this->record_baseline( $attachment_id, is_wp_error( $photo ) ? null : $photo );
		$this->settle( $attachment_id, Reconcile::STATE_SYNCED );
		return $this->result( $attachment_id, Reconcile::STATE_SYNCED );
	}

	/**
	 * Use Profotograaf's version: replaces the file with the current web
	 * variant. The local file edit is lost. The text stays as the site has it.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function use_remote( int $attachment_id ) {
		$check = $this->check( $attachment_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$result = ( $this->reimport )( $attachment_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->settle( $attachment_id, Reconcile::STATE_SYNCED );
		return $this->result( $attachment_id, Reconcile::STATE_SYNCED );
	}

	/**
	 * Keep both: copies the attachment with its file and meta, without the
	 * links to the platform, then re-imports the original. The copy is an
	 * ordinary file. A failed re-import removes the copy again.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function keep_both( int $attachment_id ) {
		$check = $this->check( $attachment_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$copy = $this->duplicate( $attachment_id );
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}
		$result = ( $this->reimport )( $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_delete_attachment( $copy, true );
			return $result;
		}
		$this->settle( $attachment_id, Reconcile::STATE_SYNCED );
		return $this->result( $attachment_id, Reconcile::STATE_SYNCED ) + array( 'copy_id' => $copy );
	}

	/**
	 * Update text: takes the platform value for the listed fields. A field
	 * that is not listed keeps the site's value, and an empty platform value
	 * never replaces a non-empty local one. The platform text then counts as
	 * seen, so a field kept as it is does not flag again until the platform
	 * changes it once more.
	 *
	 * @param int      $attachment_id Attachment id.
	 * @param string[] $fields        Field names to take from the platform.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function update_text( int $attachment_id, array $fields ) {
		$check = $this->check( $attachment_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$photo = $this->photo( $attachment_id );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}
		$remote  = self::remote_text( $photo );
		$post    = array( 'ID' => $attachment_id );
		$applied = array();
		foreach ( array_intersect( self::FIELDS, $fields ) as $field ) {
			$value = 'alt' === $field ? $remote['raw_alt'] : $remote[ $field ];
			if ( '' === $value ) {
				continue;
			}
			$applied[] = $field;
			if ( 'title' === $field ) {
				$post['post_title'] = $value;
			} elseif ( 'caption' === $field ) {
				$post['post_excerpt'] = $value;
			} else {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $value );
			}
		}
		if ( count( $post ) > 1 ) {
			wp_update_post( $post );
		}
		update_post_meta( $attachment_id, Media_Baseline::META_TEXT_HASH, Media_Baseline::current_text_hash( $attachment_id ) );
		update_post_meta( $attachment_id, Reconcile::META_ACCEPTED_TEXT, $remote['hash'] );

		// A file change still waits for its own decision.
		$file_pending = '' !== (string) get_post_meta( $attachment_id, Reconcile::META_REMOTE_VERSION, true );
		if ( $file_pending ) {
			$to = $this->file_edited( $attachment_id ) ? Reconcile::STATE_CONFLICT : Reconcile::STATE_CHANGED;
		} else {
			$to = Reconcile::STATE_SYNCED;
		}
		$this->settle( $attachment_id, $to );
		return $this->result( $attachment_id, $to ) + array( 'updated' => $applied );
	}

	/**
	 * Platform text of a catalogue row, as the importer stores it.
	 *
	 * @param array<string,mixed> $photo Catalogue row.
	 * @return array{title:string,caption:string,alt:string,raw_alt:string,hash:string}
	 */
	public static function remote_text( array $photo ): array {
		$title   = sanitize_text_field( (string) ( $photo['title'] ?? '' ) );
		$caption = sanitize_text_field( (string) ( $photo['caption'] ?? '' ) );
		$alt     = sanitize_text_field( (string) ( $photo['alt'] ?? '' ) );
		$stored  = '' !== $alt ? $alt : $title;
		return array(
			'title'   => $title,
			'caption' => $caption,
			'alt'     => $stored,
			'raw_alt' => $alt,
			'hash'    => Media_Baseline::text_hash( $title, $caption, $stored ),
		);
	}

	/**
	 * The text the site holds now.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{title:string,caption:string,alt:string}
	 */
	private static function site_text( int $attachment_id ): array {
		$post = get_post( $attachment_id );
		$alt  = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		return array(
			'title'   => $post instanceof \WP_Post ? (string) $post->post_title : '',
			'caption' => $post instanceof \WP_Post ? (string) $post->post_excerpt : '',
			'alt'     => is_scalar( $alt ) ? (string) $alt : '',
		);
	}

	/**
	 * Whether the stored original differs from the one the plugin wrote.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private function file_edited( int $attachment_id ): bool {
		$base = (string) get_post_meta( $attachment_id, Media_Baseline::META_FILE_HASH, true );
		$now  = Media_Baseline::file_hash( $attachment_id );
		return '' !== $base && '' !== $now && $base !== $now;
	}

	/**
	 * The catalogue row of the copy's photo.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function photo( int $attachment_id ) {
		$photo_id  = (string) get_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, true );
		$catalogue = $this->catalogue->get();
		if ( is_wp_error( $catalogue ) ) {
			return $catalogue;
		}
		foreach ( $catalogue['photos'] as $row ) {
			if ( (string) $row['id'] === $photo_id ) {
				return $row;
			}
		}
		return new \WP_Error( 'profotograaf_photo_gone', __( 'This photo is no longer available on Profotograaf. The copy in your Media Library is unchanged.', 'profotograaf' ), array( 'status' => 404 ) );
	}

	/**
	 * Refuses anything but a flagged copy the sync can act on.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return true|\WP_Error
	 */
	private function check( int $attachment_id ) {
		if ( ! Sync_Actions::is_copy( $attachment_id ) ) {
			return new \WP_Error( 'profotograaf_not_a_copy', __( 'This file is not a copy imported from Profotograaf.', 'profotograaf' ), array( 'status' => 404 ) );
		}
		$state = (string) get_post_meta( $attachment_id, Reconcile::META_STATE, true );
		if ( ! in_array( $state, array( Reconcile::STATE_CHANGED, Reconcile::STATE_CONFLICT ), true ) ) {
			return new \WP_Error( 'profotograaf_nothing_to_resolve', __( 'This photo has no change to resolve.', 'profotograaf' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Records the file and text the site has now as the baseline, and the
	 * platform text as seen.
	 *
	 * @param int                      $attachment_id Attachment id.
	 * @param array<string,mixed>|null $photo         Catalogue row, null when it is unknown.
	 */
	private function record_baseline( int $attachment_id, ?array $photo ): void {
		$file = Media_Baseline::file_hash( $attachment_id );
		if ( '' !== $file ) {
			update_post_meta( $attachment_id, Media_Baseline::META_FILE_HASH, $file );
		}
		update_post_meta( $attachment_id, Media_Baseline::META_TEXT_HASH, Media_Baseline::current_text_hash( $attachment_id ) );
		if ( null !== $photo ) {
			update_post_meta( $attachment_id, Reconcile::META_ACCEPTED_TEXT, self::remote_text( $photo )['hash'] );
		}
	}

	/**
	 * Moves the copy to a state and keeps the counts in step.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $to            Target state.
	 */
	private function settle( int $attachment_id, string $to ): void {
		$from   = (string) get_post_meta( $attachment_id, Reconcile::META_STATE, true );
		$counts = Reconcile::counts();
		if ( isset( $counts[ $from ] ) ) {
			$counts[ $from ] = max( 0, $counts[ $from ] - 1 );
		}
		if ( isset( $counts[ $to ] ) ) {
			++$counts[ $to ];
		}
		update_option( Reconcile::COUNTS_OPTION, $counts, false );
		if ( Reconcile::STATE_SYNCED === $to ) {
			foreach ( array( Reconcile::META_STATE, Reconcile::META_REASON, Reconcile::META_SINCE, Reconcile::META_REMOTE_VERSION ) as $key ) {
				delete_post_meta( $attachment_id, $key );
			}
			return;
		}
		update_post_meta( $attachment_id, Reconcile::META_STATE, $to );
	}

	/**
	 * Result body of a resolution.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $state         State after the action.
	 * @return array<string,mixed>
	 */
	private function result( int $attachment_id, string $state ): array {
		return array(
			'attachment_id' => $attachment_id,
			'state'         => $state,
		);
	}

	/**
	 * Copies the attachment: the file, the post fields and the meta that is
	 * not part of the link to the platform.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return int|\WP_Error Id of the copy.
	 */
	private function duplicate( int $attachment_id ) {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$post = get_post( $attachment_id );
		$file = get_attached_file( $attachment_id );
		if ( ! $post instanceof \WP_Post || ! is_string( $file ) || ! is_file( $file ) ) {
			return $this->duplicate_failed();
		}
		$target = dirname( $file ) . '/' . wp_unique_filename( dirname( $file ), basename( $file ) );
		if ( ! copy( $file, $target ) ) {
			return $this->duplicate_failed();
		}
		$copy = wp_insert_attachment(
			array(
				'post_title'     => $post->post_title,
				'post_excerpt'   => $post->post_excerpt,
				'post_content'   => $post->post_content,
				'post_mime_type' => $post->post_mime_type,
				'post_status'    => 'inherit',
			),
			$target,
			0
		);
		if ( 0 === $copy ) {
			wp_delete_file( $target );
			return $this->duplicate_failed();
		}
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( is_scalar( $alt ) && '' !== (string) $alt ) {
			update_post_meta( $copy, '_wp_attachment_image_alt', (string) $alt );
		}
		wp_update_attachment_metadata( $copy, wp_generate_attachment_metadata( $copy, $target ) );
		return $copy;
	}

	/**
	 * Error for a copy that could not be made.
	 */
	private function duplicate_failed(): \WP_Error {
		return new \WP_Error( 'profotograaf_duplicate_failed', __( 'The copy of this file could not be made, so nothing was changed.', 'profotograaf' ), array( 'status' => 500 ) );
	}
}
