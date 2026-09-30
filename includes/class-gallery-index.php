<?php
/**
 * Remembered gallery details.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Title, link and photo count of the photographer's galleries, kept in an
 * option so a page view can write the no-JavaScript fallback link without a
 * request to the platform.
 *
 * The picker fills it whenever it lists the galleries. A gallery that is not
 * in it yet is looked up once (a signed in call to the platform), and a failed
 * or empty lookup is not repeated for five minutes.
 */
class Gallery_Index {

	public const OPTION = 'profotograaf_gallery_index';

	public const LOOKUP_GUARD = 'profotograaf_gallery_lookup';

	private const MAX_ENTRIES = 500;

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
	 * Stores the rows of a gallery list.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows from Api_Client::list_galleries().
	 */
	public function remember( array $rows ): void {
		$index = array();
		foreach ( array_slice( $rows, 0, self::MAX_ENTRIES ) as $row ) {
			if ( empty( $row['id'] ) ) {
				continue;
			}
			$index[ (string) $row['id'] ] = array(
				'title' => (string) ( $row['title'] ?? '' ),
				'url'   => (string) ( $row['url'] ?? '' ),
			);
		}
		update_option( self::OPTION, $index, false );
	}

	/**
	 * Details of one gallery: title and url, or null when unknown.
	 *
	 * @param string $id Gallery id.
	 * @return array{title:string,url:string}|null
	 */
	public function find( string $id ): ?array {
		$found = $this->stored( $id );
		if ( null !== $found ) {
			return $found;
		}
		if ( false !== get_transient( self::LOOKUP_GUARD ) ) {
			return null;
		}
		set_transient( self::LOOKUP_GUARD, 1, 5 * MINUTE_IN_SECONDS );

		$rows = $this->api->list_galleries();
		if ( is_wp_error( $rows ) ) {
			return null;
		}
		$this->remember( $rows );
		return $this->stored( $id );
	}

	/**
	 * Reads the option only.
	 *
	 * @param string $id Gallery id.
	 * @return array{title:string,url:string}|null
	 */
	private function stored( string $id ): ?array {
		$index = get_option( self::OPTION, array() );
		if ( ! is_array( $index ) || ! isset( $index[ $id ] ) || ! is_array( $index[ $id ] ) ) {
			return null;
		}
		return array(
			'title' => (string) ( $index[ $id ]['title'] ?? '' ),
			'url'   => (string) ( $index[ $id ]['url'] ?? '' ),
		);
	}
}
