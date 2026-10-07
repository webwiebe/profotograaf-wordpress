<?php
/**
 * Catalogue of the account's embeddable photos.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The photos an editor can pick from when the media source is on.
 *
 * Reads `GET /api/v1/embed/photos` (library-wide, only embeddable and
 * available galleries) 200 photos at a time and stores the normalised rows in
 * an option. The editor reads the stored copy. WP-Cron refreshes it. When the
 * platform fails, the stored copy is served with `stale` set to true.
 *
 * Never call this from a front-end request. The media source setting gates
 * everything: while it is off, nothing is read, requested or stored.
 */
class Photo_Catalogue {

	public const OPTION = 'profotograaf_photo_catalogue';

	/** Most photos the platform returns per request. */
	public const PAGE_SIZE = 200;

	/** Most photos the catalogue keeps. */
	public const MAX_PHOTOS = 2000;

	/** Default page size of page(). */
	public const PER_PAGE = 20;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Clock override for tests.
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Api_Client    $api      API client.
	 * @param Settings      $settings Settings.
	 * @param callable|null $clock    Returns the current unix time.
	 */
	public function __construct( Api_Client $api, Settings $settings, ?callable $clock = null ) {
		$this->api      = $api;
		$this->settings = $settings;
		$this->clock    = $clock;
	}

	/**
	 * The catalogue as last stored. Fetches it once when nothing is stored yet.
	 *
	 * @return array{photos:array<int,array<string,mixed>>,stale:bool,fetched_at:int,dropped:int}|WP_Error
	 */
	public function get() {
		if ( ! $this->settings->media_source_enabled() ) {
			return self::empty_result();
		}
		$stored = $this->stored();
		return null === $stored ? $this->refresh() : $stored;
	}

	/**
	 * Fetches the catalogue from the platform and stores it.
	 *
	 * On failure the stored copy comes back flagged stale. Without a stored
	 * copy the error comes back.
	 *
	 * @return array{photos:array<int,array<string,mixed>>,stale:bool,fetched_at:int,dropped:int}|WP_Error
	 */
	public function refresh() {
		if ( ! $this->settings->media_source_enabled() ) {
			return self::empty_result();
		}
		$fetched = $this->fetch_all();
		if ( is_wp_error( $fetched ) ) {
			$data = $fetched->get_error_data();
			Logger::warning(
				'The photo catalogue could not be refreshed.',
				array(
					'code'   => $fetched->get_error_code(),
					'status' => is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0,
				)
			);
			$stored = $this->stored();
			if ( null === $stored ) {
				return $fetched;
			}
			$stored['stale'] = true;
			update_option( self::OPTION, $stored, false );
			return $stored;
		}
		if ( $fetched['dropped'] > 0 ) {
			Logger::warning(
				'The photo catalogue hit its size limit and left photos out.',
				array(
					'kept'    => count( $fetched['photos'] ),
					'dropped' => $fetched['dropped'],
					'limit'   => self::MAX_PHOTOS,
				)
			);
		}
		$catalogue = array(
			'photos'     => $fetched['photos'],
			'stale'      => false,
			'fetched_at' => $this->now(),
			'dropped'    => $fetched['dropped'],
		);
		update_option( self::OPTION, $catalogue, false );
		return $catalogue;
	}

