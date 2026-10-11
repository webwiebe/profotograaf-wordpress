<?php
/**
 * WP-CLI: wp profotograaf virtual.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Cli;

use Profotograaf\Virtual_Bulk_Convert;

defined( 'ABSPATH' ) || exit;

/**
 * Converts photos kept on Profotograaf to local from the command line, for
 * large libraries and for hosts where WP-Cron does not run (issue #158).
 */
final class Virtual_Command {

	/**
	 * Bulk conversion.
	 *
	 * @var Virtual_Bulk_Convert
	 */
	private Virtual_Bulk_Convert $job;

	/**
	 * Constructor.
	 *
	 * @param Virtual_Bulk_Convert $job Bulk conversion.
	 */
	public function __construct( Virtual_Bulk_Convert $job ) {
		$this->job = $job;
	}

	/**
	 * Downloads every photo kept on Profotograaf into the Media Library, in
	 * batches, so the images keep working after the plugin is removed.
	 *
	 * Run it as a user who may upload files, with the global --user flag.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Photos per batch.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--dry-run]
	 * : Only count the photos kept on Profotograaf.
	 *
	 * ## EXAMPLES
	 *
	 *     wp profotograaf virtual convert --user=admin
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function convert( array $args = array(), array $assoc_args = array() ): void {
		$count = Virtual_Bulk_Convert::count();
		/* translators: %d: number of photos. */
		\WP_CLI::log( sprintf( _n( '%d photo is kept on Profotograaf.', '%d photos are kept on Profotograaf.', $count, 'profotograaf' ), $count ) );
		if ( isset( $assoc_args['dry-run'] ) || 0 === $count ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			\WP_CLI::error( __( 'Run this command as a user who may upload files, for example with --user=admin.', 'profotograaf' ) );
			return;
		}
		$size      = max( 1, (int) ( $assoc_args['batch-size'] ?? 20 ) );
		$skip      = array();
		$converted = 0;
		do {
			$batch      = $this->job->convert_batch( $skip, $size );
			$converted += $batch['converted'];
			$skip       = array_merge( $skip, $batch['failed_ids'] );
			foreach ( $batch['failed'] as $row ) {
				\WP_CLI::warning( $row['title'] . ': ' . $row['message'] );
			}
			if ( array() !== $batch['ids'] ) {
				/* translators: 1: photos converted so far, 2: photos that failed so far. */
				\WP_CLI::log( sprintf( __( 'Converted %1$d, failed %2$d.', 'profotograaf' ), $converted, count( $skip ) ) );
			}
		} while ( array() !== $batch['ids'] );

		if ( array() === $skip ) {
			/* translators: %d: number of photos. */
			\WP_CLI::success( sprintf( _n( '%d photo converted to local.', '%d photos converted to local.', $converted, 'profotograaf' ), $converted ) );
			return;
		}
		/* translators: 1: photos converted, 2: photos that stay kept on Profotograaf. */
		\WP_CLI::warning( sprintf( __( '%1$d converted, %2$d could not be converted and stay kept on Profotograaf.', 'profotograaf' ), $converted, count( $skip ) ) );
	}
}
