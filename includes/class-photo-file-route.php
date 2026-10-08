<?php
/**
 * Same-origin file route for Profotograaf photos.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the web variant of a catalogue photo from the site's own origin, so
 * the block editor's `window.fetch` can read the bytes and upload them through
 * core's media upload (path A in docs/media-source.md).
 *
 * - GET /profotograaf/v1/photos/{id}/file/{name}.jpg?exp=&sig=&_wpnonce=
 *   needs upload_files (cookie plus REST nonce) and a signature bound to the
 *   photo, the user and an expiry. The route downloads only catalogue photos,
 *   only from the platform's own host.
 * - The `profotograaf_photo_rest_item` filter adds the signed URL to the items
 *   of GET /photos, and swaps the URL of already imported photos for the
 *   attachment's own file.
 * - The uploaded file's name carries the photo id. `rest_after_insert_attachment`
 *   maps the new attachment back to the photo and writes the importer's meta.
 */
final class Photo_File_Route {

	/** File name prefix, the same one the importer uses. */
	public const NAME_PREFIX = 'profotograaf-';

	/**
	 * Photo catalogue.
	 *
	 * @var Photo_Catalogue
	 */
	private Photo_Catalogue $catalogue;

	/**
	 * Importer, for finding photos already in the Media Library.
	 *
	 * @var Photo_Importer
	 */
	private Photo_Importer $importer;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * URL signer.
	 *
	 * @var Photo_Url_Signer
	 */
	private Photo_Url_Signer $signer;

	/**
	 * Temporary file waiting to be streamed.
	 *
	 * @var string
	 */
	private string $pending = '';

