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
 * This is a prototype behind a hidden flag. It has no UI and ships off:
 *
 * - the constant `PROFOTOGRAAF_VIRTUAL_ATTACHMENTS`, or
 * - the filter `profotograaf_virtual_attachments` returning true.
 *
 * With the flag off nothing here registers a hook or touches an attachment.
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
 * sizes). Writes happen in create() and sync(), both off the front end.
 */
final class Virtual_Attachments {

	use Virtual_Attachment_Filters;

	public const CONSTANT = 'PROFOTOGRAAF_VIRTUAL_ATTACHMENTS';

	public const FILTER = 'profotograaf_virtual_attachments';

	/** Marks an attachment as virtual. */
	public const META_VIRTUAL = '_profotograaf_virtual';

	/** The `thumb` and `web` URLs of the photo. */
	public const META_URLS = '_profotograaf_virtual_urls';

	/** Unix time at which sync() first found the photo missing from a fresh catalogue. */
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
	 * Catalogue that sync() reads on a schedule.
	 *
	 * @var Photo_Catalogue|null
	 */
	private ?Photo_Catalogue $catalogue;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings  Plugin settings.
	 * @param Photo_Importer       $importer  Importer, built with a catalogue so that reimport() works.
	 * @param Photo_Catalogue|null $catalogue Catalogue for the scheduled sync.
	 */
	public function __construct( Settings $settings, Photo_Importer $importer, ?Photo_Catalogue $catalogue = null ) {
		$this->settings  = $settings;
		$this->importer  = $importer;
		$this->catalogue = $catalogue;
	}

	/**
	 * Whether the hidden flag is on.
	 */
	public static function enabled(): bool {
		$value = defined( self::CONSTANT ) && true === constant( self::CONSTANT );

		/**
		 * Switches the virtual attachment prototype on. Hidden, off by default.
		 *
		 * @param bool $enabled Whether the prototype is on.
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
		add_filter( 'render_block_data', array( $this, 'filter_block_data' ), 20, 1 );
		add_action( self::SYNC_HOOK, array( $this, 'run_sync' ) );
		add_action( 'init', array( $this, 'schedule_sync' ) );
	}

	/**
	 * Whether an attachment is a virtual one. Reads post meta only.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function is_virtual( int $attachment_id ): bool {
		return $attachment_id > 0 && self::enabled() && '1' === (string) get_post_meta( $attachment_id, self::META_VIRTUAL, true );
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
			return Api_Errors::make( 'profotograaf_virtual_off', __( 'Virtual attachments are switched off.', 'profotograaf' ), 403, false );
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
	 * Turns a virtual attachment into a normal import, in place. The attachment
	 * id stays, so posts that use it keep working. The file is downloaded by
	 * Photo_Importer::reimport(), which needs the photo in the stored catalogue.
	 *
	 * On failure the attachment stays virtual and unchanged.
	 *
	 * @param int $attachment_id Virtual attachment.
	 * @return int|WP_Error The attachment id.
	 */
	public function convert_to_local( int $attachment_id ) {
		if ( ! $this->is_virtual( $attachment_id ) ) {
			return Api_Errors::make( 'profotograaf_not_virtual', __( 'This file is not a virtual Profotograaf attachment.', 'profotograaf' ), 400, false );
		}
		$result = $this->importer->reimport( $attachment_id );
		if ( is_wp_error( $result ) ) {
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
	 * is missing from a fresh catalogue gets META_GONE once. A stale catalogue
	 * (the platform failed) changes nothing, so an outage never marks photos gone.
	 *
	 * @param array{photos:array<int,array<string,mixed>>,stale:bool} $catalogue Result of Photo_Catalogue::get().
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
		$by_id = array();
		foreach ( $catalogue['photos'] as $row ) {
			if ( isset( $row['id'] ) && is_scalar( $row['id'] ) ) {
				$by_id[ (string) $row['id'] ] = $row;
			}
		}
		foreach ( $this->virtual_ids() as $attachment_id ) {
			$photo_id = (string) get_post_meta( $attachment_id, Photo_Importer::META_PHOTO_ID, true );
			if ( isset( $by_id[ $photo_id ] ) ) {
				$photo = $by_id[ $photo_id ];
				$web   = $this->text( $photo, 'web_url' );
				$thumb = $this->text( $photo, 'thumb_url' );
				if ( ! $this->importer->is_platform_url( $web ) ) {
					continue;
				}
				$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
				$this->store( $attachment_id, $photo, $web, $this->importer->is_platform_url( $thumb ) ? $thumb : $web, $file );
				update_post_meta( $attachment_id, Photo_Importer::META_VERSION, $this->text( $photo, 'version' ) );
				delete_post_meta( $attachment_id, self::META_GONE );
				++$result['updated'];
				continue;
			}
			if ( '' === (string) get_post_meta( $attachment_id, self::META_GONE, true ) ) {
				update_post_meta( $attachment_id, self::META_GONE, time() );

				/**
				 * Fires once when a virtual attachment's photo left the catalogue, which
				 * means the platform no longer serves it.
				 *
				 * @param int $attachment_id Attachment id.
				 */
				do_action( 'profotograaf_virtual_attachment_gone', $attachment_id );
				++$result['gone'];
			}
		}//end foreach
		return $result;
	}

	/**
	 * Cron callback: syncs from the catalogue. Never runs on a front end request.
	 */
	public function run_sync(): void {
		if ( null === $this->catalogue ) {
			return;
		}
		$catalogue = $this->catalogue->refresh();
		if ( ! is_wp_error( $catalogue ) ) {
			$this->sync( $catalogue );
		}
	}

	/**
	 * Schedules the sync twice a day while the flag is on.
	 */
	public function schedule_sync(): void {
		if ( ! wp_next_scheduled( self::SYNC_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::SYNC_HOOK );
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
	private function virtual_ids(): array {
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
