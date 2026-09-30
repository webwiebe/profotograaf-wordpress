<?php
/**
 * REST routes for the gallery block's picker.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Two routes under /wp-json/profotograaf/v1, for signed in editors:
 *
 * - GET  /galleries: the photographer's galleries (Api_Client::list_galleries()).
 * - POST /galleries/{id}/embeddable: marks a gallery embeddable
 *   (Api_Client::mark_embeddable()).
 *
 * Both need the edit_posts capability. The REST cookie nonce protects the POST.
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
	 * @return array<int,array<string,mixed>>|\WP_Error
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
	 * @return array<string,mixed>|\WP_Error
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
		return array(
			'id'         => $id,
			'embeddable' => true,
		);
	}

	/**
	 * Gives an API error an HTTP status for the REST response.
	 *
	 * @param \WP_Error $error Error from the API client.
	 */
	private function as_rest_error( \WP_Error $error ): \WP_Error {
		$statuses = array(
			'profotograaf_not_connected' => 409,
			'profotograaf_unsupported'   => 501,
			'profotograaf_invalid'       => 400,
		);
		$code     = (string) $error->get_error_code();
		return new \WP_Error(
			$code,
			$error->get_error_message(),
			array( 'status' => $statuses[ $code ] ?? 502 )
		);
	}
}
