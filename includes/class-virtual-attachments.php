<?php
/**
 * Prototype: Profotograaf photos as virtual attachments.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * An attachment post that has no file in the uploads folder. Its URLs, sizes and
 * dimensions come from post meta that was written from the photo catalogue
 * (spike #104, see docs/virtual-attachments.md).
 *
 * It is switched on by the setting "Keep photos on Profotograaf"
 * (`virtual_attachments`, off by default). The filter
 * `profotograaf_virtual_attachments` can override the setting. With it off
 * nothing here registers a hook, only convert_to_local() still acts on an
 * attachment that carries the mark (issue #152), and the Import from
 * Profotograaf screen shows no "Keep on Profotograaf" choice (issue #151).
 *
 * Rules the filters follow:
 *
 * - They read stored post meta only. They never call the platform, so a front
 *   end request never waits on it (CONTRIBUTING.md).
 * - They act on attachments carrying META_VIRTUAL and return every other value
 *   unchanged.
 *
 * Stored per virtual attachment: META_PHOTO_ID, META_GALLERY_ID and
 * META_VERSION (shared with Photo_Importer), META_VIRTUAL, META_URLS (the
 * `thumb` and `web` URLs) and `_wp_attachment_metadata` (width, height, file,
 * sizes). Writes happen in create(), sync() and Virtual_Sync, all off the
 * front end.
 *
 * The scheduled sync reads the change feed through Virtual_Sync (issue #153)
 * and falls back to the full photo list when the feed answers resync.
 */
final class Virtual_Attachments {

	use Virtual_Attachment_Filters;

	public const FILTER = 'profotograaf_virtual_attachments';

	/** Marks an attachment as virtual. */
	public const META_VIRTUAL = '_profotograaf_virtual';

	/** The `thumb` and `web` URLs of the photo. */
	public const META_URLS = '_profotograaf_virtual_urls';

	/** Unix time at which a sync first found that the platform no longer serves the photo. */
	public const META_GONE = '_profotograaf_virtual_gone';

	/** Cron hook that runs sync(). */
	public const SYNC_HOOK = 'profotograaf_virtual_sync';

	/** Filter that lets image editing through for virtual attachments. Default true blocks it. */
	public const BLOCK_EDITING_FILTER = 'profotograaf_virtual_attachments_block_editing';

	/** Directory part of `_wp_attached_file`. The folder does not exist under uploads. */
	public const FILE_DIR = 'profotograaf-virtual';

	/** Long side of the platform's `web` variant. */
	private const WEB_MAX = 1600;

	/** Side of the platform's square `thumb` variant. */
	private const THUMB_SIDE = 400;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Importer, for the idempotency lookup and for converting to local.
	 *
	 * @var Photo_Importer
	 */
	private Photo_Importer $importer;

	/**
	 * Catalogue that the scheduled sync reads on a resync.
	 *
	 * @var Photo_Catalogue|null
	 */
	private ?Photo_Catalogue $catalogue;

	/**
	 * API client that the scheduled sync reads the change feed with.
	 *
	 * @var Api_Client|null
	 */
	private ?Api_Client $api;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings  Plugin settings.
	 * @param Photo_Importer       $importer  Importer, built with a catalogue so that reimport() works.
	 * @param Photo_Catalogue|null $catalogue Catalogue for the scheduled sync.
	 * @param Api_Client|null      $api       API client for the scheduled sync.
	 */
	public function __construct( Settings $settings, Photo_Importer $importer, ?Photo_Catalogue $catalogue = null, ?Api_Client $api = null ) {
		$this->settings  = $settings;
		$this->importer  = $importer;
		$this->catalogue = $catalogue;
		$this->api       = $api;
	}

	/**
	 * Whether the setting "Keep photos on Profotograaf" is on.
	 */
	public static function enabled(): bool {
		$value = ( new Settings() )->virtual_attachments_enabled();

		/**
		 * Overrides the setting "Keep photos on Profotograaf". Only the boolean
		 * true switches virtual attachments on.
		 *
		 * @param bool $enabled Whether the setting is on.
		 */
		return true === apply_filters( 'profotograaf_virtual_attachments', $value );
	}