	/**
	 * Photos matching a search, case-insensitive over title, caption, alt and
	 * gallery title. Every word of the query must match somewhere.
	 *
	 * @param array<int,array<string,mixed>> $photos Normalised rows.
	 * @param string                         $query  Search text.
	 * @return array<int,array<string,mixed>>
	 */
	public static function search( array $photos, string $query ): array {
		$words = preg_split( '/\s+/u', mb_strtolower( trim( $query ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $words || array() === $words ) {
			return array_values( $photos );
		}
		$found = array();
		foreach ( $photos as $photo ) {
			$haystack = mb_strtolower( implode( ' ', array( (string) $photo['title'], (string) $photo['caption'], (string) $photo['alt'], (string) $photo['gallery_title'] ) ) );
			foreach ( $words as $word ) {
				if ( false === mb_strpos( $haystack, $word ) ) {
					continue 2;
				}
			}
			$found[] = $photo;
		}
		return $found;
	}

	/**
	 * One page of rows.
	 *
	 * @param array<int,array<string,mixed>> $photos   Normalised rows.
	 * @param int                            $page     Page number, from 1.
	 * @param int                            $per_page Rows per page, 1 to PAGE_SIZE.
	 * @return array{items:array<int,array<string,mixed>>,total:int,total_pages:int,page:int,per_page:int}
	 */
	public static function page( array $photos, int $page = 1, int $per_page = self::PER_PAGE ): array {
		$per_page = max( 1, min( self::PAGE_SIZE, $per_page ) );
		$total    = count( $photos );
		$pages    = (int) ceil( $total / $per_page );
		$page     = max( 1, $page );
		return array(
			'items'       => array_slice( array_values( $photos ), ( $page - 1 ) * $per_page, $per_page ),
			'total'       => $total,
			'total_pages' => $pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Normalises one platform photo, or null for one without an id.
	 *
	 * @param mixed $photo Photo from the platform.
	 * @return array{id:string,gallery_id:string,gallery_title:string,title:string,caption:string,alt:string,width:int,height:int,thumb_url:string,web_url:string,version:string}|null
	 */
	public static function normalise( $photo ): ?array {
		if ( ! is_array( $photo ) || empty( $photo['id'] ) ) {
			return null;
		}
		$thumb = self::variant_url( $photo, 'thumb' );
		$web   = self::variant_url( $photo, 'web' );
		$full  = $photo['full_url'] ?? '';
		$full  = is_string( $full ) && '' !== $full ? $full : $web;
		$cover = $photo['thumbnail_url'] ?? '';
		$cover = is_string( $cover ) && '' !== $cover ? $cover : $web;
		return array(
			'id'            => (string) $photo['id'],
			'gallery_id'    => (string) ( $photo['gallery_id'] ?? '' ),
			'gallery_title' => (string) ( $photo['gallery_title'] ?? '' ),
			'title'         => (string) ( $photo['title'] ?? '' ),
			'caption'       => (string) ( $photo['caption'] ?? '' ),
			'alt'           => (string) ( $photo['alt'] ?? '' ),
			'width'         => (int) ( $photo['width'] ?? 0 ),
			'height'        => (int) ( $photo['height'] ?? 0 ),
			'thumb_url'     => '' !== $thumb ? $thumb : $cover,
			'web_url'       => $full,
			'version'       => self::version( $full ),
		);
	}

	/**
	 * Pages through the platform list.
	 *
	 * @return array{photos:array<int,array<string,mixed>>,dropped:int}|WP_Error
	 */
	private function fetch_all() {
		$rows    = array();
		$kept    = 0;
		$offset  = 0;
		$total   = 0;
		$dropped = 0;
		do {
			$body = $this->api->request( 'GET', '/api/v1/embed/photos?limit=' . self::PAGE_SIZE . '&offset=' . $offset );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$list    = is_array( $body ) && isset( $body['photos'] ) && is_array( $body['photos'] ) ? array_values( $body['photos'] ) : array();
			$total   = is_array( $body ) && isset( $body['total'] ) ? (int) $body['total'] : 0;
			$offset += count( $list );
			foreach ( $list as $photo ) {
				$row = self::normalise( $photo );
				if ( null === $row ) {
					continue;
				}
				if ( $kept >= self::MAX_PHOTOS ) {
					++$dropped;
					continue;
				}
				$rows[] = $row;
				++$kept;
			}
		} while ( array() !== $list && $offset < $total && $kept < self::MAX_PHOTOS );
		if ( $total > $offset ) {
			$dropped += $total - $offset;
		}
		return array(
			'photos'  => $rows,
			'dropped' => $dropped,
		);
	}

	/**
	 * The stored catalogue, or null when none is stored or it is malformed.
	 *
	 * @return array{photos:array<int,array<string,mixed>>,stale:bool,fetched_at:int,dropped:int}|null
	 */
	private function stored(): ?array {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) || ! isset( $stored['photos'] ) || ! is_array( $stored['photos'] ) ) {
			return null;
		}
		return array(
			'photos'     => array_values( $stored['photos'] ),
			'stale'      => ! empty( $stored['stale'] ),
			'fetched_at' => (int) ( $stored['fetched_at'] ?? 0 ),
			'dropped'    => (int) ( $stored['dropped'] ?? 0 ),
		);
	}

	/**
	 * The result while the media source is off.
	 *
	 * @return array{photos:array<int,array<string,mixed>>,stale:bool,fetched_at:int,dropped:int}
	 */
	private static function empty_result(): array {
		return array(
			'photos'     => array(),
			'stale'      => false,
			'fetched_at' => 0,
			'dropped'    => 0,
		);
	}

	/**
	 * URL of one image variant of a photo, or an empty string.
	 *
	 * @param array<mixed> $photo   Photo.
	 * @param string       $variant Variant name.
	 */
	private static function variant_url( array $photo, string $variant ): string {
		foreach ( is_array( $photo['images'] ?? null ) ? $photo['images'] : array() as $image ) {
			if ( is_array( $image ) && ( $image['variant'] ?? '' ) === $variant && ! empty( $image['url'] ) ) {
				return (string) $image['url'];
			}
		}
		return '';
	}

	/**
	 * The 12 character version of an image URL such as `.../web-0123456789ab.jpg`.
	 *
	 * @param string $url Image URL.
	 */
	private static function version( string $url ): string {
		return 1 === preg_match( '~/(?:web|thumb)-([A-Za-z0-9]{12})\.[A-Za-z0-9]+(?:\?.*)?$~', $url, $match ) ? $match[1] : '';
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return null !== $this->clock ? (int) ( $this->clock )() : time();
	}
}
