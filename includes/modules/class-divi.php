<?php
/**
 * Divi 4 module registration.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Divi_Gallery_Module;
use Profotograaf\Gallery_Index;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "Profotograaf Gallery" module to the Divi 4 builder.
 *
 * Divi builds its module list on `et_builder_ready`. The module touches no Divi
 * class before that action. Without Divi it adds one action that never fires
 * and loads no module file.
 *
 * This covers Divi 4. Divi 5 has a different module API.
 */
class Divi implements Module {

	/**
	 * Gallery list for the module.
	 *
	 * @var Gallery_Index|null
	 */
	private ?Gallery_Index $index = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$this->index = new Gallery_Index( $plugin->api() );
		if ( did_action( 'et_builder_ready' ) ) {
			$this->add_module();
			return;
		}
		add_action( 'et_builder_ready', array( $this, 'add_module' ) );
	}

	/**
	 * Creates the module, which Divi registers from its constructor.
	 */
	public function add_module(): void {
		if ( null === $this->index || ! class_exists( 'ET_Builder_Module', false ) ) {
			return;
		}
		Divi_Gallery_Module::use_index( $this->index );
		new Divi_Gallery_Module();
	}
}
