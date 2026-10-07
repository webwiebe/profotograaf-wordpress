<?php
/**
 * Background refresh of the photo catalogue.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Plugin;
use Profotograaf\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Refreshes the photo catalogue from WP-Cron, and only while the media source
 * setting is on and the site is connected. Switching the setting off removes
 * the event. Deactivation and uninstall clear every `profotograaf_` event.
 */
class Photo_Catalogue_Refresh implements Module {

	public const HOOK = 'profotograaf_refresh_photo_catalogue';

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
		add_action( 'init', array( $this, 'sync_schedule' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'sync_schedule' ) );
		add_action( 'profotograaf_connected', array( $this, 'sync_schedule' ) );
		add_action( 'profotograaf_disconnected', array( $this, 'unschedule' ) );
	}

	/**
	 * Refreshes the catalogue.
	 */
	public function run(): void {
		if ( ! $this->active() ) {
			return;
		}
		( new Photo_Catalogue( $this->plugin->api(), $this->plugin->settings() ) )->refresh();
	}

	/**
	 * Schedules the refresh while the setting is on and the site is connected,
	 * and removes it otherwise.
	 */
	public function sync_schedule(): void {
		if ( ! $this->active() ) {
			$this->unschedule();
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::HOOK );
		}
	}

	/**
	 * Removes the scheduled refresh.
	 */
	public function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Whether the media source is on and the site is connected.
	 *
	 * @phpstan-assert-if-true Plugin $this->plugin
	 */
	private function active(): bool {
		return null !== $this->plugin && $this->plugin->settings()->media_source_enabled() && $this->plugin->connection()->is_connected();
	}
}
