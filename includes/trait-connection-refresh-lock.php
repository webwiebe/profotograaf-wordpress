<?php
/**
 * The token refresh lock of the stored connection.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * The refresh lock and the two raw option reads it needs, kept apart from
 * Connection to keep that file short. Uses Connection::LOCK_OPTION and
 * Connection::LOCK_TTL.
 */
trait Connection_Refresh_Lock {

	/**
	 * The value this request wrote into the refresh lock, empty when it holds none.
	 *
	 * @var string
	 */
	private string $lock_value = '';

	/**
	 * Takes the refresh lock. Refresh tokens rotate on every use, so two
	 * requests refreshing at once would make the second present a dead token.
	 *
	 * @param int $now Current unix time.
	 */
	public function acquire_lock( int $now ): bool {
		$token = wp_generate_uuid4();
		$value = $now . ':' . $token;
		if ( $this->insert_lock( $value ) ) {
			return true;
		}
		$held = $this->raw_option( self::LOCK_OPTION );
		if ( null === $held ) {
			// Released between the two reads: try once more.
			return $this->insert_lock( $value );
		}
		$since = (int) $held;
		if ( $since > 0 && $now - $since < self::LOCK_TTL ) {
			return false;
		}
		// An expired lock is replaced in one conditional UPDATE: of several
		// requests that saw the same expired value, exactly one changes the row.
		if ( $this->swap_option( self::LOCK_OPTION, $held, $value ) ) {
			$this->lock_value = $value;
			return true;
		}
		return false;
	}

	/**
	 * Inserts the lock row, which only one of several requests can do.
	 *
	 * @param string $value Lock value of this request.
	 */
	private function insert_lock( string $value ): bool {
		if ( ! add_option( self::LOCK_OPTION, $value, '', false ) ) {
			return false;
		}
		$this->lock_value = $value;
		return true;
	}

	/**
	 * Releases the refresh lock, if this request still holds it.
	 */
	public function release_lock(): void {
		if ( '' === $this->lock_value ) {
			return;
		}
		global $wpdb;
		// Deletes the row only while it still holds this request's value: a run
		// that outlived the lock must not release the lock of the run that took over.
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a conditional delete of one option row.
			$wpdb->options,
			array(
				'option_name'  => self::LOCK_OPTION,
				'option_value' => $this->lock_value,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a conditional delete of one option row.
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$this->lock_value = '';
	}

	/**
	 * The stored text of one option, read past the object cache.
	 *
	 * @param string $name Option name.
	 */
	private function raw_option( string $name ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one option row, read past the cache.
		return null === $value ? null : (string) $value;
	}

	/**
	 * Replaces an option's stored text only while it still equals what was read.
	 *
	 * @param string $name Option name.
	 * @param string $old  Stored text that was read.
	 * @param mixed  $value New value.
	 */
	private function swap_option( string $name, string $old, $value ): bool {
		global $wpdb;
		$changed = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a compare-and-swap of one option row.
			$wpdb->options,
			array( 'option_value' => maybe_serialize( $value ) ),
			array(
				'option_name'  => $name,
				'option_value' => $old,
			)
		);
		wp_cache_delete( $name, 'options' );
		return 1 === $changed;
	}
}
