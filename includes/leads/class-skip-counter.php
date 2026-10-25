<?php
/**
 * Counts submissions that were not sent for lack of an email address.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps, per form, how many submissions had no usable email address and when
 * the last one came in. The photographer sees the count on the Enquiry forms
 * screen, so a form without an email field is not silent. The option holds
 * counts and times only, never a visitor's data, and is not autoloaded.
 */
final class Skip_Counter {

	public const OPTION = 'profotograaf_leads_skips';

	/**
	 * Counts one skipped submission.
	 *
	 * @param string $form_key Form key.
	 * @param int    $now      Current unix time.
	 */
	public function record( string $form_key, int $now ): void {
		$all              = $this->all();
		$count            = isset( $all[ $form_key ] ) ? $all[ $form_key ]['count'] : 0;
		$all[ $form_key ] = array(
			'count'   => $count + 1,
			'last_at' => $now,
		);
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Stops counting a form, for example after the photographer fixed it.
	 *
	 * @param string $form_key Form key.
	 */
	public function forget( string $form_key ): void {
		$all = $this->all();
		if ( ! isset( $all[ $form_key ] ) ) {
			return;
		}
		unset( $all[ $form_key ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * What was counted.
	 *
	 * @return array<string,array{count:int,last_at:int}>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$all    = array();
		if ( ! is_array( $stored ) ) {
			return $all;
		}
		foreach ( $stored as $key => $row ) {
			if ( is_string( $key ) && is_array( $row ) && isset( $row['count'] ) ) {
				$all[ $key ] = array(
					'count'   => max( 0, (int) $row['count'] ),
					'last_at' => (int) ( $row['last_at'] ?? 0 ),
				);
			}
		}
		return $all;
	}
}
