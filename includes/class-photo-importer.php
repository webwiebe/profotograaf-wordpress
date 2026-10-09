<?php
/**
 * Imports a Profotograaf photo into the Media Library.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a catalogue photo into a normal attachment (media source, see
 * docs/media-source.md).
 *
 * The web variant (long side at most 1600 px) is downloaded server side from
 * the platform's own host and sideloaded. Importing is idempotent by photo id.
 *
 * Uninstall: imported attachments are the site's content and stay. Their
 * `_profotograaf_*` post meta stays with them, so a later reinstall still
 * recognises the copies. uninstall.php removes nothing for this class.
 */
final class Photo_Importer {

	public const META_PHOTO_ID   = '_profotograaf_photo_id';
	public const META_GALLERY_ID = '_profotograaf_gallery_id';
	public const META_VERSION    = '_profotograaf_version';

	/**
	 * Prefix of the per-photo lock options. uninstall.php removes leftovers.
	 */
	public const LOCK_PREFIX = 'profotograaf_import_lock_';

	/**
	 * Seconds after which a lock counts as abandoned (a crashed request).
	 */
	public const LOCK_TTL = 60;

	/**
	 * Times a held lock is waited for, one pause each, before giving up.
	 */
	private const LOCK_WAIT_STEPS = 10;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Photo catalogue, for re-importing. Without one, re-import is unavailable.
	 *
	 * @var Photo_Catalogue|null
	 */
	private ?Photo_Catalogue $catalogue;

	/**
	 * Waits one step while another request holds the photo's lock.
	 *
	 * @var callable
	 */
	private $pause;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings Plugin settings.
	 * @param callable|null        $pause    Waits one step for a held lock (default 0.25 s).
	 * @param Photo_Catalogue|null $catalogue Photo catalogue, needed by reimport().
	 */
	public function __construct( Settings $settings, ?callable $pause = null, ?Photo_Catalogue $catalogue = null ) {
		$this->settings  = $settings;
		$this->catalogue = $catalogue;
		$this->pause     = $pause ?? static function (): void {
			usleep( 250000 );
		};
	}

	/**
	 * Imports a photo, or returns the attachment of an earlier import.
	 *
	 * @param array<string,mixed> $photo Catalogue item: id, gallery_id, title, caption, alt, web_url, version.
	 * @return int|WP_Error Attachment id.
	 */
	public function import( array $photo ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return Api_Errors::make( 'profotograaf_import_forbidden', __( 'You are not allowed to add files to the Media Library.', 'profotograaf' ), 403, false );
		}
		if ( ! $this->settings->media_source_enabled() ) {
			return Api_Errors::make( 'profotograaf_media_source_off', __( 'Using Profotograaf photos in the editor is switched off in the plugin settings.', 'profotograaf' ), 403, false );
		}

		$photo_id = isset( $photo['id'] ) && is_scalar( $photo['id'] ) ? trim( (string) $photo['id'] ) : '';
		if ( '' === $photo_id ) {
			return Api_Errors::make( 'profotograaf_import_invalid', __( 'This photo cannot be imported because it has no identifier.', 'profotograaf' ), 400, false );
		}

		$existing = $this->find( $photo_id );
		if ( $existing > 0 ) {
			return $existing;
		}

		$url = isset( $photo['web_url'] ) && is_string( $photo['web_url'] ) ? $photo['web_url'] : '';
		if ( ! $this->is_platform_url( $url ) ) {
			return Api_Errors::make( 'profotograaf_import_host', __( 'The photo is not on the Profotograaf platform, so it was not imported.', 'profotograaf' ), 400, false );
		}

		$lock = self::LOCK_PREFIX . md5( $photo_id );
		$step = 0;
		while ( ! $this->acquire_lock( $lock ) ) {
			if ( $step++ >= self::LOCK_WAIT_STEPS ) {
				return Api_Errors::make( 'profotograaf_import_busy', __( 'This photo is being imported by another request. Try again in a moment.', 'profotograaf' ), 409, true );
			}
			( $this->pause )();
			$existing = $this->find( $photo_id );
			if ( $existing > 0 ) {
				return $existing;
			}
		}

