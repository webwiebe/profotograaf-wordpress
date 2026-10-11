<?php
/**
 * The full-page gallery block.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Full_Page_Payload;
use Profotograaf\Full_Page_Renderer;
use Profotograaf\Gallery_Index;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Gives the block `profotograaf/full-page-gallery` (blocks/full-page-gallery,
 * registered by the Blocks module) its render callback, Full_Page_Renderer.
 * The block folder holds no PHP.
 */
class Full_Page_Gallery implements Module {

	public const BLOCK = 'profotograaf/full-page-gallery';

	/**
	 * The renderer.
	 *
	 * @var Full_Page_Renderer|null
	 */
	private ?Full_Page_Renderer $renderer = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$this->renderer = new Full_Page_Renderer(
			new Full_Page_Payload( $plugin->api() ),
			new Gallery_Index( $plugin->api() ),
			$plugin->settings()
		);
		add_filter( 'register_block_type_args', array( $this, 'block_args' ), 10, 2 );
		add_action( 'init', array( $this, 'load_script_translations' ), 20 );
	}

	/**
	 * Gives the block its render callback.
	 *
	 * @param array<string,mixed> $args Block type arguments.
	 * @param string              $name Block name.
	 * @return array<string,mixed>
	 */
	public function block_args( array $args, string $name ): array {
		if ( self::BLOCK === $name ) {
			$args['render_callback'] = array( $this, 'render_block' );
		}
		return $args;
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string HTML.
	 */
	public function render_block( array $attributes ): string {
		return null === $this->renderer ? '' : $this->renderer->render( $attributes );
	}

	/**
	 * Loads the translations of the editor script (the inspector and the
	 * preview) and the front-end script (the lightbox and filmstrip labels)
	 * from this plugin's languages folder.
	 *
	 * The JSON files are named after the built scripts (build/full-page-gallery/
	 * index.js and view.js), the paths WordPress hashes, so the standard lookup
	 * finds them.
	 */
	public function load_script_translations(): void {
		if ( ! function_exists( 'generate_block_asset_handle' ) ) {
			return;
		}
		foreach ( array( 'editorScript', 'viewScript' ) as $field ) {
			$handle = generate_block_asset_handle( self::BLOCK, $field );
			if ( wp_script_is( $handle, 'registered' ) ) {
				wp_set_script_translations( $handle, 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
			}
		}
	}
}
