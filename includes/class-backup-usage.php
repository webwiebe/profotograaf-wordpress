<?php
/**
 * Which Media Library items the published content uses.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the attachments that published content shows, for the backup scope
 * `used`: the featured image, `wp-image-{id}` classes, the `id` of image,
 * cover and video blocks, the `mediaId` of media and text blocks, the `ids`
 * of gallery blocks and the `ids` of the `[gallery]` shortcode.
 *
 * The set of used ids is built by walking the published posts once and is
 * kept in a transient for a few minutes, so a backfill batch that checks 200
 * attachments reads the posts once.
 */
final class Backup_Usage {

	/** Transient that holds the used ids. */
	public const CACHE = 'profotograaf_backup_used';

	/** Seconds the used ids are kept. */
	public const CACHE_TTL = 600;

	/** Posts read per query. */
	public const PAGE = 100;

	/** Filter for the post types whose published entries count. */
	public const POST_TYPES_FILTER = 'profotograaf_backup_post_types';

	/** Core blocks that point at one attachment, with the attribute that holds its id. */
	private const SINGLE = array(
		'image'      => 'id',
		'cover'      => 'id',
		'video'      => 'id',
		'media-text' => 'mediaId',
	);

	/**
	 * Attachment ids that a piece of post content refers to.
	 *
	 * @param string $content Post content.
	 * @return array<int,int> Ids in ascending order, without duplicates.
	 */
	public static function references( string $content ): array {
		$ids = array();
		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $content, $matches ) ) {
			$ids = array_merge( $ids, $matches[1] );
		}
		if ( preg_match_all( '#<!--\s+wp:(?:core/)?([a-z][a-z0-9-]*)\s+(\{.*?\})\s+/?-->#s', $content, $blocks, PREG_SET_ORDER ) ) {
			foreach ( $blocks as $block ) {
				$ids = array_merge( $ids, self::block_ids( $block[1], $block[2] ) );
			}
		}
		if ( preg_match_all( '/\[gallery\b[^\]]*?\bids=["\']?([\d,\s]+)/', $content, $galleries ) ) {
			foreach ( $galleries[1] as $list ) {
				$ids = array_merge( $ids, explode( ',', $list ) );
			}
		}
		$ids = array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 );
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Whether published content uses an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function is_used( int $attachment_id ): bool {
		return isset( $this->used_ids()[ $attachment_id ] );
	}

	/**
	 * The ids of every attachment published content uses, as keys.
	 *
	 * @return array<int,true>
	 */
	public function used_ids(): array {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$used  = array();
		$page  = 1;
		$count = 0;
		do {
			$posts = get_posts(
				array(
					'post_type'      => $this->post_types(),
					'post_status'    => 'publish',
					'posts_per_page' => self::PAGE,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			foreach ( $posts as $post ) {
				foreach ( $this->in_post( $post ) as $id ) {
					$used[ $id ] = true;
				}
			}
			++$page;
			$count = count( $posts );
		} while ( $count >= self::PAGE );

		set_transient( self::CACHE, $used, self::CACHE_TTL );
		return $used;
	}

	/**
	 * Attachment ids one post uses: its featured image and its content.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int,int>
	 */
	public function in_post( $post ): array {
		$ids       = self::references( (string) $post->post_content );
		$thumbnail = (int) get_post_meta( (int) $post->ID, '_thumbnail_id', true );
		if ( $thumbnail > 0 && ! in_array( $thumbnail, $ids, true ) ) {
			$ids[] = $thumbnail;
			sort( $ids );
		}
		return $ids;
	}

	/**
	 * Drops the cached ids, for example after a post is published.
	 */
	public static function forget(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * The public post types, without attachments.
	 *
	 * @return array<int,string>
	 */
	private function post_types(): array {
		$types = array_values( array_diff( array_values( (array) get_post_types( array( 'public' => true ) ) ), array( 'attachment' ) ) );
		/**
		 * Filters the post types whose published entries make an attachment
		 * "used" for the backup.
		 *
		 * @param array<int,string> $types Post type names.
		 */
		$types = apply_filters( 'profotograaf_backup_post_types', $types );
		return array_values( array_filter( array_map( 'strval', (array) $types ) ) );
	}

	/**
	 * The attachment ids in the attributes of one block.
	 *
	 * @param string $name Block name without the `core/` namespace.
	 * @param string $json Attributes as JSON.
	 * @return array<int,mixed>
	 */
	private static function block_ids( string $name, string $json ): array {
		if ( ! isset( self::SINGLE[ $name ] ) && 'gallery' !== $name ) {
			return array();
		}
		$attributes = json_decode( $json, true );
		if ( ! is_array( $attributes ) ) {
			return array();
		}
		if ( 'gallery' === $name ) {
			return isset( $attributes['ids'] ) && is_array( $attributes['ids'] ) ? array_filter( $attributes['ids'], 'is_numeric' ) : array();
		}
		$id = $attributes[ self::SINGLE[ $name ] ] ?? null;
		return is_numeric( $id ) ? array( $id ) : array();
	}
}
