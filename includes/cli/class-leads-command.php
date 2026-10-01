<?php
/**
 * WP-CLI: wp profotograaf leads.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Cli;

use Profotograaf\Leads\Delivery;
use Profotograaf\Leads\Job_Store;
use Profotograaf\Leads\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers and lists queued leads from the command line, for hosts where
 * WP-Cron does not run.
 */
final class Leads_Command {

	private const MAX_PASSES = 100;

	/**
	 * Delivery.
	 *
	 * @var Delivery
	 */
	private Delivery $delivery;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Job store.
	 *
	 * @var Job_Store
	 */
	private Job_Store $store;

	/**
	 * Constructor.
	 *
	 * @param Delivery  $delivery Delivery.
	 * @param Queue     $queue    Queue.
	 * @param Job_Store $store    Job store behind the queue.
	 */
	public function __construct( Delivery $delivery, Queue $queue, Job_Store $store ) {
		$this->delivery = $delivery;
		$this->queue    = $queue;
		$this->store    = $store;
	}

	/**
	 * Delivers the leads that are due and prints a summary.
	 *
	 * ## OPTIONS
	 *
	 * [--retry-failed]
	 * : Put failed leads back in line first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp profotograaf leads flush
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function flush( array $args = array(), array $assoc_args = array() ): void {
		if ( isset( $assoc_args['retry-failed'] ) ) {
			$requeued = $this->queue->retry_failed();
			/* translators: %d: number of failed leads put back in line. */
			\WP_CLI::log( sprintf( _n( 'Requeued %d failed lead.', 'Requeued %d failed leads.', $requeued, 'profotograaf' ), $requeued ) );
		}

		$total = array(
			'sent'   => 0,
			'retry'  => 0,
			'failed' => 0,
		);
		for ( $pass = 0; $pass < self::MAX_PASSES; ++$pass ) {
			if ( array() === $this->queue->due( 1 ) ) {
				break;
			}
			$result = $this->delivery->run();
			if ( 0 === array_sum( $result ) ) {
				\WP_CLI::warning( __( 'Delivery is already running elsewhere, or nothing could be sent.', 'profotograaf' ) );
				break;
			}
			foreach ( $result as $key => $count ) {
				$total[ $key ] += $count;
			}
		}

		$counts = $this->queue->counts();
		\WP_CLI::success(
			sprintf(
				/* translators: 1: leads delivered, 2: leads that will be retried, 3: leads that failed for good, 4: leads still pending. */
				__( 'Delivered %1$d, retrying %2$d, failed %3$d. %4$d pending.', 'profotograaf' ),
				$total['sent'],
				$total['retry'],
				$total['failed'],
				$counts['pending']
			)
		);
	}

	/**
	 * Lists queued leads without personal data.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only leads with this status.
	 * ---
	 * options:
	 *   - pending
	 *   - failed
	 * ---
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
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp profotograaf leads list --status=failed
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function list_( array $args = array(), array $assoc_args = array() ): void {
		$status = $assoc_args['status'] ?? '';
		$rows   = array();
		foreach ( $this->store->all() as $id => $job ) {
			if ( '' !== $status && ( $job['status'] ?? '' ) !== $status ) {
				continue;
			}
			$rows[] = array(
				'id'          => substr( (string) $id, 0, 8 ),
				'status'      => (string) ( $job['status'] ?? '' ),
				'form'        => (string) ( $job['payload']['source_form'] ?? '' ),
				'attempts'    => (int) ( $job['attempts'] ?? 0 ),
				'next_at'     => $this->time( (int) ( $job['next_at'] ?? 0 ) ),
				'last_status' => (int) ( $job['last_status'] ?? 0 ),
				'last_error'  => (string) ( $job['last_error'] ?? '' ),
			);
		}
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'status', 'form', 'attempts', 'next_at', 'last_status', 'last_error' ) );
	}

	/**
	 * Formats a unix time, empty for 0.
	 *
	 * @param int $at Unix time.
	 */
	private function time( int $at ): string {
		return $at > 0 ? gmdate( 'Y-m-d H:i:s', $at ) : '';
	}
}
