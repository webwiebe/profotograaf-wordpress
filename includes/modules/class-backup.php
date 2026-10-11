<?php
/**
 * Copy to Profotograaf: the producers and the schedule.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Admin\Backup_Panel;
use Profotograaf\Backup_Backfill;
use Profotograaf\Backup_Eligibility;
use Profotograaf\Backup_Galleries;
use Profotograaf\Backup_State;
use Profotograaf\Backup_Status;
use Profotograaf\Backup_Usage;
use Profotograaf\Backup_Worker;
use Profotograaf\Cli\Backup_Command;
use Profotograaf\Module;
use Profotograaf\Plugin;
use Profotograaf\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the backup (docs/backup-and-pwa.md) into WordPress.
 *
 * Producers fill the queue; only WP-Cron and WP-CLI empty it:
 *
 * - The backfill (Backup_Backfill) walks the existing Media Library when the
 *   backup is switched on or its scope changes.
 * - `add_attachment` queues a new upload when the scope is `all`.
 * - Publishing or updating published content schedules a background check
 *   of the files it uses when the scope is `used`. The check runs in WP-Cron,
 *   so saving a post never walks the site.
 * - Backup_Worker sends the queue every five minutes. Each run records a
 *   heartbeat in Backup_Status.
 *
 * The Backup tab offers "Copy now" and "Retry failed" (admin-post
 * actions NOW and RETRY). Both only change state and move the next upload
 * run forward; the upload itself happens in WP-Cron, never in the page
 * request.
 *
 * While the backup is off, or the site is not connected, no event is
 * scheduled and nothing is queued or sent. Switching it off keeps the state
 * on each file, so switching it on again goes on from there.
 */
class Backup implements Module {

	/** Background check of the files one post uses. */
	public const SCAN_HOOK = 'profotograaf_backup_scan_post';

	/** Seconds before the first upload run after the backup is switched on. */
	public const FIRST_RUN = 60;

	/** The admin-post action of "Copy now". */
	public const NOW = 'profotograaf_backup_now';

	/** The admin-post action of "Retry failed". */
	public const RETRY = 'profotograaf_backup_retry';

	/** Files "Retry failed" puts back in the queue per click at most. */
	public const RETRY_MAX = 1000;

	/**
	 * Plugin container.
	 *
	 * @var Plugin|null
	 */
	private ?Plugin $plugin = null;

