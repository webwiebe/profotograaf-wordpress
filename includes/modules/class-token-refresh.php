<?php
/**
 * Background token refresh.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Refreshes the token pair from WP-Cron while the site is connected, so the
 * 60 day refresh token never lapses on a quiet site. The API client also
 * refreshes on demand, so a missed cron run costs nothing.
 */
class Token_Refresh implements Module {

	public const HOOK = 'profotograaf_refresh_tokens';

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
		add_action( 'profotograaf_connected', array( $this, 'schedule' ) );
		add_action( 'profotograaf_disconnected', array( $this, 'unschedule' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Refreshes the tokens. Failures are left for the next run.
	 */
	public function run(): void {
		if ( null === $this->plugin || ! $this->plugin->connection()->is_connected() ) {
			return;
		}
		$result = $this->plugin->api()->refresh_tokens();
		if ( is_wp_error( $result ) ) {
			/**
			 * Fires when the background refresh failed.
			 *
			 * @param \WP_Error $result The error.
			 */
			do_action( 'profotograaf_refresh_failed', $result );
		}
	}

	/**
	 * Schedules the hourly refresh.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Removes the scheduled refresh.
	 */
	public function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Restores the schedule when the site is connected but the event is gone.
	 */
	public function ensure_scheduled(): void {
		if ( null !== $this->plugin && $this->plugin->connection()->is_connected() ) {
			$this->schedule();
		}
	}
}
