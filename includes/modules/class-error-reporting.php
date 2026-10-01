<?php
/**
 * Subscribes the error reporter to the plugin's failure hooks.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Error_Reporter;
use Profotograaf\Module;
use Profotograaf\Plugin;
use Profotograaf\Telemetry_Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the six action hooks and the logger to Error_Reporter. The reporter
 * checks consent on every call, so registering the hooks sends nothing.
 */
class Error_Reporting implements Module {

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$reporter = new Error_Reporter( new Telemetry_Sender( $plugin->settings() ) );

		add_action( 'profotograaf_refresh_failed', array( $reporter, 'on_refresh_failed' ) );
		add_action( 'profotograaf_lead_failed', array( $reporter, 'on_lead_failed' ), 10, 2 );
		add_action( 'profotograaf_lead_delivered', array( $reporter, 'on_lead_delivered' ), 10, 2 );
		add_action( 'profotograaf_lead_skipped', array( $reporter, 'on_lead_skipped' ) );
		add_action( 'profotograaf_lead_not_queued', array( $reporter, 'on_lead_not_queued' ), 10, 2 );
		// After the consent module cleared the queue, so the disconnect itself is still reported.
		add_action( 'profotograaf_disconnected', array( $reporter, 'on_disconnected' ), 20 );
		add_action( 'profotograaf_log', array( $reporter, 'on_log' ), 10, 3 );
	}
}
