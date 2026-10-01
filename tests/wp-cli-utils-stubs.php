<?php
/**
 * WP-CLI helper functions the commands use. Pairs with wp-cli-stubs.php.
 *
 * @package Profotograaf
 */

namespace WP_CLI\Utils;

if ( ! function_exists( __NAMESPACE__ . '\format_items' ) ) {
	/**
	 * Records the rows as one JSON line.
	 *
	 * @param string                          $format Format.
	 * @param array<int,array<string,mixed>>  $items  Rows.
	 * @param string[]                        $fields Columns.
	 */
	function format_items( $format, $items, $fields ): void {
		\WP_CLI::$output[] = array( 'items', (string) json_encode( array( $format, $items, $fields ) ) );
	}
}
