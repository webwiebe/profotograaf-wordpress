<?php
/**
 * The opt-in consent control for telemetry.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Plugin;
use Profotograaf\Settings;
use Profotograaf\Settings_Schema;
use Profotograaf\Telemetry_Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Asks after the site connected, keeps the answer and enforces a revocation
 * (docs/telemetry.md, "Opt-In and Consent").
 *
 * - The prompt shows only on a connected site, to users who can manage
 *   options, until the owner answers. "Not now" brings it back after a week.
 * - Turning the setting off, from the screen or by REST, deletes the queued
 *   batches. So does a disconnect.
 * - A host can force telemetry off with the `profotograaf_telemetry_enabled`
 *   filter, which also hides the prompt.
 */
class Telemetry_Consent implements Module {

	public const PROMPT_OPTION = 'profotograaf_telemetry_prompt';
	public const ACTION        = 'profotograaf_telemetry_consent';
	public const SNOOZE        = 604800;

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

		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_choice' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'profotograaf_disconnected', array( $this, 'on_disconnected' ) );
	}

	/**
	 * The sender that holds the queue.
	 */
	public function sender(): Telemetry_Sender {
		return new Telemetry_Sender( $this->plugin()->settings() );
	}

	/**
	 * Whether the prompt shows now.
	 */
	public function should_prompt(): bool {
		if ( ! $this->plugin()->connection()->is_connected() ) {
			return false;
		}
		$state = $this->state();
		if ( ! empty( $state['answered'] ) || $this->now() < (int) ( $state['snoozed_until'] ?? 0 ) ) {
			return false;
		}
		// A host that forces telemetry off, or an owner who already opted in, has nothing to answer.
		return ! $this->plugin()->settings()->get( 'telemetry_enabled' ) && false !== apply_filters( 'profotograaf_telemetry_enabled', true );
	}

	/**
	 * Prints the prompt.
	 */
	public function render(): void {
		if ( ! current_user_can( Settings_Page::CAPABILITY ) || ! $this->should_prompt() ) {
			return;
		}
		?>
		<div class="notice notice-info profotograaf-notice profotograaf-notice--telemetry">
			<p><?php esc_html_e( 'Help improve Profotograaf? You can share anonymous usage and error data: plugin, WordPress and PHP versions, locale, enabled modules and counts of successes and failures. Your site address, visitors, galleries and enquiries are never shared. You can turn it off at any time.', 'profotograaf' ); ?>
				<a href="<?php echo esc_url( Settings_Schema::TELEMETRY_DOC_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'What is shared', 'profotograaf' ); ?></a></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<p>
					<button type="submit" name="choice" value="enable" class="button button-primary"><?php esc_html_e( 'Enable telemetry', 'profotograaf' ); ?></button>
					<button type="submit" name="choice" value="later" class="button"><?php esc_html_e( 'Not now', 'profotograaf' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Stores the owner's answer and returns to the screen the owner came from.
	 */
	public function handle_choice(): void {
		if ( ! current_user_can( Settings_Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( 'enable' === $choice ) {
			$this->enable();
		} elseif ( 'later' === $choice ) {
			$this->snooze();
		}
		$this->finish();
	}

	/**
	 * Opts in and ends the prompt.
	 */
	public function enable(): void {
		update_option( Settings::OPTION, $this->plugin()->settings()->sanitize( array( 'telemetry_enabled' => true ) ) );
		$this->save_state( array( 'answered' => true ) );
	}

	/**
	 * Hides the prompt for a week.
	 */
	public function snooze(): void {
		$this->save_state( array( 'snoozed_until' => $this->now() + self::SNOOZE ) );
	}

	/**
	 * Reacts to a settings change. Opting in from the screen answers the
	 * prompt. Opting out deletes what waits to be sent.
	 *
	 * @param mixed $before Settings before.
	 * @param mixed $after Settings after.
	 */
	public function on_settings_updated( $before, $after ): void {
		$was = is_array( $before ) && ! empty( $before['telemetry_enabled'] );
		$is  = is_array( $after ) && ! empty( $after['telemetry_enabled'] );
		if ( $is ) {
			$this->save_state( array( 'answered' => true ) );
		}
		if ( $was && ! $is ) {
			$this->sender()->clear_queue();
		}
	}

	/**
	 * A disconnect discards queued telemetry.
	 */
	public function on_disconnected(): void {
		$this->sender()->clear_queue();
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
	 * Current unix time.
	 */
	protected function now(): int {
		return time();
	}

	/**
	 * Stored prompt state.
	 *
	 * @return array<string,mixed>
	 */
	private function state(): array {
		$stored = get_option( self::PROMPT_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Merges values into the prompt state, without autoload.
	 *
	 * @param array<string,mixed> $values Values to set.
	 */
	private function save_state( array $values ): void {
		update_option( self::PROMPT_OPTION, array_merge( $this->state(), $values ), false );
	}

	/**
	 * Service container.
	 */
	private function plugin(): Plugin {
		return $this->plugin ?? Plugin::instance();
	}
}
