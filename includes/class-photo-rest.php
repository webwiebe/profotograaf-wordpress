<?php
/**
 * REST route for browsing Profotograaf photos.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * GET /profotograaf/v1/photos?search=&gallery=&page=&per_page=
 *
 * Serves one page of the photo catalogue in the shape the block inserter media
 * category expects, plus the galleries to filter on. Needs the upload_files
 * capability and the media source setting. Never asks the platform for more
 * than the catalogue does itself.
 *
 * POST /profotograaf/v1/photos/import { ids } imports up to MAX_IMPORT photos
 * from the catalogue into the Media Library. Only ids are read from the
 * request. Titles, URLs and everything else come from the catalogue.
 */
class Photo_Rest {

	/** Largest page the route serves. */
	public const MAX_PER_PAGE = 100;

	/** Most photos one import request takes. */
	public const MAX_IMPORT = 50;

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
	 * Constructor.
	 *
	 * @param Photo_Catalogue $catalogue Photo catalogue.
	 * @param Photo_Importer  $importer  Photo importer.
	 * @param Settings        $settings  Settings.
	 */
	public function __construct( Photo_Catalogue $catalogue, Photo_Importer $importer, Settings $settings ) {
		$this->catalogue = $catalogue;
		$this->importer  = $importer;
		$this->settings  = $settings;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			Gallery_Rest::NAMESPACE,
			'/photos',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_photos' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'args'                => array(
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'gallery'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => Photo_Catalogue::PER_PAGE,
						'minimum' => 1,
						'maximum' => self::MAX_PER_PAGE,
					),
				),
			)
		);
		register_rest_route(
			Gallery_Rest::NAMESPACE,
			'/photos/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_photos' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'args'                => array(
					'ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
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
	 * GET /photos.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>|\WP_Error|\WP_REST_Response
	 */
	public function list_photos( $request ) {
		if ( ! $this->settings->media_source_enabled() ) {
			return self::source_off();
		}

		$catalogue = $this->catalogue->get();
		if ( is_wp_error( $catalogue ) ) {
			return Rest_Errors::from( $catalogue );
		}

		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $request->get_param( 'per_page' ) ?? Photo_Catalogue::PER_PAGE ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$search   = $request->get_param( 'search' );
		$gallery  = $request->get_param( 'gallery' );
		$gallery  = is_string( $gallery ) ? $gallery : '';
		$photos   = Photo_Catalogue::search( $catalogue['photos'], is_string( $search ) ? $search : '' );
		$facets   = self::galleries( $photos );
		if ( '' !== $gallery ) {
			$photos = array_values( array_filter( $photos, static fn( array $photo ): bool => (string) $photo['gallery_id'] === $gallery ) );
		}
		$slice = Photo_Catalogue::page( $photos, $page, $per_page );

		$ids      = array_map( static fn( array $photo ): string => (string) $photo['id'], $slice['items'] );
		$imported = $this->importer->find_many( $ids );

		return array(
			'items'      => array_map(
				static fn( array $photo ): array => self::item( $photo, $imported[ (string) $photo['id'] ] ?? 0 ),
				$slice['items']
			),
			'totalItems' => $slice['total'],
			'totalPages' => $slice['total_pages'],
			'galleries'  => $facets,
			'stale'      => $catalogue['stale'],
		);
	}

	/**
	 * POST /photos/import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>|\WP_Error|\WP_REST_Response
	 */
	public function import_photos( $request ) {
		if ( ! $this->settings->media_source_enabled() ) {
			return self::source_off();
		}

		$raw = $request->get_param( 'ids' );
		$ids = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id ) {
			if ( is_scalar( $id ) && '' !== trim( (string) $id ) ) {
				$ids[ trim( (string) $id ) ] = true;
			}
		}
		$ids = array_map( 'strval', array_keys( $ids ) );
		if ( array() === $ids ) {
			return Api_Errors::make( 'profotograaf_import_no_ids', __( 'Choose at least one photo to import.', 'profotograaf' ), 400, false );
		}
		if ( count( $ids ) > self::MAX_IMPORT ) {
			return Api_Errors::make(
				'profotograaf_import_too_many',
				sprintf(
					/* translators: %d: largest number of photos one import takes. */
					__( 'Import at most %d photos at a time.', 'profotograaf' ),
					self::MAX_IMPORT
				),
				400,
				false
			);
		}

		$catalogue = $this->catalogue->get();
		if ( is_wp_error( $catalogue ) ) {
			return Rest_Errors::from( $catalogue );
		}
		$by_id = array();
		foreach ( $catalogue['photos'] as $photo ) {
			$by_id[ (string) $photo['id'] ] = $photo;
		}

		$results = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				$results[] = array(
					'id'    => $id,
					'error' => __( 'This photo is not in your Profotograaf library any more. Refresh the list and try again.', 'profotograaf' ),
				);
				continue;
			}
			$attachment = $this->importer->import( $by_id[ $id ] );
			$results[]  = is_wp_error( $attachment )
				? array(
					'id'    => $id,
					'error' => $attachment->get_error_message(),
				)
				: array(
					'id'            => $id,
					'attachment_id' => $attachment,
				);
		}
		return array( 'results' => $results );
	}

	/**
	 * The galleries of some photos, in order of first appearance.
	 *
	 * @param array<int,array<string,mixed>> $photos Catalogue rows.
	 * @return array<int,array{id:string,title:string,count:int}>
	 */
	private static function galleries( array $photos ): array {
		$galleries = array();
		foreach ( $photos as $photo ) {
			$id = (string) $photo['gallery_id'];
			if ( ! isset( $galleries[ $id ] ) ) {
				$galleries[ $id ] = array(
					'id'    => $id,
					'title' => (string) $photo['gallery_title'],
					'count' => 0,
				);
			}
			++$galleries[ $id ]['count'];
		}
		return array_values( $galleries );
	}

	/**
	 * The refusal while the media source setting is off.
	 */
	private static function source_off(): \WP_Error {
		return new \WP_Error(
			'profotograaf_media_source_off',
			__( 'Using Profotograaf photos in the editor is switched off in the plugin settings.', 'profotograaf' ),
			array(
				'status'    => 403,
				'retryable' => false,
			)
		);
	}

	/**
	 * One catalogue row in the inserter's shape.
	 *
	 * @param array<string,mixed> $photo         Catalogue row.
	 * @param int                 $attachment_id Attachment id of an earlier import, 0 when none.
	 * @return array<string,mixed>
	 */
	private static function item( array $photo, int $attachment_id ): array {
		$alt  = (string) $photo['alt'];
		$item = array(
			'url'          => esc_url_raw( (string) $photo['web_url'] ),
			'previewUrl'   => esc_url_raw( (string) $photo['thumb_url'] ),
			'alt'          => '' !== $alt ? $alt : (string) $photo['title'],
			'caption'      => (string) $photo['caption'],
			'title'        => (string) $photo['title'],
			'sourceId'     => (string) $photo['id'],
			'type'         => 'image',
			'galleryId'    => (string) $photo['gallery_id'],
			'galleryTitle' => (string) $photo['gallery_title'],
		);
		if ( $attachment_id > 0 ) {
			$item['id'] = $attachment_id;
		}
		return $item;
	}
}
