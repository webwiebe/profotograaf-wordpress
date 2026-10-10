<?php
/**
 * The galleries the Media Library may send files to.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the linked galleries in the option `profotograaf_upload_links`
 * (docs/media-lifecycle.md, rule 4). A file leaves the site only for a
 * gallery that is in this list. There is no default gallery.
 *
 * Each link holds `gallery_id`, `title`, `created_by` (user id),
 * `created_at` (unix time), `auto` (send new image uploads without asking)
 * and `disabled` (the platform no longer knows the gallery). At most one
 * link has `auto` on. Unlinking leaves the platform gallery and its photos
 * alone. The option starts with `profotograaf_`, so uninstall removes it.
 *
 * @phpstan-type Link array{gallery_id:string,title:string,created_by:int,created_at:int,auto:bool,disabled:bool}
 */
final class Upload_Links {

	public const OPTION = 'profotograaf_upload_links';

	/**
	 * All links, oldest first.
	 *
	 * @return array<int,Link>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$links  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['gallery_id'] ) || ! is_scalar( $row['gallery_id'] ) ) {
				continue;
			}
			$links[] = array(
				'gallery_id' => (string) $row['gallery_id'],
				'title'      => isset( $row['title'] ) && is_scalar( $row['title'] ) ? (string) $row['title'] : '',
				'created_by' => (int) ( $row['created_by'] ?? 0 ),
				'created_at' => (int) ( $row['created_at'] ?? 0 ),
				'auto'       => ! empty( $row['auto'] ),
				'disabled'   => ! empty( $row['disabled'] ),
			);
		}
		return $links;
	}

	/**
	 * The links a file can be sent to now.
	 *
	 * @return array<int,Link>
	 */
	public function usable(): array {
		return array_values( array_filter( $this->all(), static fn( array $link ): bool => ! $link['disabled'] ) );
	}

	/**
	 * One link by gallery id.
	 *
	 * @param string $gallery_id Platform gallery id.
	 * @return Link|null
	 */
	public function find( string $gallery_id ): ?array {
		foreach ( $this->all() as $link ) {
			if ( $link['gallery_id'] === $gallery_id ) {
				return $link;
			}
		}
		return null;
	}

	/**
	 * The link whose new uploads are sent automatically, if any.
	 *
	 * @return Link|null
	 */
	public function auto_link(): ?array {
		foreach ( $this->usable() as $link ) {
			if ( $link['auto'] ) {
				return $link;
			}
		}
		return null;
	}

	/**
	 * Links a gallery. A gallery that is linked already keeps its entry and is re-enabled.
	 *
	 * @param string $gallery_id Platform gallery id.
	 * @param string $title      Gallery title.
	 * @param int    $user_id    User who linked it.
	 * @param int    $now        Unix time.
	 */
	public function add( string $gallery_id, string $title, int $user_id, int $now ): bool {
		$gallery_id = trim( $gallery_id );
		if ( '' === $gallery_id ) {
			return false;
		}
		$links = $this->all();
		foreach ( $links as $index => $link ) {
			if ( $link['gallery_id'] === $gallery_id ) {
				$links[ $index ]['disabled'] = false;
				$links[ $index ]['title']    = '' !== $title ? $title : $link['title'];
				$this->save( $links );
				return true;
			}
		}
		$links[] = array(
			'gallery_id' => $gallery_id,
			'title'      => $title,
			'created_by' => $user_id,
			'created_at' => $now,
			'auto'       => false,
			'disabled'   => false,
		);
		$this->save( $links );
		return true;
	}

	/**
	 * Removes a link. Files already queued for it fail with a message when they run.
	 *
	 * @param string $gallery_id Platform gallery id.
	 */
	public function remove( string $gallery_id ): void {
		$this->save( array_values( array_filter( $this->all(), static fn( array $link ): bool => $link['gallery_id'] !== $gallery_id ) ) );
	}

	/**
	 * Switches automatic sending on for one link, which switches it off for the others.
	 *
	 * @param string $gallery_id Platform gallery id.
	 * @param bool   $auto       Whether new image uploads go to this gallery.
	 * @return bool False when no link has that gallery id.
	 */
	public function set_auto( string $gallery_id, bool $auto ): bool {
		$links = $this->all();
		$found = false;
		foreach ( $links as $index => $link ) {
			$is_target               = $link['gallery_id'] === $gallery_id;
			$found                   = $found || $is_target;
			$links[ $index ]['auto'] = $is_target && $auto && ! $link['disabled'];
		}
		if ( $found ) {
			$this->save( $links );
		}
		return $found;
	}

	/**
	 * Marks a link as unusable because the platform answered 404 for its gallery.
	 *
	 * @param string $gallery_id Platform gallery id.
	 */
	public function disable( string $gallery_id ): void {
		$links = $this->all();
		foreach ( $links as $index => $link ) {
			if ( $link['gallery_id'] === $gallery_id ) {
				$links[ $index ]['disabled'] = true;
				$links[ $index ]['auto']     = false;
			}
		}
		$this->save( $links );
	}

	/**
	 * Writes the list, without autoload.
	 *
	 * @param array<int,Link> $links Links.
	 */
	private function save( array $links ): void {
		update_option( self::OPTION, array_values( $links ), false );
	}
}
