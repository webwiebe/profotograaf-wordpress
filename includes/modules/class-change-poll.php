<?php
/**
 * Hourly poll of the platform change feed.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Change_Poller;
use Profotograaf\Cron_Health;
use Profotograaf\Module;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Runs Change_Poller from WP-Cron while the site is connected. The poll
 * follows the connection, not the media source setting: copies already
 * imported stay watched when the setting is switched off.
 *
 * The poll event also records the cron heartbeat, so one working cron proves
 * both lead delivery and the poll. Disconnecting removes the events and the
 * cursor. Deactivation and uninstall clear every `profotograaf_` event.
 */
class Change_Poll implements Module {

	public const HOOK = 'profotograaf_poll_changes';

	/**
	 * Schedule name of the poll.
	 */
	public const INTERVAL = 'hourly';

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
		add_action( self::HOOK, array( Cron_Health::for_site(), 'beat' ), 1 );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( Change_Poller::BATCH_HOOK, array( $this, 'run_batch' ) );
		add_action( 'init', array( $this, 'sync_schedule' ) );
		add_action( 'profotograaf_connected', array( $this, 'sync_schedule' ) );
		add_action( 'profotograaf_disconnected', array( $this, 'on_disconnected' ) );
	}

	/**
	 * Runs one bounded poll.
	 */
	public function run(): void {
		if ( ! $this->active() ) {
			return;
		}
		$this->poller()->run();
	}

	/**
	 * Reconciles the next batch after a resync.
	 */
	public function run_batch(): void {
		if ( ! $this->active() ) {
			return;
		}
		$this->poller()->run_batch();
	}

	/**
	 * Schedules the poll while the site is connected, and removes it otherwise.
	 */
	public function sync_schedule(): void {
		if ( ! $this->active() ) {
			$this->unschedule();
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			/**
			 * Filters the schedule name of the change feed poll.
			 *
			 * @param string $interval A registered cron schedule, `hourly` by default.
			 */
			$interval = apply_filters( 'profotograaf_poll_interval', self::INTERVAL );
			wp_schedule_event( time() + HOUR_IN_SECONDS, is_string( $interval ) && '' !== $interval ? $interval : self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Removes the events and the cursor, so a later connection to another
	 * account starts from a fresh feed.
	 */
	public function on_disconnected(): void {
		$this->unschedule();
		Change_Poller::forget();
	}

	/**
	 * Removes the scheduled events.
	 */
	public function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( Change_Poller::BATCH_HOOK );
	}

	/**
	 * The poller over the plugin's API client.
	 *
	 * @phpstan-assert Plugin $this->plugin
	 */
	private function poller(): Change_Poller {
		return new Change_Poller( $this->plugin->api(), new Photo_Catalogue( $this->plugin->api(), $this->plugin->settings() ) );
	}

	/**
	 * Whether the site is connected.
	 *
	 * @phpstan-assert-if-true Plugin $this->plugin
	 */
	private function active(): bool {
		return null !== $this->plugin && $this->plugin->connection()->is_connected();
	}
}
