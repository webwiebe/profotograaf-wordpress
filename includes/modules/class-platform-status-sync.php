<?php
/**
 * Scheduled platform status call.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the platform status call from WP-Cron once a day while the site is
 * connected, and shortly after the settings page opens (at most once per
 * hour). The page only queues a one-off event, so rendering never waits on
 * the platform.
 */
class Platform_Status_Sync implements Module {

	public const HOOK = 'profotograaf_platform_status';

	public const SOON_HOOK = 'profotograaf_platform_status_soon';

	/**
	 * Minimum seconds between two calls queued by the settings page.
	 */
	private const PAGE_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * Plugin container.
	 *
	 * @var Plugin|null
	 */
	private ?Plugin $plugin = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::SOON_HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( 'profotograaf_connected', array( $this, 'schedule' ) );
		add_action( 'profotograaf_disconnected', array( $this, 'on_disconnected' ) );
		add_action( 'profotograaf_review_event_queued', array( $this, 'queue_soon' ) );
		add_action( 'load-settings_page_' . Settings_Page::SLUG, array( $this, 'on_settings_page' ) );
	}

	/**
	 * Sends the stats. A failure keeps the stored answer and waits for the next run.
	 */
	public function run(): void {
		if ( null === $this->plugin || ! $this->plugin->connection()->is_connected() ) {
			return;
		}
		$review = $this->plugin->review_prompt();
		$event  = $review->next_event();
		if ( true !== $this->plugin->platform_status()->refresh( $event ) ) {
			return;
		}
		if ( null !== $event ) {
			$review->acknowledge( $event );
		}
		// One event goes out per call, in order. Send the rest soon.
		if ( $review->pending() > 0 ) {
			$this->queue_soon();
		}
	}

	/**
	 * Queues a one-off call, for a review event that waits for the platform.
	 */
	public function queue_soon(): void {
		if ( ! wp_next_scheduled( self::SOON_HOOK ) ) {
			wp_schedule_single_event( time(), self::SOON_HOOK );
		}
	}

	/**
	 * Queues a background call when the settings page opens, at most hourly.
	 */
	public function on_settings_page(): void {
		if ( null === $this->plugin || ! $this->plugin->connection()->is_connected() ) {
			return;
		}
		if ( ! $this->plugin->platform_status()->claim( self::PAGE_INTERVAL ) ) {
			return;
		}
		$this->queue_soon();
	}

	/**
	 * Schedules the daily call.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Restores the schedule when the site is connected but the event is gone.
	 */
	public function ensure_scheduled(): void {
		if ( null !== $this->plugin && $this->plugin->connection()->is_connected() ) {
			$this->schedule();
		}
	}

	/**
	 * Removes the events and the stored answer, so a later connection to
	 * another account never keeps this account's configuration.
	 */
	public function on_disconnected(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::SOON_HOOK );
		if ( null !== $this->plugin ) {
			$this->plugin->platform_status()->forget();
		}
	}
}
