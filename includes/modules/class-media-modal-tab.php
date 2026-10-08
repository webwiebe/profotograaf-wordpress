<?php
/**
 * Profotograaf tab in the media modal.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the script that adds a Profotograaf tab to the media modal. The modal
 * feeds the Gallery block, the Image block, the classic editor and the
 * featured image box. The script loads wherever WordPress enqueues the media
 * views, and only while the media source setting is on and the user can upload
 * files. Importing goes through the routes of the Import screen.
 */
class Media_Modal_Tab implements Module {

	/** Script handle. */
	public const HANDLE = 'profotograaf-media-modal';

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
		add_action( 'wp_enqueue_media', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the script and its style.
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
		$asset = is_array( $asset ) ? $asset : array();
		$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : PROFOTOGRAAF_VERSION;

		if ( is_readable( $this->build_directory() . 'style-index.css' ) ) {
			wp_enqueue_style( self::HANDLE, PROFOTOGRAAF_URL . 'build/media-modal/style-index.css', array(), $ver );
		}
		// The script patches the media frame classes, which media-views defines.
		wp_enqueue_script( self::HANDLE, PROFOTOGRAAF_URL . 'build/media-modal/index.js', array_merge( $deps, array( 'media-views' ) ), $ver, true );
		wp_set_script_translations( self::HANDLE, 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
	}

	/**
	 * Absolute path of the built files' directory, with a trailing slash.
	 */
	protected function build_directory(): string {
		return PROFOTOGRAAF_DIR . 'build/media-modal/';
	}
}
