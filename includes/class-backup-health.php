<?php
/**
 * Site Health tests for the backup.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use Profotograaf\Admin\Backup_Intro;
use Profotograaf\Admin\Backup_Panel;

defined( 'ABSPATH' ) || exit;

/**
 * Three Site Health tests while the backup is switched on
 * (docs/backup-and-pwa.md, "What the photographer sees"):
 *
 * - Permission: the site is connected and the connection holds
 *   `galleries:write`.
 * - Cron: WP-Cron runs the upload worker (Backup_Status::cron()).
 * - Queue: the storage on Profotograaf is not full, the queue moves
 *   (Backup_Status::stuck()) and no file waits for the photographer.
 */
final class Backup_Health {

	public const PERMISSION_TEST = 'profotograaf_backup_permission';
	public const CRON_TEST       = 'profotograaf_backup_cron';
	public const QUEUE_TEST      = 'profotograaf_backup_queue';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Copy status.
	 *
	 * @var Backup_Status
	 */
	private Backup_Status $status;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings.
	 * @param Backup_Status $status   Copy status.
	 */
	public function __construct( Settings $settings, Backup_Status $status ) {
		$this->settings = $settings;
		$this->status   = $status;
	}

	/**
	 * Adds the tests while the backup is switched on.
	 *
	 * @param mixed $tests Tests by type.
	 * @return mixed
	 */
	public function add_tests( $tests ) {
		if ( ! is_array( $tests ) || ! $this->settings->backup_enabled() ) {
			return $tests;
		}
		$tests['direct'][ self::PERMISSION_TEST ] = array(
			'label' => __( 'Profotograaf copy permission', 'profotograaf' ),
			'test'  => array( $this, 'permission_result' ),
		);
		$tests['direct'][ self::CRON_TEST ]       = array(
			'label' => __( 'Profotograaf copy schedule', 'profotograaf' ),
			'test'  => array( $this, 'cron_result' ),
		);
		$tests['direct'][ self::QUEUE_TEST ]      = array(
			'label' => __( 'Profotograaf copy queue', 'profotograaf' ),
			'test'  => array( $this, 'queue_result' ),
		);
		return $tests;
	}

	/**
	 * Whether the backup may create galleries and send files.
	 *
	 * @return array<string,mixed>
	 */
	public function permission_result(): array {
		if ( ! $this->status->active() ) {
			return self::result( self::PERMISSION_TEST, 'recommended', __( 'The Profotograaf copy waits for a connection', 'profotograaf' ), __( 'Copying is switched on, but this site is not connected to Profotograaf, so nothing is copied.', 'profotograaf' ), self::settings_link() );
		}
		if ( $this->status->needs_permission() ) {
			return self::result( self::PERMISSION_TEST, 'critical', __( 'The Profotograaf copy needs a new permission', 'profotograaf' ), Backup_Intro::reconnect_text( $this->settings, true ), self::settings_link() );
		}
		return self::result( self::PERMISSION_TEST, 'good', __( 'The Profotograaf copy has the permission it needs', 'profotograaf' ), __( 'The connection may create galleries and send files.', 'profotograaf' ) );
	}

	/**
	 * Whether WP-Cron runs the upload worker.
	 *
	 * @return array<string,mixed>
	 */
	public function cron_result(): array {
		$cron = $this->status->cron();
		if ( ! $this->status->active() || $cron['ok'] ) {
			return self::result( self::CRON_TEST, 'good', __( 'WP-Cron runs the Profotograaf copy', 'profotograaf' ), __( 'The copy ran recently, or nothing needs to run yet.', 'profotograaf' ) );
		}
		return self::result(
			self::CRON_TEST,
			$this->status->remaining() > 0 ? 'critical' : 'recommended',
			__( 'WP-Cron does not run the Profotograaf copy', 'profotograaf' ),
			$cron['disabled']
				? __( 'This site sets DISABLE_WP_CRON and no copy run has been seen, so nothing is sent.', 'profotograaf' )
				: __( 'Copying has not run for more than an hour, so WP-Cron does not seem to run on this site.', 'profotograaf' ),
			'<p>' . esc_html__( 'Run WordPress cron from the server. Add this line to the crontab of the site:', 'profotograaf' ) . '</p><p><code>' . esc_html( Cron_Health::cron_line() ) . '</code></p><p>' . esc_html__( 'Or send the files now with WP-CLI: wp profotograaf backup run', 'profotograaf' ) . '</p>'
		);
	}

