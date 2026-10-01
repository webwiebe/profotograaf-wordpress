<?php
/**
 * Registers the WP-CLI commands.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Cli;

use Profotograaf\Cron_Health;
use Profotograaf\Leads\Delivery;
use Profotograaf\Leads\Job_Store;
use Profotograaf\Leads\Queue;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds `wp profotograaf status`, `wp profotograaf leads flush` and
 * `wp profotograaf leads list` when WP-CLI runs the request.
 */
final class Registrar {

	/**
	 * Adds the commands. Does nothing outside WP-CLI.
	 *
	 * @param Plugin      $plugin   Service container.
	 * @param Queue       $queue    Lead queue.
	 * @param Job_Store   $store    Job store behind the queue.
	 * @param Delivery    $delivery Lead delivery.
	 * @param Cron_Health $cron     Cron health.
	 */
	public static function register( Plugin $plugin, Queue $queue, Job_Store $store, Delivery $delivery, Cron_Health $cron ): void {
		if ( ! defined( 'WP_CLI' ) || ! constant( 'WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'profotograaf', Command_Namespace::class );
		\WP_CLI::add_command( 'profotograaf status', new Status_Command( $plugin->connection(), $queue, $cron ) );
		\WP_CLI::add_command( 'profotograaf leads', new Leads_Command( $delivery, $queue, $store ) );
	}
}
