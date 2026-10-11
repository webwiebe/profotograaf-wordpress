<?php
/**
 * WP-CLI: wp profotograaf backup.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Cli;

use Profotograaf\Backup_Backfill;
use Profotograaf\Backup_Worker;
use Profotograaf\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the backup in the foreground, for hosts where WP-Cron is slow or off.
 */
final class Backup_Command {

	/** Seconds per worker run in the foreground. */
	private const RUN_BUDGET = 300;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Backfill.
	 *
	 * @var Backup_Backfill
	 */
	private Backup_Backfill $backfill;

	/**
	 * Upload worker.
	 *
	 * @var Backup_Worker
	 */
	private Backup_Worker $worker;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param Backup_Backfill $backfill Backfill.
	 * @param Backup_Worker   $worker   Upload worker.
	 */
	public function __construct( Settings $settings, Backup_Backfill $backfill, Backup_Worker $worker ) {
		$this->settings = $settings;
		$this->backfill = $backfill;
		$this->worker   = $worker;
	}

	/**
	 * Finishes the backfill and sends every queued file until the queue is
	 * empty or Profotograaf asks to stop.
	 *
	 * ## OPTIONS
	 *
	 * [--resume]
	 * : Lift a pause first, for example after you freed storage on Profotograaf.
	 *
	 * ## EXAMPLES
	 *
	 *     wp profotograaf backup run
	 *     wp profotograaf backup run --resume
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function run( array $args = array(), array $assoc_args = array() ): void {
		if ( ! $this->settings->backup_enabled() ) {
			\WP_CLI::warning( __( 'The backup is switched off. Switch it on under Settings > Profotograaf > Backup.', 'profotograaf' ) );
			return;
		}
		if ( isset( $assoc_args['resume'] ) ) {
			$this->worker->resume();
		}

		$checked = 0;
		do {
			$checked += $this->backfill->run();
		} while ( ! $this->backfill->status()['complete'] );
		/* translators: %d: number of Media Library items. */
		\WP_CLI::log( sprintf( _n( 'Checked %d Media Library item.', 'Checked %d Media Library items.', $checked, 'profotograaf' ), $checked ) );

		$sent    = 0;
		$missing = 0;
		do {
			$result   = $this->worker->run( self::RUN_BUDGET );
			$sent    += $result['sent'];
			$missing  = 'gallery_missing' === $result['stop'] ? $missing + 1 : 0;
			$continue = in_array( $result['stop'], array( 'budget', 'limit' ), true ) || 1 === $missing;
		} while ( $continue );

		/* translators: %d: number of files. */
		$summary = sprintf( _n( 'Sent %d file to Profotograaf.', 'Sent %d files to Profotograaf.', $sent, 'profotograaf' ), $sent );
		$problem = $this->explain( $result['stop'] );
		if ( '' === $problem ) {
			\WP_CLI::success( $summary );
			return;
		}
		\WP_CLI::warning( $summary . ' ' . $problem );
	}

	/**
	 * Why the run stopped early, empty when the queue is empty.
	 *
	 * @param string $stop Stop reason from Backup_Worker::run().
	 */
	private function explain( string $stop ): string {
		switch ( $stop ) {
			case 'empty':
				return '';
			case 'not_connected':
				return __( 'This site is not connected to Profotograaf.', 'profotograaf' );
			case 'reconnect':
				return __( 'The backup needs a new permission. Connect this site again under Settings > Profotograaf.', 'profotograaf' );
			case 'storage_full':
				return __( 'Your Profotograaf storage is full, so the backup is paused. Free up space or upgrade your plan, then run this command with --resume.', 'profotograaf' );
			case 'paused':
				return __( 'The backup is paused. Run this command with --resume to try again now.', 'profotograaf' );
			case 'backoff':
				return __( 'Profotograaf did not accept more files for now. The backup tries again later on its own.', 'profotograaf' );
			case 'busy':
				return __( 'Another backup run is in progress.', 'profotograaf' );
			case 'memory':
				return __( 'This site ran low on memory. Run the command again to send the rest.', 'profotograaf' );
			default:
				return __( 'The backup stopped early. Run the command again to send the rest.', 'profotograaf' );
		}
	}
}
