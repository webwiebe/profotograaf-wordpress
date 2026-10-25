<?php
/**
 * Shapes the platform lists the reconcile engine reads.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the gallery list and the change feed into lookups by id.
 */
final class Reconcile_Input {

	/**
	 * Gallery rows by id with a `serving` flag.
	 *
	 * @param mixed $rows Gallery list rows, or null when the request failed.
	 * @return array<string,array{serving:bool}>|null
	 */
	public static function gallery_index( $rows ): ?array {
		if ( ! is_array( $rows ) ) {
			return null;
		}
		$index = array();
		foreach ( $rows as $gallery ) {
			if ( ! is_array( $gallery ) || empty( $gallery['id'] ) ) {
				continue;
			}
			$index[ (string) $gallery['id'] ] = array(
				'serving' => ! empty( $gallery['embeddable'] ) && ! empty( $gallery['available'] ),
			);
		}
		return $index;
	}

	/**
	 * Feed entries by id.
	 *
	 * @param mixed $feed Feed input, or null.
	 * @return array{deleted:array<string,array{gallery_id:string}>,changed:array<string,bool>,galleries_deleted:array<string,bool>}
	 */
	public static function feed_index( $feed ): array {
		$index = array(
			'deleted'           => array(),
			'changed'           => array(),
			'galleries_deleted' => array(),
		);
		if ( ! is_array( $feed ) ) {
			return $index;
		}
		foreach ( (array) ( $feed['photos_deleted'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
				$index['deleted'][ (string) $entry['id'] ] = array( 'gallery_id' => (string) ( $entry['gallery_id'] ?? '' ) );
			}
		}
		foreach ( (array) ( $feed['photos_changed'] ?? array() ) as $id ) {
			if ( is_scalar( $id ) && '' !== (string) $id ) {
				$index['changed'][ (string) $id ] = true;
			}
		}
		foreach ( (array) ( $feed['galleries_deleted'] ?? array() ) as $id ) {
			if ( is_scalar( $id ) && '' !== (string) $id ) {
				$index['galleries_deleted'][ (string) $id ] = true;
			}
		}
		return $index;
	}
}
