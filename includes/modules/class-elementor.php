<?php
/**
 * Elementor widget registration.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Elementor_Gallery_Widget;
use Profotograaf\Gallery_Index;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "Profotograaf Gallery" widget to Elementor.
 *
 * The module waits for `elementor/loaded` and touches no Elementor class before
 * `elementor/widgets/register`. Without Elementor it adds one action that never
 * fires and loads no widget file.
 */
class Elementor implements Module {

	/**
	 * Gallery list for the widget.
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
		if ( did_action( 'elementor/loaded' ) ) {
			$this->hook_widgets();
			return;
		}
		add_action( 'elementor/loaded', array( $this, 'hook_widgets' ) );
	}

	/**
	 * Hooks the widget registration once Elementor is up.
	 */
	public function hook_widgets(): void {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
	}

	/**
	 * Registers the widget.
	 *
	 * @param object $widgets Elementor's widgets manager.
	 */
	public function register_widget( $widgets ): void {
		if ( null === $this->index || ! method_exists( $widgets, 'register' ) ) {
			return;
		}
		Elementor_Gallery_Widget::use_index( $this->index );
		$widgets->register( new Elementor_Gallery_Widget() );
	}
}
