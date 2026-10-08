<?php
/**
 * REST route for browsing Profotograaf photos.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_Importer;
use Profotograaf\Photo_Rest;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers GET /profotograaf/v1/photos. The route answers 403 while the media
 * source setting is off.
 */
class Media_Source_Rest implements Module {

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$settings  = $plugin->settings();
		$catalogue = new Photo_Catalogue( $plugin->api(), $settings );
		( new Photo_Rest( $catalogue, new Photo_Importer( $settings, null, $catalogue ), $settings ) )->register();
	}
}