	/**
	 * Constructor.
	 *
	 * @param Photo_Catalogue  $catalogue Photo catalogue.
	 * @param Photo_Importer   $importer  Photo importer.
	 * @param Settings         $settings  Settings.
	 * @param Photo_Url_Signer $signer    URL signer.
	 */
	public function __construct( Photo_Catalogue $catalogue, Photo_Importer $importer, Settings $settings, Photo_Url_Signer $signer ) {
		$this->catalogue = $catalogue;
		$this->importer  = $importer;
		$this->settings  = $settings;
		$this->signer    = $signer;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'profotograaf_photo_rest_item', array( $this, 'decorate_item' ), 10, 2 );
		add_action( 'rest_after_insert_attachment', array( $this, 'tag_upload' ), 10, 3 );
	}

	/**
	 * The file name an uploaded copy of a photo carries.
	 *
	 * @param string $photo_id Platform photo id.
	 */
	public static function file_name( string $photo_id ): string {
		return self::NAME_PREFIX . preg_replace( '/[^A-Za-z0-9_-]/', '-', $photo_id ) . '.jpg';
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			Gallery_Rest::NAMESPACE,
			'/photos/(?P<id>[A-Za-z0-9_-]+)/file/(?P<name>[A-Za-z0-9_-]+\.jpg)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'args'                => array(
					'exp' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'sig' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 */
	public function can_upload(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Adds the signed file URL to a REST item.
	 *
	 * @param mixed $item  Inserter item.
	 * @param mixed $photo Catalogue row.
	 * @return mixed
	 */
	public function decorate_item( $item, $photo ) {
		if ( ! is_array( $item ) || ! is_array( $photo ) || ! $this->settings->media_source_enabled() ) {
			return $item;
		}
		if ( isset( $item['id'] ) ) {
			// An imported photo is inserted by id. Its block should point at the local file.
			$url = wp_get_attachment_url( (int) $item['id'] );
			if ( is_string( $url ) && '' !== $url ) {
				$item['url'] = $url;
			}
			return $item;
		}
		$photo_id = isset( $photo['id'] ) && is_scalar( $photo['id'] ) ? (string) $photo['id'] : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $photo_id ) ) {
			return $item;
		}
		$signed      = $this->signer->sign( $photo_id, get_current_user_id() );
		$item['url'] = esc_url_raw(
			add_query_arg(
				array(
					'exp'      => $signed['exp'],
					'sig'      => $signed['sig'],
					'_wpnonce' => wp_create_nonce( 'wp_rest' ),
				),
				self::file_base_url( $photo_id )
			)
		);
		return $item;
	}

	/**
	 * The route URL of a photo file, ending in the file name in its path.
	 *
	 * The editor names the uploaded file after the last path segment of the URL.
	 * With plain permalinks the REST route sits in the query string, so the file
	 * name is added to the path after index.php instead.
	 *
	 * @param string $photo_id Platform photo id.
	 * @return string
	 */
	private static function file_base_url( string $photo_id ): string {
		$name = self::file_name( $photo_id );
		$url  = rest_url( Gallery_Rest::NAMESPACE . '/photos/' . $photo_id . '/file/' . $name );
		if ( str_contains( $url, '?rest_route=' ) ) {
			$url = str_replace( '?rest_route=', '/' . $name . '?rest_route=', $url );
		}
		return $url;
	}

	/**
	 * GET /photos/{id}/file/{name}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function serve( $request ) {
		if ( ! $this->settings->media_source_enabled() ) {
			return Api_Errors::make( 'profotograaf_media_source_off', __( 'Using Profotograaf photos in the editor is switched off in the plugin settings.', 'profotograaf' ), 403, false );
		}

		$photo_id = (string) $request->get_param( 'id' );
		$name     = (string) $request->get_param( 'name' );
		if ( ! $this->signer->verify( $photo_id, get_current_user_id(), (int) $request->get_param( 'exp' ), (string) $request->get_param( 'sig' ) ) || self::file_name( $photo_id ) !== $name ) {
			return Api_Errors::make( 'profotograaf_file_signature', __( 'This photo link is not valid or has expired. Reload the photo list and try again.', 'profotograaf' ), 403, false );
		}

		$photo = $this->find_photo( $photo_id );
		if ( is_wp_error( $photo ) ) {
			return 'profotograaf_file_missing' === $photo->get_error_code() ? $photo : Rest_Errors::from( $photo );
		}

		$url = (string) ( $photo['web_url'] ?? '' );
		if ( ! self::is_platform_url( $url ) ) {
			return Api_Errors::make( 'profotograaf_file_host', __( 'The photo is not on the Profotograaf platform, so it was not served.', 'profotograaf' ), 400, false );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $url, Config::http_timeout() );
		if ( is_wp_error( $tmp ) ) {
			Logger::error(
				'The photo download for the editor failed.',
				array(
					'photo' => $photo_id,
					'error' => $tmp->get_error_code(),
				)
			);
			return Api_Errors::make( 'profotograaf_file_download', __( 'The photo could not be downloaded from Profotograaf. Try again later.', 'profotograaf' ), 502, true );
		}
		if ( ! self::is_jpeg( $tmp ) ) {
			wp_delete_file( $tmp );
			Logger::error( 'The platform sent something other than a JPEG for a photo.', array( 'photo' => $photo_id ) );
			return Api_Errors::make( 'profotograaf_file_type', __( 'Profotograaf sent a file that is not a JPEG image, so it was not used.', 'profotograaf' ), 502, true );
		}

		$this->pending = $tmp;
		add_filter( 'rest_pre_serve_request', array( $this, 'stream' ), 10, 1 );
		return new \WP_REST_Response(
			null,
			200,
			array(
				'Content-Type'           => 'image/jpeg',
				'Content-Length'         => (string) filesize( $tmp ),
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			)
		);
	}

	/**
	 * Writes the downloaded bytes as the response body, then removes the file.
	 *
	 * @param mixed $served Whether the request was already served.
	 * @return mixed
	 */
	public function stream( $served ) {
		remove_filter( 'rest_pre_serve_request', array( $this, 'stream' ), 10 );
		if ( '' === $this->pending ) {
			return $served;
		}
		$file          = $this->pending;
		$this->pending = '';
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams a temporary image file.
		wp_delete_file( $file );
		return true;
	}

	/**
	 * Writes the importer's meta on an upload that carries a photo's file name.
	 *
	 * @param mixed $attachment Inserted attachment (WP_Post).
	 * @param mixed $request    Request.
	 * @param mixed $creating   Whether the attachment was created.
	 */
	public function tag_upload( $attachment, $request, $creating ): void {
		unset( $request );
		if ( true !== $creating || ! is_object( $attachment ) || ! isset( $attachment->ID ) ) {
			return;
		}
		if ( ! $this->settings->media_source_enabled() || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$attachment_id = (int) $attachment->ID;
		$file          = basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		if ( 0 !== strpos( $file, self::NAME_PREFIX ) ) {
			return;
		}
		$catalogue = $this->catalogue->get();
		if ( is_wp_error( $catalogue ) ) {
			return;
		}
		$photo = self::match_file( $file, $catalogue['photos'] );
		if ( null === $photo ) {
			return;
		}
		$photo_id = (string) $photo['id'];
		if ( $this->importer->find( $photo_id ) > 0 ) {
			return;
		}

		update_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, $photo_id );
		update_post_meta( $attachment_id, Photo_Importer::META_GALLERY_ID, (string) ( $photo['gallery_id'] ?? '' ) );
		update_post_meta( $attachment_id, Photo_Importer::META_VERSION, (string) ( $photo['version'] ?? '' ) );
		$alt   = (string) ( $photo['alt'] ?? '' );
		$title = (string) ( $photo['title'] ?? '' );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( '' !== $alt ? $alt : $title ) );

		/** This action is documented in includes/class-photo-importer.php. */
		do_action( 'profotograaf_photo_imported', $attachment_id, $photo );
	}

	/**
	 * The catalogue photo an uploaded file name belongs to.
	 *
	 * WordPress may add a numeric suffix to a name that is taken, so a name with
	 * and without that suffix is tried.
	 *
	 * @param string                         $file   File name.
	 * @param array<int,array<string,mixed>> $photos Catalogue rows.
	 * @return array<string,mixed>|null
	 */
	private static function match_file( string $file, array $photos ): ?array {
		$candidates = array( $file );
		$trimmed    = preg_replace( '/-\d+(\.jpg)$/', '$1', $file );
		if ( is_string( $trimmed ) && $trimmed !== $file ) {
			$candidates[] = $trimmed;
		}
		foreach ( $candidates as $candidate ) {
			foreach ( $photos as $photo ) {
				if ( self::file_name( (string) ( $photo['id'] ?? '' ) ) === $candidate ) {
					return $photo;
				}
			}
		}
		return null;
	}

	/**
	 * A photo of the catalogue by id.
	 *
	 * @param string $photo_id Platform photo id.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function find_photo( string $photo_id ) {
		$catalogue = $this->catalogue->get();
		if ( is_wp_error( $catalogue ) ) {
			return $catalogue;
		}
		foreach ( $catalogue['photos'] as $photo ) {
			if ( (string) $photo['id'] === $photo_id ) {
				return $photo;
			}
		}
		return Api_Errors::make( 'profotograaf_file_missing', __( 'This photo is no longer available from Profotograaf.', 'profotograaf' ), 404, false );
	}

	/**
	 * Whether a URL is an http(s) address on the platform's own host.
	 *
	 * @param string $url Candidate URL.
	 */
	private static function is_platform_url( string $url ): bool {
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
	 * Whether a file starts with the JPEG signature.
	 *
	 * @param string $path File path.
	 */
	private static function is_jpeg( string $path ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		$head = file_get_contents( $path, false, null, 0, 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads three bytes of a local temporary file.
		return "\xFF\xD8\xFF" === $head;
	}
}
