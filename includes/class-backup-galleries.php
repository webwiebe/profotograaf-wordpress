<?php
/**
 * The private galleries the backup copies files into.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One private gallery per month, titled after the site ("Example
 * Photography copy 2026-10"). The month is the month an attachment was
 * uploaded to WordPress. A gallery is created on first need and its id kept
 * in the option `profotograaf_backup_galleries` (month => id), so every file
 * of that month goes to the same gallery.
 *
 * The platform creates these galleries private. Nothing here, or anywhere in
 * the backup, switches them on for embedding, so they never show up in the
 * media source or a gallery picker.
 */
final class Backup_Galleries {

	public const OPTION = 'profotograaf_backup_galleries';

	/** Longest site title used in a gallery title. The platform takes up to 200 characters. */
	private const TITLE_CHARS = 150;

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
	 * The month an attachment belongs to, as `YYYY-MM`: its upload date, or
	 * the month of $now when the date is missing.
	 *
	 * @param int $attachment_id Attachment id.
	 * @param int $now           Current unix time.
	 */
	public static function month_of( int $attachment_id, int $now ): string {
		$post = get_post( $attachment_id );
		$date = $post instanceof \WP_Post ? (string) $post->post_date : '';
		if ( 1 === preg_match( '/^(\d{4}-\d{2})-\d{2}/', $date, $match ) && '0000-00' !== $match[1] ) {
			return $match[1];
		}
		return gmdate( 'Y-m', $now );
	}

	/**
	 * The title of the gallery for a month.
	 *
	 * @param string $month `YYYY-MM`.
	 */
	public static function title( string $month ): string {
		$site = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( '' === $site ) {
			$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		if ( function_exists( 'mb_substr' ) ) {
			$site = mb_substr( $site, 0, self::TITLE_CHARS );
		} else {
			$site = substr( $site, 0, self::TITLE_CHARS );
		}
		/* translators: 1: site title, 2: year and month, for example 2026-10. */
		return trim( sprintf( __( '%1$s copy %2$s', 'profotograaf' ), $site, $month ) );
	}

	/**
	 * The gallery id for a month, created on Profotograaf the first time.
	 *
	 * @param string $month `YYYY-MM`.
	 * @return string|WP_Error The id, or the error of the create call.
	 */
	public function id_for( string $month ) {
		$all = $this->all();
		if ( isset( $all[ $month ] ) ) {
			return $all[ $month ];
		}
		$created = $this->api->create_gallery( self::title( $month ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$all[ $month ] = $created['id'];
		update_option( self::OPTION, $all, false );
		Logger::info(
			'Created a backup gallery on Profotograaf.',
			array(
				'month'   => $month,
				'gallery' => $created['id'],
			)
		);
		return $created['id'];
	}

	/**
	 * Drops a gallery that Profotograaf no longer knows, so the next file of
	 * that month gets a new one.
	 *
	 * @param string $gallery_id Gallery id.
	 */
	public function forget( string $gallery_id ): void {
		$all  = $this->all();
		$kept = array_filter( $all, static fn( string $id ): bool => $id !== $gallery_id );
		if ( count( $kept ) !== count( $all ) ) {
			update_option( self::OPTION, $kept, false );
		}
	}

	/**
	 * Whether a gallery id is one of the backup galleries. It reads the stored
	 * ids only and makes no network call. Change feed consumers and the photo
	 * catalogue use it to leave these galleries alone.
	 *
	 * @param string $gallery_id Gallery id.
	 */
	public static function contains( string $gallery_id ): bool {
		return '' !== $gallery_id && in_array( $gallery_id, self::all(), true );
	}

	/**
	 * The stored galleries, month => id.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$all    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $month => $id ) {
			if ( is_string( $month ) && is_scalar( $id ) && '' !== (string) $id ) {
				$all[ $month ] = (string) $id;
			}
		}
		return $all;
	}
}
