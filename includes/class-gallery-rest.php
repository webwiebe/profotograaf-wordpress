<?php
/**
 * REST routes for the gallery block's picker.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Four routes under /wp-json/profotograaf/v1, for signed in editors:
 *
 * - GET  /galleries: the photographer's galleries (Api_Client::list_galleries()).
 * - POST /galleries/{id}/embeddable: marks a gallery embeddable
 *   (Api_Client::mark_embeddable()).
 * - GET  /galleries/{id}/photos: the photos of one gallery, for the block's
 *   exclude grid (Photo_List::fetch()).
 *
 * - GET  /galleries/{id}/showable: how many photos the site embed shows for
 *   one gallery, read from the public embed payload. The gallery list's
 *   photo_count can include photos the embed drops (PNG web variants, videos).
 *
 * All need the edit_posts capability. The REST cookie nonce protects the POST.
 */
class Gallery_Rest {

	public const NAMESPACE = 'profotograaf/v1';

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Gallery details.
	 *
	 * @var Gallery_Index
	 */
	private Gallery_Index $index;

	/**
	 * Constructor.
	 *
	 * @param Api_Client    $api   API client.
	 * @param Gallery_Index $index Gallery details.
	 */
	public function __construct( Api_Client $api, Gallery_Index $index ) {
		$this->api   = $api;
		$this->index = $index;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$this->register_showable();
		register_rest_route(
			self::NAMESPACE,
			'/galleries',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_galleries' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/galleries/(?P<id>[A-Za-z0-9_-]{1,64})/embeddable',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mark_embeddable' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/galleries/(?P<id>[A-Za-z0-9_-]{1,64})/photos',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_photos' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Registers GET /galleries/{id}/showable. Called from register_routes().
	 */
	private function register_showable(): void {
		register_rest_route(
			self::NAMESPACE,
			'/galleries/(?P<id>[A-Za-z0-9_-]{1,64})/showable',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'showable' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 */
	public function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET /galleries.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error|\WP_REST_Response
	 */
	public function list_galleries() {
		$rows = $this->api->list_galleries();
		if ( is_wp_error( $rows ) ) {
			return Rest_Errors::from( $rows );
		}
		$this->index->remember( $rows );
		return array_map(
			static function ( array $row ): array {
				$row['cover_url'] = esc_url_raw( $row['cover_url'] );
				return $row;
			},
			$rows
		);
	}

	/**
	 * POST /galleries/{id}/embeddable.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>|\WP_Error|\WP_REST_Response
	 */
	public function mark_embeddable( $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( ! Gallery_Renderer::valid_id( $id ) ) {
			return new \WP_Error( 'profotograaf_invalid', __( 'A gallery id is required.', 'profotograaf' ), array( 'status' => 400 ) );
		}
		$result = $this->api->mark_embeddable( $id );
		if ( is_wp_error( $result ) ) {
			return Rest_Errors::from( $result );
		}
		return $result;
	}

	/**
	 * GET /galleries/{id}/photos.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<int,array<string,mixed>>|\WP_Error|\WP_REST_Response
	 */
	public function list_photos( $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( ! Gallery_Renderer::valid_id( $id ) ) {
			return new \WP_Error( 'profotograaf_invalid', __( 'A gallery id is required.', 'profotograaf' ), array( 'status' => 400 ) );
		}
		$rows = ( new Photo_List( $this->api ) )->fetch( $id );
		if ( is_wp_error( $rows ) ) {
			return Rest_Errors::from( $rows );
		}
		return array_map(
			static function ( array $row ): array {
				$row['thumb_url'] = esc_url_raw( $row['thumb_url'] );
				return $row;
			},
			$rows
		);
	}

	/**
	 * GET /galleries/{id}/showable.
	 *
	 * Reads the public embed payload, the one embed.js draws from, and counts
	 * its photos. The request carries no token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{showable:int}|\WP_Error|\WP_REST_Response
	 */
	public function showable( $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( ! Gallery_Renderer::valid_id( $id ) ) {
			return new \WP_Error( 'profotograaf_invalid', __( 'A gallery id is required.', 'profotograaf' ), array( 'status' => 400 ) );
		}
		$result = $this->api->public_request( 'GET', '/api/v1/embed/galleries/' . rawurlencode( $id ) );
		if ( is_wp_error( $result ) ) {
			return Rest_Errors::from( $result );
		}
		if ( $result['status'] >= 400 ) {
			return Rest_Errors::from( Api_Errors::http( $result ) );
		}
		$photos = $result['body']['photos'] ?? null;
		return array( 'showable' => is_array( $photos ) ? count( $photos ) : 0 );
	}
}