		try {
			// Another request may have finished between the first lookup and the lock.
			$existing = $this->find( $photo_id );
			if ( $existing > 0 ) {
				return $existing;
			}
			return $this->download_and_store( $photo, $photo_id, $url );
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Replaces the file of an imported attachment with the photo's current web
	 * variant. The attachment id, title, caption and alt text stay. Sizes are
	 * regenerated and the old files are deleted once the new ones exist.
	 *
	 * @param int $attachment_id Attachment of an earlier import.
	 * @return int|WP_Error The attachment id.
	 */
	public function reimport( int $attachment_id ) {
		if ( $attachment_id <= 0 || ! current_user_can( 'edit_post', $attachment_id ) || ! current_user_can( 'upload_files' ) ) {
			return Api_Errors::make( 'profotograaf_reimport_forbidden', __( 'You are not allowed to replace this file.', 'profotograaf' ), 403, false );
		}
		if ( ! $this->settings->media_source_enabled() ) {
			return Api_Errors::make( 'profotograaf_media_source_off', __( 'Using Profotograaf photos in the editor is switched off in the plugin settings.', 'profotograaf' ), 403, false );
		}
		$photo_id = (string) get_post_meta( $attachment_id, self::META_PHOTO_ID, true );
		if ( '' === $photo_id || null === $this->catalogue ) {
			return Api_Errors::make( 'profotograaf_reimport_not_imported', __( 'This file was not imported from Profotograaf.', 'profotograaf' ), 400, false );
		}

		$catalogue = $this->catalogue->get();
		if ( is_wp_error( $catalogue ) ) {
			return $catalogue;
		}
		$photo = null;
		foreach ( $catalogue['photos'] as $row ) {
			if ( (string) $row['id'] === $photo_id ) {
				$photo = $row;
				break;
			}
		}
		if ( null === $photo ) {
			return Api_Errors::make( 'profotograaf_photo_gone', __( 'This photo is no longer available on Profotograaf. The copy in your Media Library is unchanged.', 'profotograaf' ), 404, false );
		}
		$url = isset( $photo['web_url'] ) && is_string( $photo['web_url'] ) ? $photo['web_url'] : '';
		if ( ! $this->is_platform_url( $url ) ) {
			return Api_Errors::make( 'profotograaf_import_host', __( 'The photo is not on the Profotograaf platform, so it was not imported.', 'profotograaf' ), 400, false );
		}

		$lock = self::LOCK_PREFIX . md5( $photo_id );
		if ( ! $this->acquire_lock( $lock ) ) {
			return Api_Errors::make( 'profotograaf_import_busy', __( 'This photo is being imported by another request. Try again in a moment.', 'profotograaf' ), 409, true );
		}
		try {
			$result = $this->replace_file( $attachment_id, $photo, $photo_id, $url );
		} finally {
			delete_option( $lock );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Fires after the file of an imported attachment was replaced.
		 *
		 * @param int                 $attachment_id Attachment id.
		 * @param array<string,mixed> $photo         Catalogue item the file now matches.
		 */
		do_action( 'profotograaf_photo_reimported', $attachment_id, $photo );

		return $attachment_id;
	}

	/**
	 * Downloads the current web variant and swaps it into the attachment. Runs
	 * under the lock. The old files go only after the new ones are in place.
	 *
	 * @param int                 $attachment_id Attachment id.
	 * @param array<string,mixed> $photo         Catalogue item.
	 * @param string              $photo_id      Platform photo id.
	 * @param string              $url           Platform URL of the web variant.
	 * @return true|WP_Error
	 */
	private function replace_file( int $attachment_id, array $photo, string $photo_id, string $url ) {
		$tmp = $this->download( $photo_id, $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$old_file    = (string) get_attached_file( $attachment_id );
		$old_meta    = wp_get_attachment_metadata( $attachment_id );
		$old_backups = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );

		$upload = array(
			'name'     => 'profotograaf-' . preg_replace( '/[^A-Za-z0-9_-]/', '-', $photo_id ) . '.jpg',
			'tmp_name' => $tmp,
		);
		$moved  = wp_handle_sideload( $upload, array( 'test_form' => false ) );
		if ( isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			wp_delete_file( $tmp );
			Logger::error( 'The re-imported photo could not be stored.', array( 'photo' => $photo_id ) );
			return Api_Errors::make( 'profotograaf_import_failed', __( 'The photo could not be added to the Media Library.', 'profotograaf' ), 500, true );
		}
		$new_file = (string) $moved['file'];

		$new_meta = wp_generate_attachment_metadata( $attachment_id, $new_file );
		if ( array() === $new_meta ) {
			wp_delete_file( $new_file );
			Logger::error( 'The sizes of the re-imported photo could not be made.', array( 'photo' => $photo_id ) );
			return Api_Errors::make( 'profotograaf_import_failed', __( 'The photo could not be added to the Media Library.', 'profotograaf' ), 500, true );
		}

		update_attached_file( $attachment_id, $new_file );
		if ( ! empty( $moved['type'] ) ) {
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => (string) $moved['type'],
				)
			);
		}
		wp_update_attachment_metadata( $attachment_id, $new_meta );
		delete_post_meta( $attachment_id, '_wp_attachment_backup_sizes' );
		update_post_meta( $attachment_id, self::META_GALLERY_ID, $this->text( $photo, 'gallery_id' ) );
		update_post_meta( $attachment_id, self::META_VERSION, $this->text( $photo, 'version' ) );

		if ( '' !== $old_file && $old_file !== $new_file ) {
			wp_delete_attachment_files( $attachment_id, is_array( $old_meta ) ? $old_meta : array(), is_array( $old_backups ) ? $old_backups : array(), $old_file );
		}
		return true;
	}