	/**
	 * Whether the queue moves and no file waits for the photographer.
	 *
	 * @return array<string,mixed>
	 */
	public function queue_result(): array {
		$link = self::backup_link();
		if ( $this->status->active() && 'storage_full' === $this->status->pause()['reason'] ) {
			return self::result( self::QUEUE_TEST, 'critical', __( 'Your Profotograaf storage is full', 'profotograaf' ), __( 'Copying is paused because Profotograaf has no room for more files.', 'profotograaf' ), '<p>' . esc_html__( 'Free up space or upgrade your plan on Profotograaf, then press Copy now on the Copy to Profotograaf tab.', 'profotograaf' ) . '</p>' . $link );
		}
		if ( $this->status->stuck() ) {
			$remaining = $this->status->remaining();
			return self::result(
				self::QUEUE_TEST,
				'recommended',
				__( 'The Profotograaf copy queue is stuck', 'profotograaf' ),
				sprintf(
					/* translators: %d: number of files. */
					_n( '%d file waits and no file was sent for more than six hours.', '%d files wait and no file was sent for more than six hours.', $remaining, 'profotograaf' ),
					$remaining
				),
				'<p>' . esc_html__( 'Press Copy now on the Copy to Profotograaf tab. When nothing changes, read the recent errors under Info, Profotograaf on this screen.', 'profotograaf' ) . '</p>' . $link
			);
		}
		$waiting = $this->status->waiting_for_you();
		if ( $waiting > 0 ) {
			return self::result(
				self::QUEUE_TEST,
				'recommended',
				__( 'Some files could not be copied to Profotograaf', 'profotograaf' ),
				sprintf(
					/* translators: %d: number of files. */
					_n( '%d file could not be copied and waits for you.', '%d files could not be copied and wait for you.', $waiting, 'profotograaf' ),
					$waiting
				),
				'<p>' . esc_html__( 'The Copy to Profotograaf tab lists each file with the reason. Press Retry failed to send them again.', 'profotograaf' ) . '</p>' . $link
			);
		}
		return self::result( self::QUEUE_TEST, 'good', __( 'The Profotograaf copy queue moves', 'profotograaf' ), __( 'Files are sent as planned, or nothing waits.', 'profotograaf' ) );
	}

	/**
	 * A Site Health result array.
	 *
	 * @param string $test        Test id.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Title.
	 * @param string $description Plain text explanation.
	 * @param string $actions     HTML for what to do, already escaped.
	 * @return array<string,mixed>
	 */
	private static function result( string $test, string $status, string $label, string $description, string $actions = '' ): array {
		$colors = array(
			'good'        => 'blue',
			'recommended' => 'orange',
			'critical'    => 'red',
		);
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Profotograaf', 'profotograaf' ),
				'color' => $colors[ $status ],
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => $test,
		);
	}

	/**
	 * Link to the settings page.
	 */
	private static function settings_link(): string {
		return '<p><a href="' . esc_url( Modules\Settings_Page::url() ) . '">' . esc_html__( 'Open the Profotograaf settings', 'profotograaf' ) . '</a></p>';
	}

	/**
	 * Link to the Backup tab.
	 */
	private static function backup_link(): string {
		return '<p><a href="' . esc_url( Backup_Panel::url() ) . '">' . esc_html__( 'Open the copy settings', 'profotograaf' ) . '</a></p>';
	}
}
