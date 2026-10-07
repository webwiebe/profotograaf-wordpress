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
 * scope (wiebe-xyz/professionals#1868). The route answers
 * `{"photos": [...], "total", "limit", "offset"}`, newest first, at most 200
 * photos per request. Photos carry `id`, `width`, `height`, `alt`, `title`,
 * `caption`, `images` (variants `thumb` and `web`) and `thumbnail_url`.
 *
 * The list follows `offset` until `total` is reached, up to
 * Gallery_Renderer::MAX_EXCLUDED photos, the most one block can leave out.
 * A body that is a bare list is read as the whole gallery.
 */
class Photo_List {

	/** Most photos the platform returns per request. */
	public const PAGE_SIZE = 200;

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
		$path = '/api/v1/embed/galleries/' . rawurlencode( $gallery_id ) . '/photos';
		$rows = array();
		$seen = 0;
		do {
			$limit = min( self::PAGE_SIZE, Gallery_Renderer::MAX_EXCLUDED - $seen );
			$body  = $this->api->request( 'GET', $path . '?limit=' . $limit . '&offset=' . $seen );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$paged = is_array( $body ) && isset( $body['photos'] );
			$list  = $paged ? $body['photos'] : $body;
			$list  = is_array( $list ) ? array_values( $list ) : array();
			$seen += count( $list );
			foreach ( $list as $photo ) {
				$row = self::row( $photo );
				if ( null !== $row ) {
					$rows[] = $row;
				}
			}
			$total = $paged && isset( $body['total'] ) ? (int) $body['total'] : 0;
		} while ( array() !== $list && $seen < $total && $seen < Gallery_Renderer::MAX_EXCLUDED );
		return $rows;
	}

	/**
	 * One row of the list, or null for a photo without an id.
	 *
	 * @param mixed $photo Photo from the platform.
	 * @return array{id:string,title:string,alt:string,caption:string,width:int,height:int,thumb_url:string}|null
	 */
	private static function row( $photo ): ?array {
		if ( ! is_array( $photo ) || empty( $photo['id'] ) ) {
			return null;
		}
		return array(
			'id'        => (string) $photo['id'],
			'title'     => (string) ( $photo['title'] ?? '' ),
			'alt'       => (string) ( $photo['alt'] ?? '' ),
			'caption'   => (string) ( $photo['caption'] ?? '' ),
			'width'     => (int) ( $photo['width'] ?? 0 ),
			'height'    => (int) ( $photo['height'] ?? 0 ),
			'thumb_url' => self::thumb_url( $photo ),
		);
	}

	/**
	 * The thumbnail URL of a photo: the `thumb` image, then `thumbnail_url`,
	 * then the first image.
	 *
	 * @param array<mixed> $photo Photo.
	 */
	private static function thumb_url( array $photo ): string {
		$fallback = '';
		foreach ( is_array( $photo['images'] ?? null ) ? $photo['images'] : array() as $image ) {
			if ( ! is_array( $image ) || empty( $image['url'] ) ) {
				continue;
			}
			if ( ( $image['variant'] ?? '' ) === 'thumb' ) {
				return (string) $image['url'];
			}
			$fallback = '' === $fallback ? (string) $image['url'] : $fallback;
		}
		$thumbnail = $photo['thumbnail_url'] ?? '';
		return is_string( $thumbnail ) && '' !== $thumbnail ? $thumbnail : $fallback;
	}
}
