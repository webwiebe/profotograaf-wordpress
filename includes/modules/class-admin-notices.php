<?php
/**
 * Admin notices for a revoked connection, a failing token refresh, failed
 * leads and a backup that needs the photographer.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Admin\Backup_Panel;
use Profotograaf\Admin\Leads_Settings;
use Profotograaf\Backup_Status;
use Profotograaf\Connection;
use Profotograaf\Leads\Option_Job_Store;
use Profotograaf\Leads\Queue;
use Profotograaf\Module;
use Profotograaf\Plugin;
use Profotograaf\Sync_Attention;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a notice on every admin screen to users who can manage options, so a
 * problem is seen before the owner opens a settings page.
 *
 * - Revoked: the platform ended the connection.
 * - Refresh: the background token refresh keeps failing.
 * - Leads: enquiries failed for good and wait on this site.
 * - Leads reconnect: the connection lacks the permission to send enquiries.
 * - Leads waiting: enquiries are queued and the site is not connected.
 * - Backup storage: the backup is paused because the storage on Profotograaf
 *   is full.
 * - Backup failures: files could not be copied and wait for the
 *   photographer to retry them.
 *
 * Other backup states (waiting, a short backoff, no cron) need no action
 * here or show on the Backup tab and in Site Health only. A missing
 * permission has the reconnect notice of Settings_Page.
 *
 * Each notice has one link to the screen that fixes it, and is left out on
 * that screen. A user dismisses a notice for themselves (user meta). The
 * dismissal stores a signature of the state, so a new revocation, a new run of
 * refresh failures or another failed enquiry shows the notice again. A notice
 * disappears as soon as its state recovers.
 */
class Admin_Notices implements Module {

	public const REVOKED = 'revoked';
	public const REFRESH = 'refresh';
	public const LEADS   = 'leads';
	public const SYNC    = 'sync';

	public const LEADS_RECONNECT = 'leads_reconnect';
	public const LEADS_WAITING   = 'leads_waiting';
	public const LEADS_NEW_FORMS = 'leads_new_forms';

	public const BACKUP_STORAGE = 'backup_storage';
	public const BACKUP_FAILED  = 'backup_failed';

	public const EPISODES_OPTION = 'profotograaf_notice_episodes';
	public const DISMISS_META    = 'profotograaf_dismissed_notices';
	public const DISMISS_ACTION  = 'profotograaf_dismiss_notice';

