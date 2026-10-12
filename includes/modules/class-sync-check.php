<?php
/**
 * Check now route and the opportunistic check from admin screens.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Change_Poller;
use Profotograaf\Module;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Plugin;
use Profotograaf\Sync_Panel;
use Profotograaf\Sync_Actions_Rest;
use Profotograaf\Sync_Resolve;
use Profotograaf\Sync_Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Registers POST /profotograaf/v1/sync/run and loads its script on the Media
 * Library, the Import screen and the settings page for users with
 * upload_files. Hosts without WP-Cron stay current through these visits.
 */
class Sync_Check implements Module {

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
		$catalogue    = new Photo_Catalogue( $plugin->api(), $plugin->settings() );
		( new Sync_Rest( new Change_Poller( $plugin->api(), $catalogue ), $plugin->connection() ) )->register();
		( new Sync_Actions_Rest( new Sync_Resolve( array( new Photo_Importer( $plugin->settings(), null, $catalogue ), 'reimport' ), $catalogue ) ) )->register();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the script on the screens that carry the check.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( null === $this->plugin || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$screens = array( 'upload.php', 'media_page_' . Media_Import_Screen::SLUG, 'settings_page_' . Settings_Page::SLUG );
		if ( ! in_array( $hook_suffix, $screens, true ) ) {
			return;
		}
		Sync_Panel::enqueue( $this->plugin->connection() );
	}
}
