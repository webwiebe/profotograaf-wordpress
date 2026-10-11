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
use Profotograaf\Virtual_Attachments;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the /profotograaf/v1/photos routes. They answer 403 while the media
 * source setting is off. The keep route exists only while the setting "Keep
 * photos on Profotograaf" is on.
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
		$importer  = new Photo_Importer( $settings, null, $catalogue );
		$virtual   = Virtual_Attachments::enabled() ? new Virtual_Attachments( $settings, $importer, $catalogue ) : null;
		( new Photo_Rest( $catalogue, $importer, $settings, $virtual ) )->register();
	}
}