	/**
	 * Adds the read-only filters and the sync schedule.
	 */
	public function register(): void {
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_url' ), 20, 2 );
		add_filter( 'image_downsize', array( $this, 'filter_downsize' ), 20, 3 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset' ), 20, 5 );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'filter_js' ), 20, 2 );
		add_filter( 'load_image_to_edit_path', array( $this, 'filter_edit_path' ), 20, 2 );
		add_action( self::SYNC_HOOK, array( $this, 'run_sync' ) );
		add_action( 'init', array( $this, 'schedule_sync' ) );
	}

	/**
	 * Whether an attachment is a virtual one. Reads post meta only.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function is_virtual( int $attachment_id ): bool {
		return self::enabled() && self::is_marked( $attachment_id );
	}

	/**
	 * Creates a virtual attachment for a catalogue photo, or returns the
	 * attachment of an earlier import or virtual attachment of the same photo.
	 *
	 * No download, no request to the platform.
	 *
	 * @param array<string,mixed> $photo Catalogue row: id, gallery_id, title, caption, alt, width, height, thumb_url, web_url, version.
	 * @return int|WP_Error Attachment id.
	 */
	public function create( array $photo ) {
		if ( ! self::enabled() ) {
			return Api_Errors::make( 'profotograaf_virtual_off', __( 'Keeping photos on Profotograaf is switched off in the plugin settings.', 'profotograaf' ), 403, false );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return Api_Errors::make( 'profotograaf_import_forbidden', __( 'You are not allowed to add files to the Media Library.', 'profotograaf' ), 403, false );
		}
		if ( ! $this->settings->media_source_enabled() ) {
			return Api_Errors::make( 'profotograaf_media_source_off', __( 'Using Profotograaf photos in the editor is switched off in the plugin settings.', 'profotograaf' ), 403, false );
		}
		$photo_id = $this->text( $photo, 'id' );
		if ( '' === $photo_id ) {
			return Api_Errors::make( 'profotograaf_import_invalid', __( 'This photo cannot be imported because it has no identifier.', 'profotograaf' ), 400, false );
		}
		$existing = $this->importer->find( $photo_id );
		if ( $existing > 0 ) {
			return $existing;
		}

		$web   = $this->text( $photo, 'web_url' );
		$thumb = $this->text( $photo, 'thumb_url' );
		if ( ! $this->importer->is_platform_url( $web ) ) {
			return Api_Errors::make( 'profotograaf_import_host', __( 'The photo is not on the Profotograaf platform, so it was not imported.', 'profotograaf' ), 400, false );
		}
		if ( ! $this->importer->is_platform_url( $thumb ) ) {
			$thumb = $web;
		}

		$version = $this->text( $photo, 'version' );
		$title   = $this->text( $photo, 'title' );
		$alt     = $this->text( $photo, 'alt' );
		$file    = self::FILE_DIR . '/' . preg_replace( '/[^A-Za-z0-9_-]/', '-', $photo_id ) . '/web-' . ( '' !== $version ? $version : 'v0' ) . '.jpg';

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => $title,
				'post_excerpt'   => $this->text( $photo, 'caption' ),
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				'guid'           => $web,
			),
			$file,
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			Logger::error( 'The virtual attachment could not be created.', array( 'photo' => $photo_id ) );
			return Api_Errors::make( 'profotograaf_import_failed', __( 'The photo could not be added to the Media Library.', 'profotograaf' ), 500, true );
		}
		$attachment_id = (int) $attachment_id;

