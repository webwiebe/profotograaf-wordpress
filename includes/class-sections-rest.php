<?php
/**
 * REST route for the full-page gallery's editor preview.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/profotograaf/v1/galleries/{id}/sections, for signed in editors.
 *
 * Runs Section_Sequencer over the public embed payload of one gallery, the
 * same photos the full-page gallery draws, so the editor previews the sections
 * the front end renders. The sequencer exists once, in PHP (#194).
 *
 * Query parameters, each optional and read like the block attributes:
 * - `preset`: a preset id or `custom`; anything else is the default preset.
 * - `sections`: the custom sections, as a JSON list.
 * - `picks`: the preset picks, as a JSON list of `{ slot, photoId }`.
 * - `exclude`: photo ids separated by commas.
 *
 * The answer is `{ preset, sections }`. Each section has its fields, `slots`
 * and `photos`, every photo cut down to what the preview draws: `id`, `thumb`
 * (the URL of the smallest image), `width`, `height` and `alt`.
 */
class Sections_Rest {

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $api API client.
	 */
	public function __construct( Api_Client $api ) {
		$this->api = $api;
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
			'/galleries/(?P<id>[A-Za-z0-9_-]{1,64})/sections',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'sections' ),
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
	 * GET /galleries/{id}/sections.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{preset:string,sections:array<int,array<string,mixed>>}|\WP_Error|\WP_REST_Response
	 */
	public function sections( $request ) {
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
		$photos   = is_array( $result['body']['photos'] ?? null ) ? array_values( array_filter( $result['body']['photos'], 'is_array' ) ) : array();
		$preset   = Sections_Schema::preset_id( $request->get_param( 'preset' ) );
		$sections = Section_Sequencer::sequence(
			array_map( array( self::class, 'preview_photo' ), $photos ),
			$preset,
			self::json_list( $request->get_param( 'sections' ) ),
			self::json_list( $request->get_param( 'picks' ) ),
			self::id_list( $request->get_param( 'exclude' ) )
		);
		return compact( 'preset', 'sections' );
	}

	/**
	 * One payload photo cut down to what the preview draws.
	 *
	 * @param array<string,mixed> $photo Payload photo.
	 * @return array{id:string,thumb:string,width:int,height:int,alt:string}
	 */
	private static function preview_photo( array $photo ): array {
		$thumb = '';
		$width = PHP_INT_MAX;
		foreach ( is_array( $photo['images'] ?? null ) ? $photo['images'] : array() as $image ) {
			$url = is_array( $image ) && is_string( $image['url'] ?? null ) ? $image['url'] : '';
			$w   = is_array( $image ) ? (int) ( $image['width'] ?? 0 ) : 0;
			if ( '' !== $url && ( '' === $thumb || ( $w > 0 && $w < $width ) ) ) {
				$thumb = $url;
				$width = $w > 0 ? $w : $width;
			}
		}
		return array(
			'id'     => is_scalar( $photo['id'] ?? null ) ? (string) $photo['id'] : '',
			'thumb'  => esc_url_raw( $thumb ),
			'width'  => (int) ( $photo['width'] ?? 0 ),
			'height' => (int) ( $photo['height'] ?? 0 ),
			'alt'    => is_string( $photo['alt'] ?? null ) ? sanitize_text_field( $photo['alt'] ) : '',
		);
	}

	/**
	 * A list from a query parameter that holds a list or a JSON list.
	 *
	 * @param mixed $value Parameter.
	 * @return array<int,mixed>
	 */
	private static function json_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * Photo ids from a query parameter that holds a list or a comma separated string.
	 *
	 * @param mixed $value Parameter.
	 * @return string[]
	 */
	private static function id_list( $value ): array {
		$ids = is_array( $value ) ? $value : explode( ',', is_string( $value ) ? $value : '' );
		return array_values( array_filter( array_map( static fn( $id ) => is_scalar( $id ) ? trim( (string) $id ) : '', $ids ) ) );
	}
}
