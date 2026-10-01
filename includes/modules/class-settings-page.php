<?php
/**
 * Settings > Profotograaf.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Admin\Leads_Settings;
use Profotograaf\Admin\Settings_Fields;
use Profotograaf\Cron_Health;
use Profotograaf\Module;
use Profotograaf\Plugin;
use Profotograaf\Settings_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The settings page, in the tabs General, Galleries, Enquiry forms and
 * Advanced. General holds the connection status, connect and disconnect and
 * the allowed embed origin. The options on every tab come from
 * Settings_Schema.
 *
 * Every state change is an admin-post or admin-ajax action that checks the
 * manage_options capability and a nonce before doing anything.
 */
class Settings_Page implements Module {

	public const SLUG       = 'profotograaf';
	public const CAPABILITY = 'manage_options';

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

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'migrate_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'reconnect_notice' ) );
		add_action( 'admin_notices', array( $this, 'cron_notice' ) );
		add_action( 'admin_post_profotograaf_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_profotograaf_cancel', array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_profotograaf_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_profotograaf_sync_origins', array( $this, 'handle_sync_origins' ) );
		add_action( 'wp_ajax_profotograaf_poll', array( $this, 'handle_poll' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PROFOTOGRAAF_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * URL of the settings page.
	 */
	public static function url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Adds the menu entry under Settings.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Profotograaf', 'profotograaf' ),
			__( 'Profotograaf', 'profotograaf' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the settings.
	 */
	public function register_settings(): void {
		$this->plugin()->settings()->register();
	}

	/**
	 * Normalises settings saved by an older version, once per schema version.
	 */
	public function migrate_settings(): void {
		$this->plugin()->settings()->migrate();
	}

	/**
	 * Adds a Settings link on the plugins screen.
	 *
	 * @param array<int|string,string> $links Existing links.
	 * @return array<int|string,string>
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'profotograaf' ) )
		);
		return $links;
	}

	/**
	 * Loads the polling script and styles on this page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'settings_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'profotograaf-settings', PROFOTOGRAAF_URL . 'assets/admin/settings.css', array(), PROFOTOGRAAF_VERSION );
		wp_enqueue_script( 'profotograaf-settings', PROFOTOGRAAF_URL . 'assets/admin/settings.js', array( 'wp-i18n' ), PROFOTOGRAAF_VERSION, true );
		wp_set_script_translations( 'profotograaf-settings', 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
		wp_localize_script(
			'profotograaf-settings',
			'profotograafSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'profotograaf_poll' ),
			)
		);
	}

	/**
	 * Warns on the settings page when WP-Cron does not run and leads wait.
	 */
	public function cron_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( 'settings_page_' . self::SLUG !== $this->screen_id() ) {
			return;
		}
		$this->cron_health()->render_notice();
	}

	/**
	 * Id of the admin screen being shown, empty when there is none.
	 */
	protected function screen_id(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen ? (string) $screen->id : '';
	}

	/**
	 * Cron health of this site.
	 */
	protected function cron_health(): Cron_Health {
		return Cron_Health::for_site();
	}

	/**
	 * Asks the photographer to connect again when the stored token lacks the
	 * permission to switch galleries on. Shown on every admin screen except
	 * the settings page, which carries the same prompt with a button.
	 */
	public function reconnect_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->plugin()->connection()->needs_reconnect() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'settings_page_' . self::SLUG === $screen->id ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Profotograaf needs a new permission to switch galleries on for embedding.', 'profotograaf' ),
			esc_url( self::url() ),
			esc_html__( 'Connect this site again', 'profotograaf' )
		);
	}

	/**
	 * Starts a pairing.
	 */
	public function handle_connect(): void {
		$this->authorize( 'profotograaf_connect' );
		$result = $this->plugin()->pairing()->start();
		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
		}
		$this->finish();
	}

	/**
	 * Abandons the pairing in progress.
	 */
	public function handle_cancel(): void {
		$this->authorize( 'profotograaf_cancel' );
		$this->plugin()->pairing()->cancel();
		$this->finish();
	}

	/**
	 * Disconnects.
	 */
	public function handle_disconnect(): void {
		$this->authorize( 'profotograaf_disconnect' );
		$revoked = $this->plugin()->pairing()->disconnect();
		if ( $revoked ) {
			$this->notice( 'success', __( 'Disconnected from Profotograaf.', 'profotograaf' ) );
		} else {
			$this->notice(
				'success',
				__( 'Disconnected. This site no longer has access. To also remove it from your account, open Connected apps in your Profotograaf account settings.', 'profotograaf' )
			);
		}
		$this->finish();
	}

