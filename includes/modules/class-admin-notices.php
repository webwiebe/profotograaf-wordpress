<?php
/**
 * Admin notices for a revoked connection, a failing token refresh and failed leads.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Admin\Leads_Settings;
use Profotograaf\Connection;
use Profotograaf\Leads\Option_Job_Store;
use Profotograaf\Leads\Queue;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a notice on every admin screen to users who can manage options, so a
 * problem is seen before the owner opens a settings page.
 *
 * - Revoked: the platform ended the connection.
 * - Refresh: the background token refresh keeps failing.
 * - Leads: enquiries failed for good and wait on this site.
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
	 * @return array<string,array{signature:string,message:string,link:string,label:string,screen:string}>
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
		return $notices;
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
		if ( in_array( $kind, array( self::REVOKED, self::REFRESH, self::LEADS ), true ) && '' !== $signature ) {
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
