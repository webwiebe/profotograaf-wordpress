<?php
/**
 * The public payload of one gallery, cached for the full-page gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads GET /api/v1/embed/galleries/{id}, the route embed.js reads, and keeps
 * the decoded body in a transient for five minutes, the route's s-maxage. The
 * entry stores the payload `version` next to the body, so a later step can ask
 * the platform whether that version is still current.
 *
 * The route is public: the request carries no token. Errors are not cached, so
 * the next page view asks again.
 */
final class Full_Page_Payload {

	/** Seconds a cached payload stays fresh. */
	public const TTL = 300;

	/** Prefix of the transient name; the gallery id follows. */
	public const PREFIX = 'profotograaf_fp_payload_';

	/**
	 * Platform client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $api Platform client.
	 */
	public function __construct( Api_Client $api ) {
		$this->api = $api;
	}

	/**
	 * The payload of one gallery: from the cache while it is fresh, else from
	 * the platform.
	 *
	 * @param string $id Gallery id, already checked with Gallery_Renderer::valid_id().
	 * @return array<string,mixed>|WP_Error The decoded body.
	 */
	public function get( string $id ) {
		$cached = get_transient( self::PREFIX . $id );
		if ( is_array( $cached ) && is_array( $cached['body'] ?? null ) ) {
			return $cached['body'];
		}
		$result = $this->api->public_request( 'GET', '/api/v1/embed/galleries/' . rawurlencode( $id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $result['status'] >= 400 ) {
			return Api_Errors::http( $result );
		}
		$body = $result['body'];
		set_transient(
			self::PREFIX . $id,
			array(
				'version' => is_scalar( $body['version'] ?? null ) ? (string) $body['version'] : '',
				'body'    => $body,
			),
			self::TTL
		);
		return $body;
	}
}
