<?php
/**
 * Profotograaf category in the block inserter.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Photo_Catalogue;
use Profotograaf\Photo_File_Route;
use Profotograaf\Photo_Importer;
use Profotograaf\Photo_Url_Signer;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the signed photo file route and loads the inserter script in the
 * block editor. The script loads only while the media source setting is on and
 * the user can upload files.
 */
class Media_Source_Inserter implements Module {

	/** Script handle. */
	public const HANDLE = 'profotograaf-inserter-category';

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
		$settings     = $plugin->settings();
		( new Photo_File_Route( new Photo_Catalogue( $plugin->api(), $settings ), new Photo_Importer( $settings ), $settings, new Photo_Url_Signer() ) )->register();
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the editor script.
	 */
	public function enqueue(): void {
		if ( null === $this->plugin || ! $this->plugin->settings()->media_source_enabled() || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$asset_file = $this->build_directory() . 'index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script(
			self::HANDLE,
			PROFOTOGRAAF_URL . 'build/inserter-category/index.js',
			is_array( $asset ) && isset( $asset['dependencies'] ) ? (array) $asset['dependencies'] : array(),
			is_array( $asset ) && isset( $asset['version'] ) ? (string) $asset['version'] : PROFOTOGRAAF_VERSION,
			true
		);
		wp_set_script_translations( self::HANDLE, 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
	}

	/**
	 * Absolute path of the built script's directory, with a trailing slash.
	 */
	protected function build_directory(): string {
		return PROFOTOGRAAF_DIR . 'build/inserter-category/';
	}
}
