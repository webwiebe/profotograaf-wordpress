<?php
/**
 * Virtual attachments: photos kept on Profotograaf (spike #104, issue #151).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Plugin;
use Profotograaf\Virtual_Attachments as Prototype;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Virtual_Attachments filters when the setting "Keep photos on
 * Profotograaf" is on. With the setting off this module registers nothing.
 */
class Virtual_Attachments implements Module {

	/**
	 * Adds the hooks when the setting is on.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		if ( ! Prototype::enabled() ) {
			return;
		}
		$settings  = $plugin->settings();
		$catalogue = new Photo_Catalogue( $plugin->api(), $settings );
		( new Prototype( $settings, new Photo_Importer( $settings, null, $catalogue ), $catalogue ) )->register();
	}
}
