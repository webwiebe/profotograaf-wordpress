<?php
/**
 * Settings > Profotograaf > Backup, the status below the switch and scope.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Admin;

use Profotograaf\Backup_Backfill;
use Profotograaf\Backup_Eligibility;
use Profotograaf\Backup_Labels;
use Profotograaf\Backup_State;
use Profotograaf\Backup_Status;
use Profotograaf\Cron_Health;
use Profotograaf\Modules\Backup;
use Profotograaf\Modules\Settings_Page;
use Profotograaf\Plugin;
use Profotograaf\Settings_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Shows where the backup stands while it is switched on: how many files are
 * backed up and how many wait, when the worker last ran and whether WP-Cron
 * runs it, a pause, the bytes still to send, "Copy now", "Retry failed"
 * and the files that wait for the photographer, with the reason.
 *
 * The buttons post to the admin-post actions of Modules\Backup.
 */
class Backup_Panel {

	/** Files listed in the failure list at most. */
	public const FAILURES_SHOWN = 20;

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Backup state.
	 *
	 * @var Backup_State
	 */
	private Backup_State $state;

	/**
	 * Copy status.
	 *
	 * @var Backup_Status
	 */
	private Backup_Status $status;

	/**
	 * Constructor.
	 *
	 * @param Plugin             $plugin Service container.
	 * @param Backup_Status|null $status Copy status, built from the plugin by default.
	 */
	public function __construct( Plugin $plugin, ?Backup_Status $status = null ) {
		$this->plugin = $plugin;
		$this->state  = new Backup_State( $plugin->clock() );
		$this->status = $status ?? new Backup_Status( $plugin->settings(), $plugin->connection(), $this->state, $plugin->clock() );
	}

