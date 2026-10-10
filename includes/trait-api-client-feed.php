<?php
/**
 * The change feed calls of the API client.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the change feed and the per-gallery photo list, kept apart from
 * Api_Client to keep that file short. Both need the galleries:read scope and
 * use the request pipeline of Api_Client.
 */
trait Api_Client_Feed {

	/**
	 * Reads one page of the change feed.
	 *
	 * Needs the galleries:read scope. The cursor is opaque. An empty `$since`
	 * asks for a fresh cursor and the platform answers with `resync` true.
	 * Delivery is at least once, so a caller applies an entry twice without harm.
	 * Entries carry ids only.
	 *
	 * @param string $since Cursor from the previous page, empty for the first call.
	 * @param int    $limit Entries per list, 1 to 1000.
	 * @return array{cursor:string,has_more:bool,resync:bool,galleries:array{created:array<int,string>,updated:array<int,string>,deleted:array<int,string>},photos:array{created:array<int,array{id:string,gallery_id:string}>,updated:array<int,array{id:string,gallery_id:string}>,deleted:array<int,array{id:string,gallery_id:string}>}}|WP_Error
	 */
	public function changes( string $since = '', int $limit = 500 ) {
		$query = array( 'limit' => max( 1, min( 1000, $limit ) ) );
		if ( '' !== $since ) {
			$query['since'] = $since;
		}
		$body = $this->request( 'GET', '/api/v1/embed/changes?' . http_build_query( $query ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( ! is_array( $body ) || ! isset( $body['cursor'] ) || ! is_scalar( $body['cursor'] ) || '' === (string) $body['cursor'] ) {
			return Api_Errors::make( 'profotograaf_bad_response', __( 'Profotograaf sent an answer this plugin cannot read.', 'profotograaf' ), 0, true );
		}
		$galleries = is_array( $body['galleries'] ?? null ) ? $body['galleries'] : array();
		$photos    = is_array( $body['photos'] ?? null ) ? $body['photos'] : array();
		return array(
			'cursor'    => (string) $body['cursor'],
			'has_more'  => ! empty( $body['has_more'] ),
			'resync'    => ! empty( $body['resync'] ),
			'galleries' => array(
				'created' => self::feed_ids( $galleries['created'] ?? null ),
				'updated' => self::feed_ids( $galleries['updated'] ?? null ),
				'deleted' => self::feed_ids( $galleries['deleted'] ?? null ),
			),
			'photos'    => array(
				'created' => self::feed_photos( $photos['created'] ?? null ),
				'updated' => self::feed_photos( $photos['updated'] ?? null ),
				'deleted' => self::feed_photos( $photos['deleted'] ?? null ),
			),
		);
	}

	/**
	 * The photo ids one gallery lists right now.
	 *
	 * Needs the galleries:read scope. A gallery the platform will not serve
	 * (unknown, foreign, not embeddable, password, expired) answers 404, which
	 * comes back as false: a definite "not listed". Any other failure comes
	 * back as the error, so the caller decides nothing from it.
	 *
	 * @param string $gallery_id Gallery id.
	 * @return array<int,string>|false|WP_Error
	 */
	public function gallery_photo_ids( string $gallery_id ) {
		$body = $this->request( 'GET', '/api/v1/embed/galleries/' . rawurlencode( $gallery_id ) . '/photos' );
		if ( is_wp_error( $body ) ) {
			$data = $body->get_error_data();
			return is_array( $data ) && 404 === (int) ( $data['status'] ?? 0 ) ? false : $body;
		}
		$entries = is_array( $body ) && isset( $body['photos'] ) && is_array( $body['photos'] ) ? $body['photos'] : ( is_array( $body ) ? $body : array() );
		$ids     = array();
		foreach ( $entries as $photo ) {
			if ( is_array( $photo ) && ! empty( $photo['id'] ) ) {
				$ids[] = (string) $photo['id'];
			}
		}
		return $ids;
	}

	/**
	 * Ids from a feed list of strings.
	 *
	 * @param mixed $raw Raw list.
	 * @return array<int,string>
	 */
	private static function feed_ids( $raw ): array {
		$ids = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id ) {
			if ( is_scalar( $id ) && '' !== (string) $id ) {
				$ids[] = (string) $id;
			}
		}
		return $ids;
	}

	/**
	 * Photo entries `{ id, gallery_id }` from a feed list.
	 *
	 * @param mixed $raw Raw list.
	 * @return array<int,array{id:string,gallery_id:string}>
	 */
	private static function feed_photos( $raw ): array {
		$entries = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['id'] ) && is_scalar( $entry['id'] ) ) {
				$entries[] = array(
					'id'         => (string) $entry['id'],
					'gallery_id' => is_scalar( $entry['gallery_id'] ?? null ) ? (string) $entry['gallery_id'] : '',
				);
			}
		}
		return $entries;
	}
}
