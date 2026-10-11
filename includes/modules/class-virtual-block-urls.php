<?php
/**
 * Current URLs of Profotograaf photos in Image and Gallery blocks (issue #154).
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Config;
use Profotograaf\Module;
use Profotograaf\Photo_Importer;
use Profotograaf\Plugin;
use Profotograaf\Virtual_Block_Urls as Rewriter;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the render-time URL rewrite, the REST field it shares with the
 * editor, and the editor script that updates the `url` attribute of Image
 * blocks, Gallery items included.
 *
 * It registers whatever the setting "Keep photos on Profotograaf" says, so a
 * photo converted to local after the setting was switched off still shows its
 * local file in posts saved with the Profotograaf URL (issue #219). The
 * rewrite skips a photo that is still kept on Profotograaf while the setting
 * is off, because it has no working URL then.
 */
class Virtual_Block_Urls implements Module {

	/** Script handle. */
	public const HANDLE = 'profotograaf-virtual-urls';

	/** Name of the global the editor script reads its settings from. */
	public const SCRIPT_GLOBAL = 'profotograafVirtualUrls';

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		( new Rewriter( new Photo_Importer( $plugin->settings() ) ) )->register();
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the editor script for users who can edit posts.
	 */
	public function enqueue(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$asset_file = $this->build_directory() . 'index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script(
			self::HANDLE,
			PROFOTOGRAAF_URL . 'build/virtual-urls/index.js',
			is_array( $asset ) && isset( $asset['dependencies'] ) ? (array) $asset['dependencies'] : array(),
			is_array( $asset ) && isset( $asset['version'] ) ? (string) $asset['version'] : PROFOTOGRAAF_VERSION,
			true
		);
		wp_localize_script( self::HANDLE, self::SCRIPT_GLOBAL, array( 'platformUrl' => Config::platform_url() ) );
	}

	/**
	 * Absolute path of the built script's directory, with a trailing slash.
	 */
	protected function build_directory(): string {
		return PROFOTOGRAAF_DIR . 'build/virtual-urls/';
	}
}