		update_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, $photo_id );
		update_post_meta( $attachment_id, Photo_Importer::META_GALLERY_ID, $this->text( $photo, 'gallery_id' ) );
		update_post_meta( $attachment_id, Photo_Importer::META_VERSION, $version );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', '' !== $alt ? $alt : $title );
		$this->store( $attachment_id, $photo, $web, $thumb, $file );
		update_post_meta( $attachment_id, self::META_VIRTUAL, '1' );

		/**
		 * Fires after a virtual attachment was created for a Profotograaf photo.
		 *
		 * @param int                 $attachment_id Attachment id.
		 * @param array<string,mixed> $photo         Catalogue row.
		 */
		do_action( 'profotograaf_virtual_attachment_created', $attachment_id, $photo );

		return $attachment_id;
	}

	/**
	 * Whether an attachment carries the virtual mark, whatever the setting.
	 * Convert to local uses it, so attachments kept while the setting was on
	 * can still be converted after it was switched off.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function is_marked( int $attachment_id ): bool {
		return $attachment_id > 0 && '1' === (string) get_post_meta( $attachment_id, self::META_VIRTUAL, true );
	}

	/**
	 * Whether a marked attachment's photo left the catalogue (META_GONE).
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function is_gone( int $attachment_id ): bool {
		return self::is_marked( $attachment_id ) && '' !== (string) get_post_meta( $attachment_id, self::META_GONE, true );
	}

	/**
	 * Turns a virtual attachment into a normal import, in place. The attachment
	 * id stays, so posts that use it keep working. Photo_Importer::reimport()
	 * downloads the file the way Import does and generates the sizes. A photo
	 * that left the catalogue is downloaded from its stored `web` URL, which
	 * works while Profotograaf still serves it.
	 *
	 * It works on any attachment with the virtual mark, also after the setting
	 * was switched off. On failure the attachment stays virtual and unchanged.
	 *
	 * @param int $attachment_id Virtual attachment.
	 * @return int|WP_Error The attachment id.
	 */
	public function convert_to_local( int $attachment_id ) {
		if ( ! self::is_marked( $attachment_id ) ) {
			return Api_Errors::make( 'profotograaf_not_virtual', __( 'This file is not a virtual Profotograaf attachment.', 'profotograaf' ), 400, false );
		}
		$urls     = get_post_meta( $attachment_id, self::META_URLS, true );
		$fallback = array(
			'id'         => (string) get_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, true ),
			'gallery_id' => (string) get_post_meta( $attachment_id, Photo_Importer::META_GALLERY_ID, true ),
			'version'    => (string) get_post_meta( $attachment_id, Photo_Importer::META_VERSION, true ),
			'web_url'    => is_array( $urls ) && isset( $urls['web'] ) && is_string( $urls['web'] ) ? $urls['web'] : '',
		);
		$result   = $this->importer->reimport( $attachment_id, $fallback );
		if ( is_wp_error( $result ) ) {
			if ( self::is_gone( $attachment_id ) && 'profotograaf_import_download' === $result->get_error_code() ) {
				return Api_Errors::make( 'profotograaf_photo_gone', __( 'Profotograaf no longer serves this photo, so no local copy could be made. It stays kept on Profotograaf.', 'profotograaf' ), 404, false );
			}
			return $result;
		}
		delete_post_meta( $attachment_id, self::META_VIRTUAL );
		delete_post_meta( $attachment_id, self::META_URLS );
		delete_post_meta( $attachment_id, self::META_GONE );

		/**
		 * Fires after a virtual attachment became a normal import.
		 *
		 * @param int $attachment_id Attachment id.
		 */
		do_action( 'profotograaf_virtual_attachment_converted', $attachment_id );

		return $attachment_id;
	}

	/**
	 * Refreshes the stored data of every virtual attachment from a catalogue.
	 *
	 * Photos still listed get their URLs, version and size updated. A photo that
	 * is missing from a fresh, complete catalogue gets META_GONE once. A stale
	 * catalogue (the platform failed) changes nothing, so an outage never marks
	 * photos gone. A catalogue that hit its size limit (`dropped`) marks nothing
	 * gone, because a missing photo may only have been left out.
	 *
	 * @param array{photos:array<int,array<string,mixed>>,stale:bool,dropped?:int} $catalogue Result of Photo_Catalogue::get().
	 * @return array{updated:int,gone:int,skipped:bool}
	 */
	public function sync( array $catalogue ): array {
		$result = array(
			'updated' => 0,
			'gone'    => 0,
			'skipped' => false,
		);
		if ( ! empty( $catalogue['stale'] ) ) {
			$result['skipped'] = true;
			return $result;
		}
		$by_id    = Virtual_Sync::by_id( $catalogue['photos'] );
		$complete = (int) ( $catalogue['dropped'] ?? 0 ) <= 0;
		foreach ( $this->virtual_ids() as $attachment_id ) {
			$photo_id = (string) get_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, true );
			if ( isset( $by_id[ $photo_id ] ) ) {
				$result['updated'] += $this->refresh_from( $attachment_id, $by_id[ $photo_id ] ) ? 1 : 0;
				continue;
			}
			if ( $complete && Virtual_Sync::mark_gone( $attachment_id ) ) {
				++$result['gone'];
			}
		}
		return $result;
	}

	/**
	 * Writes fresh URLs, version, size and gallery of a listed photo to its
	 * virtual attachment and clears a gone mark. A row whose `web` URL is not on
	 * the platform host changes nothing.
	 *
	 * @param int                 $attachment_id Virtual attachment.
	 * @param array<string,mixed> $photo         Catalogue row.
	 * @return bool Whether it wrote.
	 */
	public function refresh_from( int $attachment_id, array $photo ): bool {
		$web   = $this->text( $photo, 'web_url' );
		$thumb = $this->text( $photo, 'thumb_url' );
		if ( ! $this->importer->is_platform_url( $web ) ) {
			return false;
		}
		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$this->store( $attachment_id, $photo, $web, $this->importer->is_platform_url( $thumb ) ? $thumb : $web, $file );
		update_post_meta( $attachment_id, Photo_Importer::META_VERSION, $this->text( $photo, 'version' ) );
		$gallery = $this->text( $photo, 'gallery_id' );
		if ( '' !== $gallery ) {
			update_post_meta( $attachment_id, Photo_Importer::META_GALLERY_ID, $gallery );
		}
		delete_post_meta( $attachment_id, self::META_GONE );
		return true;
	}

	/**
	 * Cron callback: syncs from the change feed (Virtual_Sync), with the full
	 * photo list on a resync. Never runs on a front end request.
	 */
	public function run_sync(): void {
		if ( null === $this->catalogue || null === $this->api ) {
			return;
		}
		( new Virtual_Sync( $this->api, $this->catalogue, $this ) )->run();
	}

	/**
	 * Schedules the sync every hour while the setting is on, the interval of
	 * the change feed poll for imported copies.
	 */
	public function schedule_sync(): void {
		if ( ! wp_next_scheduled( self::SYNC_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::SYNC_HOOK );
		}
	}

	/**
	 * The stored URLs of a virtual attachment, null for any other attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{thumb:string,web:string}|null
	 */
	private function urls( int $attachment_id ): ?array {
		if ( ! $this->is_virtual( $attachment_id ) ) {
			return null;
		}
		$urls = get_post_meta( $attachment_id, self::META_URLS, true );
		if ( ! is_array( $urls ) || empty( $urls['web'] ) || ! is_string( $urls['web'] ) ) {
			return null;
		}
		$thumb = isset( $urls['thumb'] ) && is_string( $urls['thumb'] ) && '' !== $urls['thumb'] ? $urls['thumb'] : $urls['web'];
		return array(
			'thumb' => $thumb,
			'web'   => $urls['web'],
		);
	}

	/**
	 * Writes the URLs and `_wp_attachment_metadata` of a virtual attachment.
	 *
	 * @param int                 $attachment_id Attachment id.
	 * @param array<string,mixed> $photo         Catalogue row.
	 * @param string              $web           `web` URL.
	 * @param string              $thumb         `thumb` URL.
	 * @param string              $file          Value of `_wp_attached_file`.
	 */
	private function store( int $attachment_id, array $photo, string $web, string $thumb, string $file ): void {
		list( $width, $height ) = $this->web_size( $photo );
		$version                = $this->text( $photo, 'version' );
		$sizes                  = array();
		if ( $thumb !== $web ) {
			$sizes['thumbnail'] = array(
				'file'      => 'thumb-' . ( '' !== $version ? $version : 'v0' ) . '.jpg',
				'width'     => self::THUMB_SIDE,
				'height'    => self::THUMB_SIDE,
				'mime-type' => 'image/jpeg',
			);
		}
		update_post_meta(
			$attachment_id,
			self::META_URLS,
			array(
				'thumb' => $thumb,
				'web'   => $web,
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'      => $width,
				'height'     => $height,
				'file'       => $file,
				// There is no local file to measure. WordPress 7.1 core reads
				// $meta['filesize'] unguarded when the image editor backs up the
				// original (wp-admin/includes/image-edit.php), a PHP warning without it.
				'filesize'   => 0,
				'sizes'      => $sizes,
				'image_meta' => array(),
			)
		);
	}

	/**
	 * Size of the `web` variant: the photo's shape with the long side at most 1600 px.
	 *
	 * @param array<string,mixed> $photo Catalogue row.
	 * @return array{0:int,1:int}
	 */
	private function web_size( array $photo ): array {
		$width  = (int) ( $photo['width'] ?? 0 );
		$height = (int) ( $photo['height'] ?? 0 );
		if ( $width <= 0 || $height <= 0 ) {
			return array( self::WEB_MAX, self::WEB_MAX );
		}
		$long = max( $width, $height );
		if ( $long <= self::WEB_MAX ) {
			return array( $width, $height );
		}
		$scale = self::WEB_MAX / $long;
		return array( max( 1, (int) round( $width * $scale ) ), max( 1, (int) round( $height * $scale ) ) );
	}

	/**
	 * Ids of every virtual attachment.
	 *
	 * @return array<int,int>
	 */
	public function virtual_ids(): array {
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'meta_key'               => self::META_VIRTUAL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- background sync, never on the front end.
				'meta_value'             => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- same lookup.
				'fields'                 => 'ids',
				'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- background sync over every virtual attachment.
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * A plain-text field of the photo.
	 *
	 * @param array<string,mixed> $photo Catalogue row.
	 * @param string              $key   Field name.
	 */
	private function text( array $photo, string $key ): string {
		return isset( $photo[ $key ] ) && is_scalar( $photo[ $key ] ) ? sanitize_text_field( (string) $photo[ $key ] ) : '';
	}
}
