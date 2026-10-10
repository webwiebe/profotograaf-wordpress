<?php
/**
 * The "Last checked" line and the Check now button.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Markup and script for the photo change check on the settings page, the
 * Import screen and the Media Library. The script (assets/admin/sync.js)
 * posts to /profotograaf/v1/sync/run, and does so once in the background when
 * the last success is old (Sync_Rest::background_due). Nothing here runs on
 * the front end.
 */
final class Sync_Panel {

	/**
	 * "Photo changes last checked 5 minutes ago." or "Photo changes have not been checked yet."
	 *
	 * @param int|null $last_ok Unix time of the last success.
	 * @param int      $now     Current unix time.
	 */
	public static function text( ?int $last_ok, int $now ): string {
		if ( null === $last_ok ) {
			return __( 'Photo changes have not been checked yet.', 'profotograaf' );
		}
		return sprintf(
			/* translators: %s: time since the last check, for example "5 minutes". */
			__( 'Photo changes last checked %s ago.', 'profotograaf' ),
			human_time_diff( $last_ok, $now )
		);
	}

	/**
	 * Prints the line and the button while the site is connected.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function render( Connection $connection ): void {
		if ( ! $connection->is_connected() ) {
			return;
		}
		printf(
			'<p class="profotograaf-sync"><span class="profotograaf-sync__text">%1$s</span> <button type="button" class="button profotograaf-sync__button">%2$s</button> <span class="profotograaf-sync__status" role="status" aria-live="polite"></span></p>',
			esc_html( self::text( Change_Poller::last_ok(), time() ) ),
			esc_html__( 'Check now', 'profotograaf' )
		);
	}

	/**
	 * Loads the script, with a flag that asks for one background run.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function enqueue( Connection $connection ): void {
		if ( ! $connection->is_connected() ) {
			return;
		}
		wp_enqueue_script( 'profotograaf-sync', PROFOTOGRAAF_URL . 'assets/admin/sync.js', array( 'wp-api-fetch', 'wp-i18n' ), PROFOTOGRAAF_VERSION, true );
		wp_set_script_translations( 'profotograaf-sync', 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
		wp_localize_script( 'profotograaf-sync', 'profotograafSync', array( 'background' => Sync_Rest::background_due( true, time() ) ) );
	}
}
