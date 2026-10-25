<?php
/**
 * The prompt for the consent to the Free plan link.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Link_Consent;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the owner to accept the "Powered by Profotograaf" link of the Free plan.
 *
 * The notice shows on every admin screen, to users who can manage options,
 * while the link applies and the owner has not accepted it. The checkbox is
 * required. Without it the gallery block, the full page block and the shortcode
 * render nothing for visitors, and the notice points to the paid plans.
 */
class Link_Consent_Prompt implements Module {

	public const ACTION = 'profotograaf_link_consent';

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
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Prints the notice.
	 */
	public function render(): void {
		if ( ! current_user_can( Settings_Page::CAPABILITY ) ) {
			return;
		}
		if ( $this->consent()->plan_unknown() ) {
			?>
			<div class="notice notice-info profotograaf-notice profotograaf-notice--plan-unknown">
				<p><?php esc_html_e( 'Profotograaf has not reported your plan yet. Your galleries keep showing. On the Free plan a small "Powered by Profotograaf" link appears under galleries, and you will be asked to accept it once your plan is known.', 'profotograaf' ); ?></p>
			</div>
			<?php
			return;
		}
		if ( ! $this->consent()->pending() ) {
			return;
		}
		?>
		<div class="notice notice-warning profotograaf-notice profotograaf-notice--link-consent">
			<p><strong><?php esc_html_e( 'Galleries are hidden until you accept the Free plan condition.', 'profotograaf' ); ?></strong></p>
			<p><?php esc_html_e( 'On the Free plan a small "Powered by Profotograaf" link appears under your galleries. It cannot be switched off. Paid plans show no link.', 'profotograaf' ); ?>
				<a href="<?php echo esc_url( Link_Consent::PRICING_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See the paid plans', 'profotograaf' ); ?></a></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<p>
					<label>
						<input type="checkbox" name="accept" value="1" required />
						<?php esc_html_e( 'Free plan: a small "Powered by Profotograaf" link appears under galleries', 'profotograaf' ); ?>
					</label>
				</p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Accept and show my galleries', 'profotograaf' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Stores the acceptance and returns to the screen the owner came from. A
	 * request without the tick stores nothing.
	 */
	public function handle(): void {
		if ( ! current_user_can( Settings_Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		if ( isset( $_POST['accept'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['accept'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			$this->consent()->accept();
		}
		$this->finish();
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
	 * The consent service.
	 */
	private function consent(): Link_Consent {
		return ( $this->plugin ?? Plugin::instance() )->link_consent();
	}
}