	/**
	 * Syncs the allowed embed origin again.
	 */
	public function handle_sync_origins(): void {
		$this->authorize( 'profotograaf_sync_origins' );
		do_action( 'profotograaf_sync_origins' );
		$this->finish();
	}

	/**
	 * Answers the settings page's poll: one check with the platform.
	 */
	public function handle_poll(): void {
		check_ajax_referer( 'profotograaf_poll', 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'profotograaf' ) ), 403 );
		}

		$result = $this->plugin()->pairing()->poll();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		switch ( $result['status'] ) {
			case 'approved':
				$this->notice( 'success', __( 'Connected to Profotograaf.', 'profotograaf' ) );
				break;
			case 'denied':
				$this->notice( 'error', __( 'The connection was declined in Profotograaf.', 'profotograaf' ) );
				break;
			case 'expired':
				$this->notice( 'error', __( 'The code expired. Start the connection again.', 'profotograaf' ) );
				break;
		}
		wp_send_json_success( $result );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
		$connection = $this->plugin()->connection();
		$status     = $connection->status();
		$pairing    = $connection->pairing();
		$tab        = $this->current_tab();
		?>
		<div class="wrap profotograaf-settings">
			<h1><?php esc_html_e( 'Profotograaf', 'profotograaf' ); ?></h1>
			<?php $this->render_notice(); ?>
			<?php $this->render_tabs( $tab ); ?>

			<?php if ( Settings_Schema::TAB_GENERAL === $tab ) : ?>
			<h2><?php esc_html_e( 'Connection', 'profotograaf' ); ?></h2>
				<?php if ( null !== $pairing ) : ?>
					<?php $this->render_pairing( $pairing ); ?>
			<?php elseif ( 'connected' === $status['state'] ) : ?>
				<p class="profotograaf-status profotograaf-status--connected">
					<strong><?php esc_html_e( 'Connected', 'profotograaf' ); ?></strong>
					<?php if ( $status['connected_at'] > 0 ) : ?>
						<?php
						/* translators: %s: date the site was connected. */
						echo esc_html( sprintf( __( 'since %s.', 'profotograaf' ), wp_date( (string) get_option( 'date_format' ), $status['connected_at'] ) ) );
						?>
					<?php endif; ?>
				</p>
				<?php if ( $connection->needs_reconnect() ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'This connection was made before Profotograaf could let this site switch galleries on for embedding. Connect again to grant that permission. Your galleries and settings stay as they are.', 'profotograaf' ); ?></p>
					</div>
					<?php $this->render_action_form( 'profotograaf_connect', __( 'Connect again', 'profotograaf' ), 'primary' ); ?>
				<?php endif; ?>
				<?php $this->render_action_form( 'profotograaf_disconnect', __( 'Disconnect', 'profotograaf' ), 'secondary' ); ?>
			<?php else : ?>
				<p class="profotograaf-status">
					<strong><?php esc_html_e( 'Not connected', 'profotograaf' ); ?></strong>
					<?php if ( 'revoked' === $status['state'] ) : ?>
						<?php echo esc_html( $status['last_error'] ); ?>
					<?php endif; ?>
				</p>
				<p><?php esc_html_e( 'Connect this site to your Profotograaf account to place galleries and receive enquiries. You approve the connection in Profotograaf, so your password never reaches this site.', 'profotograaf' ); ?></p>
				<?php $this->render_action_form( 'profotograaf_connect', __( 'Connect to Profotograaf', 'profotograaf' ), 'primary' ); ?>
			<?php endif; ?>
			<?php endif; ?>

			<?php if ( Settings_Schema::TAB_ENQUIRY === $tab ) : ?>
				<p><a href="<?php echo esc_url( Leads_Settings::url() ); ?>"><?php esc_html_e( 'Set up the enquiry forms', 'profotograaf' ); ?></a></p>
			<?php endif; ?>
			<?php ( new Settings_Fields( $this->plugin()->settings() ) )->render_form( $tab ); ?>

			<?php if ( Settings_Schema::TAB_GENERAL === $tab && 'connected' === $status['state'] ) : ?>
				<?php $this->render_origin_sync(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The tab to show: the `tab` query argument when it names a tab, else General.
	 */
	private function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects what to show, changes nothing.
		return array_key_exists( $tab, Settings_Schema::tabs() ) ? $tab : Settings_Schema::TAB_GENERAL;
	}

	/**
	 * Prints the tab links.
	 *
	 * @param string $current Tab shown now.
	 */
	private function render_tabs( string $current ): void {
		?>
		<nav class="nav-tab-wrapper">
			<?php foreach ( Settings_Schema::tabs() as $slug => $label ) : ?>
				<a class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $slug, self::url() ) ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Renders the pairing panel: the code, the approval link and the cancel button.
	 *
	 * @param array{device_code:string,user_code:string,verification_uri:string,expires_at:int,interval:int} $pairing Pairing in progress.
	 */
	private function render_pairing( array $pairing ): void {
		?>
		<div id="profotograaf-pairing" class="profotograaf-pairing" data-interval="<?php echo esc_attr( (string) $pairing['interval'] ); ?>">
			<p><?php esc_html_e( 'Confirm this code in Profotograaf to finish connecting:', 'profotograaf' ); ?></p>
			<p><code class="profotograaf-code"><?php echo esc_html( $pairing['user_code'] ); ?></code></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $pairing['verification_uri'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the approval page', 'profotograaf' ); ?></a>
			</p>
			<p class="profotograaf-pairing__status" role="status" aria-live="polite"><?php esc_html_e( 'Waiting for you to approve the connection in Profotograaf.', 'profotograaf' ); ?></p>
			<?php $this->render_action_form( 'profotograaf_cancel', __( 'Cancel', 'profotograaf' ), 'secondary' ); ?>
		</div>
		<?php
	}

	/**
	 * Renders the origin sync section.
	 */
	private function render_origin_sync(): void {
		$sync = Origin_Sync::status();
		?>
		<h2><?php esc_html_e( 'Embedding on this site', 'profotograaf' ); ?></h2>
		<?php if ( 'synced' === $sync['state'] ) : ?>
			<p>
				<?php
				/* translators: %s: site address, for example https://example.com. */
				echo esc_html( sprintf( __( '%s is allowed to show your Profotograaf pages in a frame.', 'profotograaf' ), $sync['origin'] ) );
				?>
			</p>
		<?php elseif ( 'insecure' === $sync['state'] ) : ?>
			<p><?php esc_html_e( 'Profotograaf only allows embedding on sites served over https. Serve this site over https to embed your pages in a frame.', 'profotograaf' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				/* translators: %s: site address, for example https://example.com. */
				echo esc_html( sprintf( __( 'To show your Profotograaf pages in a frame on this site, add %s under "Sites allowed to embed my pages" in your Profotograaf account settings.', 'profotograaf' ), '' !== $sync['origin'] ? $sync['origin'] : \Profotograaf\Config::site_origin() ) );
				?>
			</p>
			<?php if ( 'error' === $sync['state'] && '' !== $sync['message'] ) : ?>
				<p class="description"><?php echo esc_html( $sync['message'] ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
		$this->render_action_form( 'profotograaf_sync_origins', __( 'Check again', 'profotograaf' ), 'secondary' );
	}

	/**
	 * Renders a one button form that posts to admin-post.php with a nonce.
	 *
	 * @param string $action Action name, also the nonce action.
	 * @param string $label  Button label.
	 * @param string $type   Button type: primary or secondary.
	 */
	private function render_action_form( string $action, string $label, string $type ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="profotograaf-action">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, $type, 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Shows and clears the notice left for the current user.
	 */
	private function render_notice(): void {
		$key    = $this->notice_key();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$class = 'error' === ( $notice['type'] ?? '' ) ? 'notice-error' : 'notice-success';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Leaves a notice for the next page load.
	 *
	 * @param string $type    success or error.
	 * @param string $message Text.
	 */
	private function notice( string $type, string $message ): void {
		set_transient(
			$this->notice_key(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Transient name for the current user's notice.
	 */
	private function notice_key(): string {
		return 'profotograaf_notice_' . get_current_user_id();
	}

	/**
	 * Requires the capability and a valid nonce, or stops the request.
	 *
	 * @param string $action Nonce action.
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Sends the browser back to the settings page.
	 */
	protected function finish(): void {
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * The plugin container.
	 */
	private function plugin(): Plugin {
		return $this->plugin ?? Plugin::instance();
	}
}
