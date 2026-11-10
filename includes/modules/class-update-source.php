<?php
/**
 * Update source for installs outside wordpress.org.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Github_Updates;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the GitHub release update source for the wp-admin update check.
 */
class Update_Source implements Module {

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		( new Github_Updates( PROFOTOGRAAF_VERSION, plugin_basename( PROFOTOGRAAF_FILE ) ) )->register();
	}
}