	/**
	 * Takes the per-photo lock, replacing one older than LOCK_TTL.
	 *
	 * @param string $lock Lock option name.
	 */
	private function acquire_lock( string $lock ): bool {
		if ( add_option( $lock, time(), '', false ) ) {
			return true;
		}
		$since = get_option( $lock, 0 );
		if ( is_numeric( $since ) && time() - (int) $since >= self::LOCK_TTL ) {
			delete_option( $lock );
			return (bool) add_option( $lock, time(), '', false );
		}
		return false;
	}

	/**
	 * Downloads the web variant and creates the attachment. Runs under the lock.
	 *
	 * @param array<string,mixed> $photo    Catalogue item.
	 * @param string              $photo_id Platform photo id.
	 * @param string              $url      Platform URL of the web variant.
	 * @return int|WP_Error Attachment id.
	 */
	private function download_and_store( array $photo, string $photo_id, string $url ) {
		$tmp = $this->download( $photo_id, $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		return $this->sideload( $photo, $photo_id, $tmp );
	}

	/**
	 * Downloads the web variant to a temp file, never following a redirect.
	 *
	 * @param string $photo_id Platform photo id.
	 * @param string $url      Platform URL of the web variant.
	 * @return string|WP_Error Temp file path.
	 */
	private function download( string $photo_id, string $url ) {
		$this->load_wordpress_files();

		// The host check covers the first URL only, so redirects stay off.
		$no_redirects = static function ( $args ) {
			$args                = is_array( $args ) ? $args : array();
			$args['redirection'] = 0;
			return $args;
		};
		add_filter( 'http_request_args', $no_redirects, 99 );
		try {
			$tmp = download_url( $url, Config::http_timeout() );
		} finally {
			remove_filter( 'http_request_args', $no_redirects, 99 );
		}
		if ( is_wp_error( $tmp ) ) {
			$data = $tmp->get_error_data();
			$code = is_array( $data ) && isset( $data['code'] ) ? (int) $data['code'] : 0;
			if ( $code >= 300 && $code < 400 ) {
				Logger::error( 'The photo download was redirected.', array( 'photo' => $photo_id ) );
				return Api_Errors::make( 'profotograaf_import_redirect', __( 'Profotograaf redirected the photo download, so it was not imported.', 'profotograaf' ), 502, false );
			}
			Logger::error(
				'The photo download failed.',
				array(
					'photo' => $photo_id,
					'error' => $tmp->get_error_code(),
				)
			);
			return Api_Errors::make( 'profotograaf_import_download', __( 'The photo could not be downloaded from Profotograaf. Try again later.', 'profotograaf' ), 0, true );
		}
		return $tmp;
	}

	/**
	 * Creates the attachment from a downloaded temp file.
	 *
	 * @param array<string,mixed> $photo    Catalogue item.
	 * @param string              $photo_id Platform photo id.
	 * @param string              $tmp      Temp file.
	 * @return int|WP_Error Attachment id.
	 */
	private function sideload( array $photo, string $photo_id, string $tmp ) {
		$title   = $this->text( $photo, 'title' );
		$caption = $this->text( $photo, 'caption' );
		$alt     = $this->text( $photo, 'alt' );

		$attachment_id = media_handle_sideload(
			array(
				'name'     => 'profotograaf-' . preg_replace( '/[^A-Za-z0-9_-]/', '-', $photo_id ) . '.jpg',
				'tmp_name' => $tmp,
			),
			0,
			null,
			array(
				'post_title'   => $title,
				'post_excerpt' => $caption,
			)
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			Logger::error(
				'The photo could not be added to the Media Library.',
				array(
					'photo' => $photo_id,
					'error' => $attachment_id->get_error_code(),
				)
			);
			return Api_Errors::make( 'profotograaf_import_failed', __( 'The photo could not be added to the Media Library.', 'profotograaf' ), 500, true );
		}

		$attachment_id = (int) $attachment_id;
		update_post_meta( $attachment_id, self::META_PHOTO_ID, $photo_id );
		update_post_meta( $attachment_id, self::META_GALLERY_ID, $this->text( $photo, 'gallery_id' ) );
		update_post_meta( $attachment_id, self::META_VERSION, $this->text( $photo, 'version' ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', '' !== $alt ? $alt : $title );

		/**
		 * Fires after a Profotograaf photo became a Media Library attachment.
		 *
		 * @param int                 $attachment_id Attachment id.
		 * @param array<string,mixed> $photo         Catalogue item that was imported.
		 */
		do_action( 'profotograaf_photo_imported', $attachment_id, $photo );

		return $attachment_id;
	}

	/**
	 * The attachment id of an earlier import of a photo, 0 when none.
	 *
	 * @param string $photo_id Platform photo id.
	 */
	public function find( string $photo_id ): int {
		$found = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'meta_key'               => self::META_PHOTO_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup by photo id.
				'meta_value'             => $photo_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- same lookup.
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		return array() === $found ? 0 : (int) $found[0];
	}

	/**
	 * The attachment ids of earlier imports, for several photos at once.
	 *
	 * @param array<int,string> $photo_ids Platform photo ids.
	 * @return array<string,int> Attachment id by photo id, only for imported photos.
	 */
	public function find_many( array $photo_ids ): array {
		$photo_ids = array_values( array_unique( array_filter( $photo_ids, static fn( $id ): bool => '' !== $id ) ) );
		if ( array() === $photo_ids ) {
			return array();
		}
		$found = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup per page of photos.
					array(
						'key'     => self::META_PHOTO_ID,
						'value'   => $photo_ids,
						'compare' => 'IN',
					),
				),
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => count( $photo_ids ) * 2,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$map   = array();
		foreach ( $found as $attachment_id ) {
			$photo_id = (string) get_post_meta( (int) $attachment_id, self::META_PHOTO_ID, true );
			if ( '' !== $photo_id && ! isset( $map[ $photo_id ] ) ) {
				$map[ $photo_id ] = (int) $attachment_id;
			}
		}
		return $map;
	}

	/**
	 * Whether a URL is an http(s) address on the platform's own host.
	 *
	 * @param string $url Candidate URL.
	 */
	public function is_platform_url( string $url ): bool {
		$parts    = wp_parse_url( $url );
		$platform = wp_parse_url( Config::platform_url() );
		if ( ! is_array( $parts ) || ! is_array( $platform ) || ! isset( $parts['host'], $platform['host'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		return strtolower( (string) $parts['host'] ) === strtolower( (string) $platform['host'] );
	}

	/**
	 * A plain-text field of the photo.
	 *
	 * @param array<string,mixed> $photo Catalogue item.
	 * @param string              $key   Field name.
	 */
	private function text( array $photo, string $key ): string {
		return isset( $photo[ $key ] ) && is_scalar( $photo[ $key ] ) ? sanitize_text_field( (string) $photo[ $key ] ) : '';
	}

	/**
	 * Loads the admin files that hold the download and sideload functions.
	 * REST requests and cron do not load them.
	 */
	private function load_wordpress_files(): void {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