	/**
	 * Plugin container.
	 *
	 * @var Plugin|null
	 */
	private ?Plugin $plugin = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'profotograaf_disconnected', array( $this, 'on_disconnected' ) );
		add_action( 'profotograaf_connected', array( $this, 'on_connected' ) );
		add_action( 'profotograaf_refresh_failed', array( $this, 'on_refresh_failed' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Starts a revoked episode when the platform ended the connection. Any
	 * disconnect ends the refresh episode.
	 *
	 * @param string $reason `user` or `revoked`.
	 */
	public function on_disconnected( $reason = '' ): void {
		$episodes = $this->episodes();
		unset( $episodes[ self::REFRESH ], $episodes[ self::REVOKED ] );
		if ( 'revoked' === $reason ) {
			$episodes[ self::REVOKED ] = array( 'since' => $this->now() );
		}
		$this->save_episodes( $episodes );
	}

	/**
	 * A new connection ends the revoked and refresh episodes.
	 */
	public function on_connected(): void {
		$episodes = $this->episodes();
		unset( $episodes[ self::REFRESH ], $episodes[ self::REVOKED ] );
		$this->save_episodes( $episodes );
	}

	/**
	 * Remembers the first failed refresh of a run of failures. The expiry of
	 * the access token at that moment shows later whether a refresh succeeded,
	 * because a successful refresh stores a new expiry.
	 */
	public function on_refresh_failed(): void {
		$episodes = $this->episodes();
		if ( isset( $episodes[ self::REFRESH ] ) ) {
			return;
		}
		$episodes[ self::REFRESH ] = array(
			'since'      => $this->now(),
			'expires_at' => $this->connection()->access_expires_at(),
		);
		$this->save_episodes( $episodes );
	}

	/**
	 * Notices whose state is active now, by kind.
	 *
	 * @return array<string,array{signature:string,message:string,link:string,label:string,screen:string,only?:array<int,string>}>
	 */
	public function active(): array {
		$notices    = array();
		$connection = $this->connection();
		$episodes   = $this->episodes();
		$settings   = Settings_Page::url();

		if ( 'revoked' === $connection->status()['state'] ) {
			$notices[ self::REVOKED ] = array(
				'signature' => 'revoked:' . (int) ( $episodes[ self::REVOKED ]['since'] ?? 0 ),
				'message'   => __( 'Profotograaf is no longer connected. The connection was ended in your Profotograaf account.', 'profotograaf' ),
				'link'      => $settings,
				'label'     => __( 'Connect this site again', 'profotograaf' ),
				'screen'    => 'settings_page_' . Settings_Page::SLUG,
			);
		}

		if ( $connection->is_connected() && $this->refresh_failing( $episodes ) ) {
			$notices[ self::REFRESH ] = array(
				'signature' => 'refresh:' . (int) $episodes[ self::REFRESH ]['since'],
				'message'   => __( 'Profotograaf could not renew the connection. Galleries and enquiries stop working when it runs out.', 'profotograaf' ),
				'link'      => $settings,
				'label'     => __( 'Check the connection', 'profotograaf' ),
				'screen'    => 'settings_page_' . Settings_Page::SLUG,
			);
		}

		$plugin = $this->plugin ?? Plugin::instance();
		if ( $plugin->settings()->get( 'leads_enabled' ) ) {
			if ( $connection->needs_leads_reconnect() ) {
				$notices[ self::LEADS_RECONNECT ] = array(
					'signature' => 'leads_reconnect:' . (int) $connection->status()['connected_at'],
					'message'   => __( 'Profotograaf cannot send your enquiries yet. This connection lacks the permission to receive enquiries.', 'profotograaf' ),
					'link'      => $settings,
					'label'     => __( 'Connect this site again', 'profotograaf' ),
					'screen'    => 'settings_page_' . Settings_Page::SLUG,
				);
			}
			$pending = $connection->is_connected() ? 0 : $this->pending_leads();
			if ( $pending > 0 ) {
				$notices[ self::LEADS_WAITING ] = array(
					'signature' => 'leads_waiting:' . $pending,
					'message'   => sprintf(
						/* translators: %d: number of enquiries. */
						_n( '%d enquiry is waiting to be sent because this site is not connected to Profotograaf.', '%d enquiries are waiting to be sent because this site is not connected to Profotograaf.', $pending, 'profotograaf' ),
						$pending
					),
					'link'      => $settings,
					'label'     => __( 'Connect this site', 'profotograaf' ),
					'screen'    => 'settings_page_' . Settings_Page::SLUG,
				);
			}
			$new_forms = $this->new_forms();
			if ( array() !== $new_forms ) {
				$notices[ self::LEADS_NEW_FORMS ] = array(
					'signature' => 'leads_new_forms:' . md5( implode( '|', $new_forms ) ),
					'message'   => sprintf(
						/* translators: %s: names of the forms, separated by commas. */
						_n( '%s now sends its enquiries to Profotograaf. Check its fields and switch.', '%s now send their enquiries to Profotograaf. Check their fields and switches.', count( $new_forms ), 'profotograaf' ),
						implode( ', ', $new_forms )
					),
					'link'      => Leads_Settings::url(),
					'label'     => __( 'Review the forms', 'profotograaf' ),
					'screen'    => 'settings_page_' . Leads_Settings::SLUG,
				);
			}
		}//end if

		$failed = $this->failed_leads();
		if ( $failed > 0 ) {
			$notices[ self::LEADS ] = array(
				'signature' => 'leads:' . $failed,
				'message'   => sprintf(
					/* translators: %d: number of enquiries. */
					_n( '%d enquiry could not be delivered to Profotograaf.', '%d enquiries could not be delivered to Profotograaf.', $failed, 'profotograaf' ),
					$failed
				),
				'link'      => Leads_Settings::url(),
				'label'     => __( 'Retry or export the enquiries', 'profotograaf' ),
				'screen'    => 'settings_page_' . Leads_Settings::SLUG,
			);
		}
		$notices = array_merge( $notices, $this->sync_notices() );
		return array_merge( $notices, $this->backup_notices() );
	}

	/**
	 * The notice for imported photos that need attention. It shows on the
	 * Media screens and the settings page, and links to the filtered library.
	 *
	 * @return array<string,array{signature:string,message:string,link:string,label:string,screen:string,only?:array<int,string>}>
	 */
	private function sync_notices(): array {
		$plugin = $this->plugin ?? Plugin::instance();
		$total  = Sync_Attention::total();
		if ( $total < 1 || ! $plugin->settings()->media_source_enabled() ) {
			return array();
		}
		return array(
			self::SYNC => array(
				'signature' => Sync_Attention::signature(),
				'message'   => Sync_Attention::message( $total ),
				'link'      => Sync_Attention::list_url(),
				'label'     => __( 'Show them', 'profotograaf' ),
				'screen'    => '',
				'only'      => array( 'upload', 'media_page_' . Media_Import_Screen::SLUG, 'settings_page_' . Settings_Page::SLUG ),
			),
		);
	}

	/**
	 * The backup notices that are active now, by kind.
	 *
	 * @return array<string,array{signature:string,message:string,link:string,label:string,screen:string,only?:array<int,string>}>
	 */
	private function backup_notices(): array {
		$status = $this->backup_status();
		if ( ! $status->active() ) {
			return array();
		}
		$notices = array();
		$pause   = $status->pause();
		if ( 'storage_full' === $pause['reason'] ) {
			$notices[ self::BACKUP_STORAGE ] = array(
				'signature' => 'backup_storage:' . $pause['until'],
				'message'   => __( 'Your Profotograaf storage is full, so copying is paused. Free up space or upgrade your plan, then press Copy now.', 'profotograaf' ),
				'link'      => Backup_Panel::url(),
				'label'     => __( 'Open the copy settings', 'profotograaf' ),
				'screen'    => 'settings_page_' . Settings_Page::SLUG,
			);
		}
		$waiting = $status->waiting_for_you();
		if ( $waiting > 0 ) {
			$notices[ self::BACKUP_FAILED ] = array(
				'signature' => 'backup_failed:' . $waiting,
				'message'   => sprintf(
					/* translators: %d: number of files. */
					_n( '%d file could not be copied to Profotograaf.', '%d files could not be copied to Profotograaf.', $waiting, 'profotograaf' ),
					$waiting
				),
				'link'      => Backup_Panel::url(),
				'label'     => __( 'See why and retry', 'profotograaf' ),
				'screen'    => 'settings_page_' . Settings_Page::SLUG,
			);
		}
		return $notices;
	}

	/**
	 * Where the backup stands.
	 */
	protected function backup_status(): Backup_Status {
		$plugin = $this->plugin ?? Plugin::instance();
		return new Backup_Status( $plugin->settings(), $plugin->connection(), null, $plugin->clock() );
	}

	/**
	 * Prints the notices the current user has not dismissed.
	 */
	public function render(): void {
		if ( ! current_user_can( Settings_Page::CAPABILITY ) ) {
			return;
		}
		$dismissed = $this->dismissed();
		$screen    = $this->screen_id();
		foreach ( $this->active() as $kind => $notice ) {
			if ( ( $dismissed[ $kind ] ?? '' ) === $notice['signature'] || $screen === $notice['screen'] ) {
				continue;
			}
			if ( isset( $notice['only'] ) && ! in_array( $screen, $notice['only'], true ) ) {
				continue;
			}
			printf(
				'<div class="notice notice-error profotograaf-notice profotograaf-notice--%1$s"><p>%2$s <a href="%3$s">%4$s</a> | <a href="%5$s">%6$s</a></p></div>',
				esc_attr( $kind ),
				esc_html( $notice['message'] ),
				esc_url( $notice['link'] ),
				esc_html( $notice['label'] ),
				esc_url( $this->dismiss_url( $kind, $notice['signature'] ) ),
				esc_html__( 'Dismiss', 'profotograaf' )
			);
		}
	}

	/**
	 * Stores the dismissal for the current user and returns to the screen the
	 * user came from.
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( Settings_Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::DISMISS_ACTION );

		$kind      = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$signature = isset( $_GET['signature'] ) ? sanitize_text_field( wp_unslash( $_GET['signature'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		if ( in_array( $kind, array( self::REVOKED, self::REFRESH, self::LEADS, self::SYNC, self::BACKUP_STORAGE, self::BACKUP_FAILED ), true ) && '' !== $signature ) {
			$this->dismiss( $kind, $signature );
		}
		$this->finish();
	}

	/**
	 * Dismisses a notice for the current user.
	 *
	 * @param string $kind      Notice kind.
	 * @param string $signature State signature the user saw.
	 */
	public function dismiss( string $kind, string $signature ): void {
		$dismissed          = $this->dismissed();
		$dismissed[ $kind ] = $signature;
		update_user_meta( get_current_user_id(), self::DISMISS_META, $dismissed );
	}

	/**
	 * Sends the browser back to where it came from.
	 */
	protected function finish(): void {
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Number of enquiries that failed for good.
	 */
	protected function failed_leads(): int {
		return ( new Queue( new Option_Job_Store() ) )->counts()['failed'];
	}

	/**
	 * Names of the forms that started sending enquiries without a review.
	 *
	 * @return string[]
	 */
	protected function new_forms(): array {
		/**
		 * Filters the names of the forms that have no saved entry yet.
		 *
		 * @param string[] $labels Names such as `Contact Form 7: Wedding`.
		 */
		$labels = apply_filters( 'profotograaf_new_lead_forms', array() );
		return is_array( $labels ) ? array_values( array_filter( array_map( 'strval', $labels ) ) ) : array();
	}

	/**
	 * Number of enquiries waiting to be sent.
	 */
	protected function pending_leads(): int {
		return ( new Queue( new Option_Job_Store() ) )->counts()['pending'];
	}

	/**
	 * Id of the admin screen being shown, empty when there is none.
	 */
	protected function screen_id(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen ? (string) $screen->id : '';
	}

	/**
	 * Current unix time.
	 */
	protected function now(): int {
		return time();
	}

	/**
	 * Whether a refresh failed and none succeeded since.
	 *
	 * @param array<string,array<string,int>> $episodes Stored episodes.
	 */
	private function refresh_failing( array $episodes ): bool {
		return isset( $episodes[ self::REFRESH ] )
			&& (int) $episodes[ self::REFRESH ]['expires_at'] === $this->connection()->access_expires_at();
	}

	/**
	 * URL that dismisses a notice.
	 *
	 * @param string $kind      Notice kind.
	 * @param string $signature State signature.
	 */
	private function dismiss_url( string $kind, string $signature ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => self::DISMISS_ACTION,
					'kind'      => $kind,
					'signature' => $signature,
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION
		);
	}

	/**
	 * What the current user dismissed: kind => signature.
	 *
	 * @return array<string,string>
	 */
	private function dismissed(): array {
		$stored = get_user_meta( get_current_user_id(), self::DISMISS_META, true );
		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}

	/**
	 * Stored episodes.
	 *
	 * @return array<string,array<string,int>>
	 */
	private function episodes(): array {
		$stored = get_option( self::EPISODES_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Stores the episodes without autoload.
	 *
	 * @param array<string,array<string,int>> $episodes Episodes.
	 */
	private function save_episodes( array $episodes ): void {
		update_option( self::EPISODES_OPTION, $episodes, false );
	}

	/**
	 * Connection state.
	 */
	private function connection(): Connection {
		return ( $this->plugin ?? Plugin::instance() )->connection();
	}
}
