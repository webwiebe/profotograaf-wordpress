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
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
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

		$this->load_wordpress_files();

		$tmp = download_url( $url, Config::http_timeout() );
		if ( is_wp_error( $tmp ) ) {
			Logger::error(
				'The photo download failed.',
				array(
					'photo' => $photo_id,
					'error' => $tmp->get_error_code(),
				)
			);
			return Api_Errors::make( 'profotograaf_import_download', __( 'The photo could not be downloaded from Profotograaf. Try again later.', 'profotograaf' ), 0, true );
		}

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
	 * Whether a URL is an http(s) address on the platform's own host.
	 *
	 * @param string $url Candidate URL.
	 */
	private function is_platform_url( string $url ): bool {
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
