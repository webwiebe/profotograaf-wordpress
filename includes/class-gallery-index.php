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
 * The picker fills it whenever it lists the galleries. A page view only reads
 * the option. When a gallery is not in it yet, the view schedules one
 * background lookup (a signed in call to the platform, made by cron) and
 * renders without the link. A later view finds the result. A lookup is
 * scheduled at most once per five minutes.
 */
class Gallery_Index {

	public const OPTION = 'profotograaf_gallery_index';

	public const LOOKUP_GUARD = 'profotograaf_gallery_lookup';

	public const LOOKUP_HOOK = 'profotograaf_lookup_galleries';

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
			// The platform layout and, when the platform reports it, the layout the
			// embed draws. Always stored, so an entry without the key is one from
			// before the layout was kept and asks for a fresh list.
			$index[ (string) $row['id'] ]['layout']       = Layout_Map::clean( $row['layout'] ?? '' );
			$index[ (string) $row['id'] ]['embed_layout'] = Layout_Map::clean( $row['embed_layout'] ?? '' );
			if ( isset( $row['photo_count'] ) ) {
				$index[ (string) $row['id'] ]['count'] = max( 0, (int) $row['photo_count'] );
			}
		}
		update_option( self::OPTION, $index, false );
	}

	/**
	 * Details of one gallery: title and url, or null when unknown.
	 *
	 * Reads local data only. A miss schedules the background lookup.
	 *
	 * @param string $id Gallery id.
	 * @return array{title:string,url:string}|null
	 */
	public function find( string $id ): ?array {
		$found = $this->stored( $id );
		if ( null === $found ) {
			$this->schedule_lookup();
		}
		return $found;
	}

	/**
	 * Photo count of one gallery as last listed, or null when unknown. Reads the
	 * option only and never schedules a lookup.
	 *
	 * @param string $id Gallery id.
	 */
	public function count( string $id ): ?int {
		$index = get_option( self::OPTION, array() );
		if ( ! is_array( $index ) || ! isset( $index[ $id ] ) || ! is_array( $index[ $id ] ) ) {
			return null;
		}
		$count = (int) ( $index[ $id ]['count'] ?? 0 );
		return $count > 0 ? $count : null;
	}

	/**
	 * The layout embed.js draws for a gallery on the platform default: the layout
	 * the platform reports, else the table in Layout_Map, else the grid. Reads the
	 * option only. A gallery that is not in the list yet, or was stored before the
	 * layout was kept, schedules the background lookup and gets the grid for now.
	 *
	 * @param string $id Gallery id.
	 */
	public function drawn_layout( string $id ): string {
		$index = get_option( self::OPTION, array() );
		$entry = is_array( $index ) && isset( $index[ $id ] ) && is_array( $index[ $id ] ) ? $index[ $id ] : null;
		if ( null === $entry || ! array_key_exists( 'layout', $entry ) ) {
			$this->schedule_lookup();
			return Layout_Map::fallback();
		}
		return Layout_Map::drawn( (string) $entry['layout'], (string) ( $entry['embed_layout'] ?? '' ) );
	}

	/**
	 * Whether the last gallery list said this gallery has no photos. False when
	 * the count is unknown or above zero. Reads the option only. A gallery with
	 * photos the embed cannot show still counts them, so this misses that case;
	 * the front end script catches it once embed.js has answered.
	 *
	 * @param string $id Gallery id.
	 */
	public function is_empty( string $id ): bool {
		$index = get_option( self::OPTION, array() );
		return is_array( $index )
			&& isset( $index[ $id ] )
			&& is_array( $index[ $id ] )
			&& isset( $index[ $id ]['count'] )
			&& 0 === (int) $index[ $id ]['count'];
	}

	/**
	 * Fetches the gallery list and stores it. Runs from cron, never on a page view.
	 */
	public function lookup(): void {
		$rows = $this->api->list_galleries();
		if ( ! is_wp_error( $rows ) ) {
			$this->remember( $rows );
		}
	}

	/**
	 * Queues one lookup unless one is pending or ran in the last five minutes.
	 */
	private function schedule_lookup(): void {
		if ( false !== get_transient( self::LOOKUP_GUARD ) || wp_next_scheduled( self::LOOKUP_HOOK ) ) {
			return;
		}
		set_transient( self::LOOKUP_GUARD, 1, 5 * MINUTE_IN_SECONDS );
		wp_schedule_single_event( time(), self::LOOKUP_HOOK );
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
