<?php
/**
 * One-time baseline for copies imported before the conflict baseline existed.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Media_Baseline;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Runs Media_Baseline::backfill() in admin requests, one batch each, until no
 * copy is left without a baseline, then stores a flag and stops. It reads and
 * writes local data only.
 */
class Media_Baseline_Backfill implements Module {

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		unset( $plugin );
		add_action( 'admin_init', array( $this, 'run' ) );
	}

	/**
	 * Handles one batch.
	 */
	public function run(): void {
		if ( 'done' === get_option( Media_Baseline::BACKFILL_OPTION, '' ) ) {
			return;
		}
		if ( Media_Baseline::backfill() < Media_Baseline::BACKFILL_BATCH ) {
			update_option( Media_Baseline::BACKFILL_OPTION, 'done', false );
		}
	}
}
