<?php
/**
 * Block registration.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every block found in `blocks/<name>/block.json`.
 *
 * `wp-scripts build` writes each block to `build/<name>/`. The built copy is
 * registered when it exists (release zips and after `npm run build`), the
 * source folder otherwise (server side only blocks during development). To add
 * a block, add a `blocks/<name>/` folder. No PHP file changes.
 */
class Blocks implements Module {

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		unset( $plugin );
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Registers the blocks.
	 */
	public function register_blocks(): void {
		foreach ( $this->block_directories() as $directory ) {
			register_block_type( $directory );
		}
	}

	/**
	 * Directories to register, one per block.
	 *
	 * @return array<int,string>
	 */
	public function block_directories(): array {
		$sources = glob( PROFOTOGRAAF_DIR . 'blocks/*/block.json' );
		$found   = array();
		foreach ( is_array( $sources ) ? $sources : array() as $source ) {
			$name  = basename( dirname( $source ) );
			$built = PROFOTOGRAAF_DIR . 'build/' . $name;
			if ( is_readable( $built . '/block.json' ) ) {
				$found[] = $built;
			} else {
				$found[] = dirname( $source );
			}
		}

		// A released zip has no blocks/ folder, only build/.
		$builds = glob( PROFOTOGRAAF_DIR . 'build/*/block.json' );
		foreach ( is_array( $builds ) ? $builds : array() as $build ) {
			$directory = dirname( $build );
			if ( ! in_array( $directory, $found, true ) ) {
				$found[] = $directory;
			}
		}

		/**
		 * Filters the block directories to register.
		 *
		 * @param string[] $directories Absolute paths, each holding a block.json.
		 */
		$directories = apply_filters( 'profotograaf_block_directories', $found );
		return array_values( array_filter( is_array( $directories ) ? $directories : array(), 'is_string' ) );
	}
}