	/**
	 * Built services.
	 *
	 * @var array{state:Backup_State,eligibility:Backup_Eligibility,backfill:Backup_Backfill,worker:Backup_Worker}|null
	 */
	private ?array $services = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- five minutes is the documented upload interval.
		add_action( Backup_Worker::HOOK, array( $this, 'run_worker' ) );
		add_action( Backup_Backfill::HOOK, array( $this, 'run_backfill' ) );
		add_action( self::SCAN_HOOK, array( $this, 'scan_post' ) );
		add_action( 'init', array( $this, 'sync_schedule' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'settings_added' ), 10, 2 );
		add_action( 'profotograaf_connected', array( $this, 'on_connected' ) );
		add_action( 'profotograaf_disconnected', array( $this, 'unschedule' ) );
		add_action( 'add_attachment', array( $this, 'on_new_attachment' ) );
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'admin_post_' . self::NOW, array( $this, 'handle_now' ) );
		add_action( 'admin_post_' . self::RETRY, array( $this, 'handle_retry' ) );
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'profotograaf backup', new Backup_Command( $plugin->settings(), $this->backfill(), $this->worker() ) );
		}
	}

	/**
	 * Adds the five minute WP-Cron schedule.
	 *
	 * @param mixed $schedules Schedules.
	 * @return array<string,mixed>
	 */
	public function add_schedule( $schedules ): array {
		$schedules                            = is_array( $schedules ) ? $schedules : array();
		$schedules[ Backup_Worker::SCHEDULE ] = array(
			'interval' => Backup_Worker::INTERVAL,
			'display'  => __( 'Every five minutes (Profotograaf copy)', 'profotograaf' ),
		);
		return $schedules;
	}

	/**
	 * Cron: one upload run.
	 */
	public function run_worker(): void {
		Backup_Status::record_run( $this->worker()->run(), $this->now() );
	}

	/**
	 * "Copy now": lifts a pause, goes on with an unfinished walk of the
	 * Media Library and moves the next upload run to now. WP-Cron starts it
	 * on the next request, so this request sends nothing.
	 *
	 * @return bool False when the backup is off or the site is not connected.
	 */
	public function back_up_now(): bool {
		if ( ! $this->active() ) {
			return false;
		}
		$this->worker()->resume();
		$this->backfill()->resume();
		wp_clear_scheduled_hook( Backup_Worker::HOOK );
		wp_schedule_event( $this->now(), Backup_Worker::SCHEDULE, Backup_Worker::HOOK );
		return true;
	}

	/**
	 * "Retry failed": puts the failed files and the ones that may be on
	 * Profotograaf back in the queue, then backs up now.
	 *
	 * @return int How many files went back in the queue.
	 */
	public function retry_failed(): int {
		$state = $this->services()['state'];
		$count = 0;
		foreach ( array( Backup_State::FAILED, Backup_State::NEEDS_CHECK ) as $from ) {
			do {
				$ids = $count < self::RETRY_MAX ? $state->in_state( $from, min( 100, self::RETRY_MAX - $count ) ) : array();
				foreach ( $ids as $id ) {
					$state->queue( $id );
					++$count;
				}
			} while ( array() !== $ids );
		}
		$this->back_up_now();
		return $count;
	}

	/**
	 * Handles "Copy now".
	 */
	public function handle_now(): void {
		$this->authorize( self::NOW );
		$this->notice(
			$this->back_up_now()
				? __( 'Copying starts within a minute, in the background. You can leave this page.', 'profotograaf' )
				: __( 'Copying cannot start: switch it on and connect this site to Profotograaf first.', 'profotograaf' )
		);
		$this->finish();
	}

	/**
	 * Handles "Retry failed".
	 */
	public function handle_retry(): void {
		$this->authorize( self::RETRY );
		$count = $this->retry_failed();
		$this->notice(
			sprintf(
				/* translators: %d: number of files. */
				_n( '%d file will be sent again in the background.', '%d files will be sent again in the background.', $count, 'profotograaf' ),
				$count
			)
		);
		$this->finish();
	}

	/**
	 * Requires manage_options and a valid nonce, or stops the request.
	 *
	 * @param string $action Nonce action.
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( Settings_Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Leaves a message for the settings page, like Settings_Page does.
	 *
	 * @param string $message Text.
	 */
	private function notice( string $message ): void {
		set_transient(
			'profotograaf_notice_' . get_current_user_id(),
			array(
				'type'    => 'success',
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Sends the browser back to the Backup tab.
	 */
	protected function finish(): void {
		wp_safe_redirect( Backup_Panel::url() );
		exit;
	}

	/**
	 * Cron: one backfill batch.
	 */
	public function run_backfill(): void {
		if ( $this->active() ) {
			$this->backfill()->run();
		}
	}

	/**
	 * Cron: queues the files a published post uses, for the scope `used`.
	 *
	 * @param int|string $post_id Post id.
	 */
	public function scan_post( $post_id ): void {
		if ( ! $this->active() || 'used' !== $this->plugin()->settings()->backup_scope() ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		Backup_Usage::forget();
		foreach ( ( new Backup_Usage() )->in_post( $post ) as $id ) {
			$this->eligibility()->apply( $id );
		}
	}

	/**
	 * Schedules the upload run while the backup is on and the site is
	 * connected, and removes every backup event otherwise.
	 */
	public function sync_schedule(): void {
		if ( ! $this->active() ) {
			$this->unschedule();
			return;
		}
		if ( ! wp_next_scheduled( Backup_Worker::HOOK ) ) {
			wp_schedule_event( $this->now() + self::FIRST_RUN, Backup_Worker::SCHEDULE, Backup_Worker::HOOK );
		}
	}

	/**
	 * After the settings were saved: starts the backfill when the backup was
	 * switched on or its scope changed, and fixes the schedule.
	 *
	 * @param mixed $old_value Settings before.
	 * @param mixed $new_value Settings after.
	 */
	public function settings_changed( $old_value, $new_value ): void {
		$was = self::backup_values( $old_value );
		$now = self::backup_values( $new_value );
		if ( $now['on'] && ( ! $was['on'] || $was['scope'] !== $now['scope'] ) ) {
			// Switching on is "Copy now": a pause from an earlier full storage is lifted too.
			$this->worker()->resume();
			// Without a connection the first batch does nothing, and a later pairing resumes the walk.
			$this->backfill()->restart();
		}
		$this->sync_schedule();
	}

	/**
	 * After the settings were stored through add_option(). update_option() takes
	 * that path too while the stored settings equal the registered defaults, so
	 * switching the backup on from untouched settings arrives here and never
	 * fires update_option_profotograaf_settings.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $new_value Settings after.
	 */
	public function settings_added( $option, $new_value ): void {
		unset( $option );
		$this->settings_changed( array(), $new_value );
	}

	/**
	 * After a pairing: the new token may hold galleries:write, so the backup
	 * goes on.
	 */
	public function on_connected(): void {
		if ( ! $this->plugin()->settings()->backup_enabled() ) {
			return;
		}
		$this->worker()->resume();
		$this->backfill()->resume();
		$this->sync_schedule();
	}

	/**
	 * Removes every scheduled backup event.
	 */
	public function unschedule(): void {
		wp_clear_scheduled_hook( Backup_Worker::HOOK );
		wp_clear_scheduled_hook( Backup_Backfill::HOOK );
		wp_unschedule_hook( self::SCAN_HOOK );
	}

	/**
	 * Queues a new upload when the scope is `all`. Under `used` a file joins
	 * once published content shows it, under `marked` once it is marked.
	 *
	 * @param int|string $attachment_id Attachment id.
	 */
	public function on_new_attachment( $attachment_id ): void {
		if ( $this->active() && 'all' === $this->plugin()->settings()->backup_scope() ) {
			$this->eligibility()->apply( (int) $attachment_id );
		}
	}

	/**
	 * Schedules the background check of a published post's files.
	 *
	 * @param int|string $post_id Post id.
	 * @param mixed      $post    Post.
	 */
	public function on_save_post( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || in_array( $post->post_type, array( 'attachment', 'revision' ), true ) ) {
			return;
		}
		if ( ! $this->active() || 'used' !== $this->plugin()->settings()->backup_scope() ) {
			return;
		}
		$args = array( (int) $post_id );
		if ( ! wp_next_scheduled( self::SCAN_HOOK, $args ) ) {
			wp_schedule_single_event( $this->now() + self::FIRST_RUN, self::SCAN_HOOK, $args );
		}
	}

	/**
	 * The upload worker.
	 */
	public function worker(): Backup_Worker {
		return $this->services()['worker'];
	}

	/**
	 * The backfill.
	 */
	public function backfill(): Backup_Backfill {
		return $this->services()['backfill'];
	}

	/**
	 * The eligibility rules.
	 */
	private function eligibility(): Backup_Eligibility {
		return $this->services()['eligibility'];
	}

	/**
	 * Builds the services once.
	 *
	 * @return array{state:Backup_State,eligibility:Backup_Eligibility,backfill:Backup_Backfill,worker:Backup_Worker}
	 */
	private function services(): array {
		if ( null === $this->services ) {
			$plugin         = $this->plugin();
			$clock          = $plugin->clock();
			$state          = new Backup_State( $clock );
			$eligibility    = new Backup_Eligibility( $plugin->settings(), $state );
			$this->services = array(
				'state'       => $state,
				'eligibility' => $eligibility,
				'backfill'    => new Backup_Backfill( $eligibility, $clock ),
				'worker'      => new Backup_Worker( $plugin->settings(), $plugin->connection(), $plugin->api(), $eligibility, $state, new Backup_Galleries( $plugin->api() ), $clock ),
			);
		}
		return $this->services;
	}

	/**
	 * Whether the backup is on and the site connected.
	 */
	private function active(): bool {
		return $this->plugin()->settings()->backup_enabled() && $this->plugin()->connection()->is_connected();
	}

	/**
	 * The backup switch and scope from a stored settings value.
	 *
	 * @param mixed $value Stored settings.
	 * @return array{on:bool,scope:string}
	 */
	private static function backup_values( $value ): array {
		$value = is_array( $value ) ? $value : array();
		return array(
			'on'    => true === filter_var( $value['backup'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ),
			'scope' => isset( $value['backup_scope'] ) && is_string( $value['backup_scope'] ) ? $value['backup_scope'] : Settings::BACKUP_SCOPE_DEFAULT,
		);
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->plugin()->clock() )();
	}

	/**
	 * The plugin container.
	 */
	private function plugin(): Plugin {
		return $this->plugin ?? Plugin::instance();
	}
}
