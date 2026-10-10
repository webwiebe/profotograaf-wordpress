<?php
/**
 * Review notice driven by the platform.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Asks for a review on wordpress.org once the platform says the site earned
 * it (`review_prompt.eligible` in the stored status answer).
 *
 * The notice follows the plugin directory guidelines: it shows to users who
 * can manage options, only on the dashboard and on this plugin's settings
 * page, it has a clear way to dismiss it, and it never returns after
 * "Don't ask again" or after the owner went to the review page. "Later"
 * hides it for 14 days. Every choice is a nonce-protected admin-post link, so
 * the notice needs no script. The choices are reported to the platform on the
 * next status call (see Review_Prompt and Platform_Status_Sync).
 */
class Review_Notice implements Module {

	public const ACTION = 'profotograaf_review_choice';

	public const REVIEW_URL = 'https://wordpress.org/support/plugin/profotograaf/reviews/#new-post';

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
	}

	/**
	 * Prints the notice when it is due, on the screens that may show it.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $this->screen_id(), $this->screens(), true ) ) {
			return;
		}
		$plugin = $this->plugin();
		if ( ! $plugin->connection()->is_connected() ) {
			return;
		}
		$prompt = $plugin->platform_status()->stored()['review_prompt'];
		$review = $plugin->review_prompt();
		if ( ! $review->is_due( $prompt ) ) {
			return;
		}
		if ( $review->mark_shown() ) {
			do_action( 'profotograaf_review_event_queued' );
		}

		printf(
			'<div class="notice notice-info profotograaf-notice profotograaf-notice--review"><p>%1$s</p><p><a class="button button-primary" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a> <a class="button" href="%4$s">%5$s</a> <a class="button-link" href="%6$s">%7$s</a></p></div>',
			esc_html( $this->message( $prompt['reason'] ) ),
			esc_url( $this->choice_url( 'clicked' ) ),
			esc_html__( 'Leave a review', 'profotograaf' ),
			esc_url( $this->choice_url( 'later' ) ),
			esc_html__( 'Later', 'profotograaf' ),
			esc_url( $this->choice_url( 'dismissed' ) ),
			esc_html__( "Don't ask again", 'profotograaf' )
		);
	}

	/**
	 * Stores the choice and sends the browser on: to the review page for
	 * "Leave a review", back to where it came from otherwise.
	 */
	public function handle_choice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'profotograaf' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$choice = isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$review = $this->plugin()->review_prompt();
		if ( 'clicked' === $choice ) {
			$review->click();
		} elseif ( 'later' === $choice ) {
			$review->later();
		} elseif ( 'dismissed' === $choice ) {
			$review->dismiss();
		} else {
			$this->finish( false );
			return;
		}
		do_action( 'profotograaf_review_event_queued' );
		$this->finish( 'clicked' === $choice );
	}

	/**
	 * Redirects: to the review page, or back.
	 *
	 * @param bool $to_review Whether to go to the review page.
	 */
	protected function finish( bool $to_review ): void {
		if ( $to_review ) {
			wp_redirect( self::REVIEW_URL ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed wordpress.org address.
			exit;
		}
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Id of the admin screen being shown, empty when there is none.
	 */
	protected function screen_id(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen ? (string) $screen->id : '';
	}

	/**
	 * Screens that may show the notice.
	 *
	 * @return string[]
	 */
	private function screens(): array {
		return array( 'dashboard', 'settings_page_' . Settings_Page::SLUG );
	}

	/**
	 * The notice text for a reason the platform named.
	 *
	 * @param string $reason Reason from the platform.
	 */
	private function message( string $reason ): string {
		if ( 'first_client_download' === $reason ) {
			return __( 'A client just downloaded photos from a gallery you share with Profotograaf. If the plugin helps your work, a short review on WordPress.org helps other photographers find it.', 'profotograaf' );
		}
		if ( 'embed_views_10' === $reason ) {
			return __( 'Your Profotograaf galleries have been viewed on your site at least 10 times. If the plugin works well for you, a short review on WordPress.org helps other photographers find it.', 'profotograaf' );
		}
		return __( 'If the Profotograaf plugin works well for you, a short review on WordPress.org helps other photographers find it.', 'profotograaf' );
	}

	/**
	 * Link that stores a choice.
	 *
	 * @param string $choice `clicked`, `later` or `dismissed`.
	 */
	private function choice_url( string $choice ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'choice' => $choice,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	/**
	 * The plugin container.
	 */
	private function plugin(): Plugin {
		return $this->plugin ?? Plugin::instance();
	}
}
