<?php
/**
 * REST routes for the gallery block's picker.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Three routes under /wp-json/profotograaf/v1, for signed in editors:
 *
 * - GET  /galleries: the photographer's galleries (Api_Client::list_galleries()).
 * - POST /galleries/{id}/embeddable: marks a gallery embeddable
 *   (Api_Client::mark_embeddable()).
 * - GET  /galleries/{id}/photos: the photos of one gallery, for the block's
 *   exclude grid (Photo_List::fetch()).
 *
 * All need the edit_posts capability. The REST cookie nonce protects the POST.
 */
class Gallery_Rest {

	public const NAMESPACE = 'profotograaf/v1';

	/**
	 * Seconds to wait when the platform rate limits without saying for how long.
	 */
	private const DEFAULT_RETRY_AFTER = 60;

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
			return $this->as_rest_error( $rows );
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
			return $this->as_rest_error( $result );
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
			return $this->as_rest_error( $rows );
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
	 * Gives an API error an HTTP status for the REST response.
	 *
	 * A platform rate limit stays a 429 and carries a Retry-After header, which
	 * a WP_Error cannot, so that case is a WP_REST_Response with the same body
	 * shape the REST server builds for an error. Timeouts and unreachable
	 * platforms are 504. Other platform failures are 502.
	 *
	 * @param \WP_Error $error Error from the API client.
	 * @return \WP_Error|\WP_REST_Response
	 */
	private function as_rest_error( \WP_Error $error ) {
		$statuses = array(
			'profotograaf_not_connected' => 409,
			'profotograaf_reconnect'     => 403,
			'profotograaf_invalid'       => 400,
			'profotograaf_network'       => 504,
		);
		$code     = (string) $error->get_error_code();
		$source   = $error->get_error_data();
		$source   = is_array( $source ) ? $source : array();
		$upstream = (int) ( $source['status'] ?? 0 );
		$status   = $statuses[ $code ] ?? 502;
		if ( 'profotograaf_http' === $code ) {
			if ( 429 === $upstream ) {
				$status = 429;
			} elseif ( 408 === $upstream || 504 === $upstream ) {
				$status = 504;
			}
		}

		$data = array(
			'status'    => $status,
			'retryable' => (bool) ( $source['retryable'] ?? false ),
		);
		if ( 429 === $status ) {
			$retry_after         = (int) ( $source['retry_after'] ?? 0 );
			$data['retry_after'] = $retry_after > 0 ? $retry_after : self::DEFAULT_RETRY_AFTER;
			return new \WP_REST_Response(
				array(
					'code'    => $code,
					'message' => $error->get_error_message(),
					'data'    => $data,
				),
				429,
				array( 'Retry-After' => (string) $data['retry_after'] )
			);
		}
		return new \WP_Error( $code, $error->get_error_message(), $data );
	}
}