	/**
	 * Prints the panel on the Backup tab while the backup is switched on.
	 *
	 * @param string $tab The tab shown.
	 */
	public function render( string $tab ): void {
		if ( Settings_Schema::TAB_BACKUP !== $tab || ! $this->plugin->settings()->backup_enabled() ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Copy status', 'profotograaf' ) . '</h2><div class="profotograaf-backup-status">';
		if ( ! $this->plugin->connection()->is_connected() ) {
			printf(
				'<p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Nothing is copied while this site is not connected to Profotograaf.', 'profotograaf' ),
				esc_url( Settings_Page::url() ),
				esc_html__( 'Connect this site', 'profotograaf' )
			);
			return;
		}
		if ( $this->status->needs_permission() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html( Backup_Intro::reconnect_text( $this->plugin->settings(), true ) ),
				esc_url( Settings_Page::url() ),
				esc_html__( 'Connect this site again', 'profotograaf' )
			);
		}
		echo '<p class="profotograaf-backup-status__line">' . esc_html( implode( ' ', $this->summary() ) ) . '</p>';
		foreach ( $this->problems() as $problem ) {
			echo '<div class="notice notice-warning inline"><p>' . $problem . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in problems().
		}
		$this->render_bytes();
		echo '<div class="profotograaf-backup-status__actions">';
		self::render_button( Backup::NOW, __( 'Copy now', 'profotograaf' ), 'primary' );
		if ( $this->status->waiting_for_you() > 0 ) {
			self::render_button( Backup::RETRY, __( 'Retry failed', 'profotograaf' ), 'secondary' );
		}
		echo '</div>';
		$this->render_failures();
		echo '</div>';
	}

	/**
	 * The status line, sentence by sentence: files backed up, files that
	 * wait, files that wait for the photographer, skipped files and the last run.
	 *
	 * @return array<int,string>
	 */
	public function summary(): array {
		$counts = $this->status->counts();
		$total  = $this->status->total();
		$lines  = array();
		if ( ! ( new Backup_Backfill( new Backup_Eligibility( $this->plugin->settings(), $this->state ) ) )->status()['complete'] ) {
			$lines[] = __( 'Still looking through the Media Library for files to copy.', 'profotograaf' );
		}
		$lines[] = sprintf(
			/* translators: 1: number of files backed up, 2: number of files in the backup. */
			_n( '%1$s of %2$s file copied.', '%1$s of %2$s files copied.', $total, 'profotograaf' ),
			number_format_i18n( $counts[ Backup_State::DONE ] ),
			number_format_i18n( $total )
		);
		$remaining = $this->status->remaining();
		if ( $remaining > 0 ) {
			/* translators: %s: number of files. */
			$lines[] = sprintf( _n( '%s file waits to be sent.', '%s files wait to be sent.', $remaining, 'profotograaf' ), number_format_i18n( $remaining ) );
		}
		$waiting = $this->status->waiting_for_you();
		if ( $waiting > 0 ) {
			/* translators: %s: number of files. */
			$lines[] = sprintf( _n( '%s file waits for you, see the list below.', '%s files wait for you, see the list below.', $waiting, 'profotograaf' ), number_format_i18n( $waiting ) );
		}
		$skipped = $counts[ Backup_State::SKIPPED ];
		if ( $skipped > 0 ) {
			/* translators: %s: number of files. */
			$lines[] = sprintf( _n( '%s file is not copied. The Copy column in the Media Library list says why.', '%s files are not copied. The Copy column in the Media Library list says why.', $skipped, 'profotograaf' ), number_format_i18n( $skipped ) );
		}
		$last    = $this->status->last_run()['at'];
		$lines[] = $last > 0
			/* translators: %s: time since the last run, for example "4 minutes". */
			? sprintf( __( 'Last run %s ago.', 'profotograaf' ), human_time_diff( $last, (int) ( $this->plugin->clock() )() ) )
			: __( 'The first run starts within a few minutes.', 'profotograaf' );
		return $lines;
	}

	/**
	 * What keeps the backup from sending, as escaped HTML paragraphs: a pause,
	 * no working WP-Cron, or a queue that does not move.
	 *
	 * @return array<int,string>
	 */
	public function problems(): array {
		$problems = array();
		$pause    = $this->status->pause();
		$when     = '' === $pause['reason'] ? '' : (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pause['until'] );
		if ( 'storage_full' === $pause['reason'] ) {
			/* translators: %s: date and time. */
			$problems[] = esc_html( sprintf( __( 'Your Profotograaf storage is full, so copying is paused until %s. Free up space or upgrade your plan, then press Copy now.', 'profotograaf' ), $when ) );
		} elseif ( 'backoff' === $pause['reason'] ) {
			/* translators: %s: date and time. */
			$problems[] = esc_html( sprintf( __( 'Profotograaf asked copying to wait. It tries again on its own at %s.', 'profotograaf' ), $when ) );
		}
		$cron = $this->status->cron();
		if ( ! $cron['ok'] ) {
			$problems[] = esc_html(
				$cron['disabled']
					? __( 'This site sets DISABLE_WP_CRON and no copy run has been seen, so nothing is sent.', 'profotograaf' )
					: __( 'Copying has not run for more than an hour, so WP-Cron does not seem to run on this site.', 'profotograaf' )
			) . ' ' . esc_html__( 'Run WordPress cron from the server with this crontab line:', 'profotograaf' ) . ' <code>' . esc_html( Cron_Health::cron_line() ) . '</code>';
		}
		if ( $this->status->stuck() ) {
			$problems[] = esc_html__( 'No file was sent for more than six hours while files wait. Press Copy now to try again, and check Tools > Site Health.', 'profotograaf' );
		}
		return $problems;
	}

	/**
	 * Prints the bytes still to send.
	 */
	private function render_bytes(): void {
		if ( $this->status->counts()[ Backup_State::QUEUED ] < 1 ) {
			return;
		}
		$bytes = $this->status->bytes_queued( new Backup_Eligibility( $this->plugin->settings(), $this->state ) );
		$size  = (string) size_format( $bytes['bytes'], 1 );
		echo '<p>' . esc_html(
			$bytes['partial']
				/* translators: %s: size, for example "1.2 GB". */
				? sprintf( __( 'More than %s waits to be sent. The copies count towards your Profotograaf storage.', 'profotograaf' ), $size )
				/* translators: %s: size, for example "1.2 GB". */
				: sprintf( __( '%s waits to be sent. The copies count towards your Profotograaf storage.', 'profotograaf' ), $size )
		) . '</p>';
	}

	/**
	 * Prints the files that wait for the photographer.
	 */
	private function render_failures(): void {
		$ids = $this->status->failures( self::FAILURES_SHOWN );
		if ( array() === $ids ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Files that wait for you', 'profotograaf' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Retry failed sends these files again. A file whose copy is not confirmed may then be on Profotograaf twice.', 'profotograaf' ) . '</p>';
		echo '<table class="widefat striped profotograaf-backup-failures"><thead><tr>';
		foreach ( array( __( 'File', 'profotograaf' ), __( 'State', 'profotograaf' ), __( 'Reason', 'profotograaf' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$label = Backup_Labels::of( $this->state, $id );
			$title = get_the_title( $id );
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$s</td></tr>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( '' !== $title ? $title : '#' . $id ),
				esc_html( $label['label'] ),
				esc_html( $label['reason'] )
			);
		}
		echo '</tbody></table>';
		$more = $this->status->waiting_for_you() - count( $ids );
		if ( $more > 0 ) {
			/* translators: %s: number of files. */
			echo '<p class="description">' . esc_html( sprintf( _n( 'And %s more file.', 'And %s more files.', $more, 'profotograaf' ), number_format_i18n( $more ) ) ) . '</p>';
		}
	}

	/**
	 * Prints a one button form that posts to admin-post.php with a nonce.
	 *
	 * @param string $action Action name, also the nonce action.
	 * @param string $label  Button label.
	 * @param string $type   Button type: primary or secondary.
	 */
	private static function render_button( string $action, string $label, string $type ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="profotograaf-action">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		submit_button( $label, $type, 'submit', false );
		echo '</form>';
	}

	/**
	 * URL of the Backup tab.
	 */
	public static function url(): string {
		return add_query_arg( 'tab', Settings_Schema::TAB_BACKUP, Settings_Page::url() );
	}
}
