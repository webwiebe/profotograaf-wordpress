<?php
/**
 * Lead bridges module.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Admin\Leads_Settings;
use Profotograaf\Leads\Bridge;
use Profotograaf\Leads\Contact_Form_7;
use Profotograaf\Leads\Delivery;
use Profotograaf\Leads\Dispatcher;
use Profotograaf\Leads\Form_Settings;
use Profotograaf\Leads\Gravity_Forms;
use Profotograaf\Leads\Option_Job_Store;
use Profotograaf\Leads\Queue;
use Profotograaf\Leads\Wpforms;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Sends form submissions to the photographer's Profotograaf inbox.
 *
 * Contact Form 7, WPForms and Gravity Forms each get a bridge that listens to
 * the plugin's public submission hook and queues the lead. WP-Cron delivers it
 * with Api_Client::post_lead() and retries with exponential backoff, so a form
 * submission never waits for Profotograaf and never fails because of it.
 *
 * The `profotograaf_lead_bridges` filter adds bridges for other form plugins.
 */
class Leads implements Module {

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		add_action( 'init', array( $this, 'load_translations' ) );

		$queue      = new Queue( new Option_Job_Store() );
		$settings   = new Form_Settings();
		$dispatcher = new Dispatcher( $settings, $queue );

		/**
		 * Filters the form plugin bridges.
		 *
		 * @param Bridge[]   $bridges    Bridges, each implementing Profotograaf\Leads\Bridge.
		 * @param Dispatcher $dispatcher Where a bridge hands its submissions.
		 */
		$bridges = apply_filters(
			'profotograaf_lead_bridges',
			array(
				new Contact_Form_7( $dispatcher ),
				new Wpforms( $dispatcher ),
				new Gravity_Forms( $dispatcher ),
			),
			$dispatcher
		);
		$bridges = array_values(
			array_filter(
				is_array( $bridges ) ? $bridges : array(),
				static fn( $bridge ): bool => $bridge instanceof Bridge
			)
		);
		foreach ( $bridges as $bridge ) {
			$bridge->register();
		}

		( new Delivery( $queue, $plugin->api() ) )->register();

		if ( is_admin() ) {
			( new Leads_Settings( $settings, $queue, $bridges, $plugin ) )->register();
		}
	}

	/**
	 * Loads the Dutch strings of the lead settings.
	 *
	 * They live in languages/leads so the lead bridges change no shared file.
	 * WordPress merges a second file into the `profotograaf` text domain.
	 */
	public function load_translations(): void {
		if ( 0 === strpos( determine_locale(), 'nl' ) ) {
			load_textdomain( 'profotograaf', PROFOTOGRAAF_DIR . 'languages/leads/profotograaf-nl_NL.mo' );
		}
	}
}
