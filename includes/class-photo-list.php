<?php
/**
 * Photo list of one gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the photos of one gallery for the editor's exclude grid.
 *
 * Calls `GET /api/v1/embed/galleries/{id}/photos` with the galleries:read
 * scope (wiebe-xyz/professionals#1868). The route is built against the shape
 * of the public gallery payload: photos with `id`, `width`, `height`, `alt`,
 * `title`, `caption` and `images` (variants `thumb` and `web`). The body may
 * be the list itself or an object with a `photos` list.
 */
class Photo_List {

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
	 * Lists the photos of one gallery.
	 *
	 * @param string $gallery_id Gallery id.
	 * @return array<int,array{id:string,title:string,alt:string,caption:string,width:int,height:int,thumb_url:string}>|WP_Error
	 */
	public function fetch( string $gallery_id ) {
		if ( '' === trim( $gallery_id ) ) {
			return Api_Errors::make( 'profotograaf_invalid', __( 'A gallery id is required.', 'profotograaf' ), 0, false );
		}
		$body = $this->api->request( 'GET', '/api/v1/embed/galleries/' . rawurlencode( $gallery_id ) . '/photos' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$list = is_array( $body ) && isset( $body['photos'] ) ? $body['photos'] : $body;
		$rows = array();
		foreach ( is_array( $list ) ? $list : array() as $photo ) {
			if ( ! is_array( $photo ) || empty( $photo['id'] ) ) {
				continue;
			}
			$rows[] = array(
				'id'        => (string) $photo['id'],
				'title'     => (string) ( $photo['title'] ?? '' ),
				'alt'       => (string) ( $photo['alt'] ?? '' ),
				'caption'   => (string) ( $photo['caption'] ?? '' ),
				'width'     => (int) ( $photo['width'] ?? 0 ),
				'height'    => (int) ( $photo['height'] ?? 0 ),
				'thumb_url' => self::variant_url( $photo, 'thumb' ),
			);
		}
		return $rows;
	}

	/**
	 * The URL of one image variant of a photo, or the first image when the
	 * wanted variant is missing.
	 *
	 * @param array<mixed> $photo   Photo.
	 * @param string       $variant Variant name.
	 */
	private static function variant_url( array $photo, string $variant ): string {
		$fallback = '';
		foreach ( is_array( $photo['images'] ?? null ) ? $photo['images'] : array() as $image ) {
			if ( ! is_array( $image ) || empty( $image['url'] ) ) {
				continue;
			}
			if ( ( $image['variant'] ?? '' ) === $variant ) {
				return (string) $image['url'];
			}
			$fallback = '' === $fallback ? (string) $image['url'] : $fallback;
		}
		return $fallback;
	}
}
