<?php
/**
 * WP-CLI: wp profotograaf status.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Cli;

use Profotograaf\Connection;
use Profotograaf\Cron_Health;
use Profotograaf\Leads\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the connection, the lead queue and the cron state.
 */
final class Status_Command {

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Cron health.
	 *
	 * @var Cron_Health
	 */
	private Cron_Health $cron;

	/**
	 * Constructor.
	 *
	 * @param Connection  $connection Connection.
	 * @param Queue       $queue      Queue.
	 * @param Cron_Health $cron       Cron health.
	 */
	public function __construct( Connection $connection, Queue $queue, Cron_Health $cron ) {
		$this->connection = $connection;
		$this->queue      = $queue;
		$this->cron       = $cron;
	}

	/**
	 * Shows whether the site is connected, how many leads wait and whether WP-Cron runs.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp profotograaf status
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function __invoke( array $args = array(), array $assoc_args = array() ): void {
		$counts = $this->queue->counts();
		$cron   = $this->cron->status();
		$last   = $cron['last_run'];
		$rows   = array(
			array(
				'check' => 'connected',
				'value' => $this->connection->is_connected() ? 'yes' : 'no',
			),
			array(
				'check' => 'leads_pending',
				'value' => (string) $counts['pending'],
			),
			array(
				'check' => 'leads_failed',
				'value' => (string) $counts['failed'],
			),
			array(
				'check' => 'wp_cron_disabled',
				'value' => $cron['disabled'] ? 'yes' : 'no',
			),
			array(
				'check' => 'last_delivery_run',
				'value' => null === $last ? 'never' : gmdate( 'Y-m-d H:i:s', $last ) . ' UTC',
			),
			array(
				'check' => 'cron_healthy',
				'value' => $cron['problem'] ? 'no' : 'yes',
			),
		);
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'check', 'value' ) );

		if ( $cron['problem'] ) {
			\WP_CLI::warning( __( 'WP-Cron is not delivering leads. Run `wp profotograaf leads flush` from a system cron.', 'profotograaf' ) );
		}
	}
}
