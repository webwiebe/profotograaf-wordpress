<?php
/**
 * The enquiry block: a native form that feeds the lead queue.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

use Profotograaf\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the `profotograaf/enquiry` block (name, email, message, date) and
 * handles its post.
 *
 * A submission goes through the same Dispatcher as the form plugin bridges, so
 * it is queued, delivered by WP-Cron with retries and shows up in the
 * photographer's leads. The form key is `native:enquiry` and it is on unless
 * the photographer switched it off in the lead settings.
 *
 * Spam protection needs no account and no cookie, so it survives page caching:
 * a hidden field bots fill, a signed timestamp that must be 3 seconds to 24
 * hours old, and 5 enquiries an hour per address. The address is only ever
 * used as a salted hash in a transient key.
 *
 * Every outcome is shown to the visitor. A silent drop would lose the enquiry
 * without the visitor knowing, so a failure says the message was not received.
 * Logs hold the outcome and never a name, email, message or address.
 */
final class Enquiry_Form {

	public const FORM_KEY = 'native:enquiry';

	public const BLOCK = 'profotograaf/enquiry';

	public const ACTION = 'profotograaf_enquiry';

	public const QUERY = 'pf_enquiry';

	/**
	 * Enquiries one address may send per hour.
	 */
	public const LIMIT = 5;

	private const MIN_AGE = 3;

	private const MAX_AGE = 86400;

	private const WINDOW = 3600;

	private const OUTCOMES = array( 'sent', 'invalid', 'limited', 'error' );

	/**
	 * Dispatcher.
	 *
	 * @var Dispatcher
	 */
	private Dispatcher $dispatcher;

	/**
	 * Whether lead capture is switched on.
	 *
	 * @var callable
	 */
	private $enabled;

	/**
	 * Returns what keeps enquiries from reaching Profotograaf: `off`,
	 * `disconnected`, `reconnect` or an empty string.
	 *
	 * @var callable
	 */
	private $problem;

	/**
	 * Constructor.
	 *
	 * @param Dispatcher    $dispatcher Where the lead goes.
	 * @param callable|null $enabled    Returns whether lead capture is on, always on by default.
	 * @param callable|null $problem    Returns the delivery problem for the editor warning, none by default.
	 */
	public function __construct( Dispatcher $dispatcher, ?callable $enabled = null, ?callable $problem = null ) {
		$this->dispatcher = $dispatcher;
		$this->enabled    = $enabled ?? static fn(): bool => true;
		$this->problem    = $problem ?? static fn(): string => '';
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'register_block_type_args', array( $this, 'add_render_callback' ), 10, 2 );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'on_post' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'on_post' ) );
	}

	/**
	 * Attaches the render callback when the block is registered.
	 *
	 * @param array<string,mixed> $args Block type arguments.
	 * @param string              $name Block name.
	 * @return array<string,mixed>
	 */
	public function add_render_callback( array $args, string $name ): array {
		if ( self::BLOCK === $name ) {
			$args['render_callback'] = array( $this, 'render_block' );
		}
		return $args;
	}

	/**
	 * Render callback: shows the outcome of the last post when the address has one.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public function render_block( array $attributes ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only, picks a message.
		$outcome = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ) : '';
		$editing = defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' );
		return $this->render( $attributes, time(), $outcome, $editing );
	}

	/**
	 * The signed timestamp that goes into the form.
	 *
	 * @param int $time Unix time the form is drawn.
	 */
	public function token( int $time ): string {
		return $time . '.' . hash_hmac( 'sha256', 'enquiry|' . $time, wp_salt( 'nonce' ) );
	}

	/**
	 * Draws the form.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param int                 $now        Unix time.
	 * @param string              $outcome    Outcome of the last post, empty for none.
	 * @param bool                $editing    Whether the editor draws the block, which adds a warning when enquiries cannot reach Profotograaf.
	 */
	public function render( array $attributes, int $now, string $outcome = '', bool $editing = false ): string {
		unset( $attributes );
		$warning = $editing ? $this->warning( (string) call_user_func( $this->problem ) ) : '';
		if ( ! (bool) call_user_func( $this->enabled ) ) {
			return $warning;
		}

		$message = $this->message( $outcome );
		$page    = (string) get_permalink();

		$html  = '<div class="wp-block-profotograaf-enquiry">';
		$html .= $warning;
		$html .= $message;
		if ( 'sent' !== $outcome ) {
			$html .= '<form class="profotograaf-enquiry" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
			$html .= '<input type="hidden" name="pf_token" value="' . esc_attr( $this->token( $now ) ) . '" />';
			$html .= '<input type="hidden" name="pf_page" value="' . esc_attr( $page ) . '" />';
			$html .= $this->field( 'pf_name', __( 'Your name', 'profotograaf' ), 'text', true, 'name' );
			$html .= $this->field( 'pf_email', __( 'Email address', 'profotograaf' ), 'email', true, 'email' );
			$html .= $this->field( 'pf_date', __( 'Date of your event', 'profotograaf' ), 'date', false, '' );
			$html .= '<p><label for="pf_message">' . esc_html__( 'Your message', 'profotograaf' ) . '</label>'
				. '<textarea id="pf_message" name="pf_message" rows="5" maxlength="8000"></textarea></p>';
			$html .= '<div aria-hidden="true" style="position:absolute;left:-9999px;height:0;overflow:hidden">'
				. '<label for="pf_website">' . esc_html__( 'Leave this field empty', 'profotograaf' ) . '</label>'
				. '<input type="text" id="pf_website" name="pf_website" value="" tabindex="-1" autocomplete="off" /></div>';
			$html .= '<p><button type="submit">' . esc_html__( 'Send enquiry', 'profotograaf' ) . '</button></p>';
			$html .= '</form>';
		}
		return $html . '</div>';
	}

	/**
	 * Handles a post. Never throws.
	 *
	 * @param array<string,mixed> $input Posted fields, already unslashed.
	 * @param string              $ip    Visitor address, used only as a salted hash.
	 * @param int                 $now   Unix time.
	 * @return string `sent`, `invalid`, `limited` or `error`.
	 */
	public function handle( array $input, string $ip, int $now ): string {
		try {
			if ( ! (bool) call_user_func( $this->enabled ) ) {
				return $this->done( 'error', 'off' );
			}
			if ( '' !== Submission::text( $input['pf_website'] ?? '' ) ) {
				// A bot filled the hidden field. It is told "sent" so it learns nothing.
				return $this->done( 'sent', 'honeypot' );
			}
			if ( ! $this->token_ok( Submission::text( $input['pf_token'] ?? '' ), $now ) ) {
				return $this->done( 'invalid', 'token' );
			}

			$name  = Submission::text( sanitize_text_field( (string) ( $input['pf_name'] ?? '' ) ) );
			$email = Submission::text( sanitize_text_field( (string) ( $input['pf_email'] ?? '' ) ) );
			if ( '' === $name || ! is_email( $email ) ) {
				return $this->done( 'invalid', 'fields' );
			}
			if ( ! $this->within_limit( $ip ) ) {
				return $this->done( 'limited', 'limit' );
			}

			$submission = new Submission(
				self::FORM_KEY,
				'Website enquiry form',
				array(
					array(
						'id'    => 'name',
						'label' => 'Name',
						'type'  => 'name',
						'value' => $name,
					),
					array(
						'id'    => 'email',
						'label' => 'Email',
						'type'  => 'email',
						'value' => $email,
					),
					array(
						'id'    => 'date',
						'label' => 'Event date',
						'type'  => 'date',
						'value' => Submission::text( sanitize_text_field( (string) ( $input['pf_date'] ?? '' ) ) ),
					),
					array(
						'id'    => 'message',
						'label' => 'Message',
						'type'  => 'textarea',
						'value' => Submission::text( sanitize_textarea_field( (string) ( $input['pf_message'] ?? '' ) ) ),
					),
				),
				esc_url_raw( Submission::text( $input['pf_page'] ?? '' ) )
			);

			$result = $this->dispatcher->submit( $submission );
			return 'queued' === $result ? $this->done( 'sent', 'queued' ) : $this->done( 'error', $result );
		} catch ( \Throwable $e ) {
			Logger::exception( 'An enquiry could not be handled.', $e );
			return 'error';
		}//end try
	}

	/**
	 * Handles the admin-post request and redirects back with the outcome.
	 */
	public function on_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Signed timestamp, see token(); a nonce expires on cached pages.
		$input = wp_unslash( $_POST );
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$page  = isset( $input['pf_page'] ) ? esc_url_raw( (string) $input['pf_page'] ) : '';
		$back  = '' !== $page && 0 === strpos( $page, home_url() ) ? $page : home_url( '/' );

		$outcome = $this->handle( is_array( $input ) ? $input : array(), $ip, time() );

		wp_safe_redirect( add_query_arg( self::QUERY, $outcome, $back ) );
		exit;
	}

	/**
	 * Logs the outcome without any visitor data and returns it.
	 *
	 * @param string $outcome Outcome shown to the visitor.
	 * @param string $reason  Short internal reason.
	 */
	private function done( string $outcome, string $reason ): string {
		if ( 'sent' !== $outcome || 'queued' !== $reason ) {
			Logger::info(
				'Enquiry form outcome.',
				array(
					'outcome' => $outcome,
					'reason'  => $reason,
				)
			);
		}
		return $outcome;
	}

	/**
	 * Whether a token is signed by this site and 3 seconds to 24 hours old.
	 *
	 * @param string $token Posted token.
	 * @param int    $now   Unix time.
	 */
	private function token_ok( string $token, int $now ): bool {
		if ( ! preg_match( '/^(\d{1,12})\.[0-9a-f]{64}$/', $token, $match ) ) {
			return false;
		}
		$age = $now - (int) $match[1];
		return hash_equals( $this->token( (int) $match[1] ), $token ) && $age >= self::MIN_AGE && $age <= self::MAX_AGE;
	}

	/**
	 * Counts this enquiry against the address and says whether it is allowed.
	 *
	 * The transient key is a salted hash, so the address is not stored.
	 *
	 * @param string $ip Visitor address.
	 */
	private function within_limit( string $ip ): bool {
		$key   = 'pf_enq_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= self::LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::WINDOW );
		return true;
	}

	/**
	 * One labelled input.
	 *
	 * @param string $name         Field name and id.
	 * @param string $label        Label text.
	 * @param string $type         Input type.
	 * @param bool   $required     Whether the browser requires it.
	 * @param string $autocomplete Autocomplete token.
	 */
	private function field( string $name, string $label, string $type, bool $required, string $autocomplete ): string {
		return '<p><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>'
			. '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"'
			. ( $required ? ' required' : '' )
			. ( '' !== $autocomplete ? ' autocomplete="' . esc_attr( $autocomplete ) . '"' : '' )
			. ' /></p>';
	}

	/**
	 * The editor warning for a delivery problem. Visitors never see it.
	 *
	 * @param string $problem `off`, `disconnected`, `reconnect` or empty.
	 */
	private function warning( string $problem ): string {
		$texts = array(
			'off'          => __( 'Enquiries are switched off for this site, so visitors do not see this form. Turn on Collect enquiries in the Profotograaf settings.', 'profotograaf' ),
			'disconnected' => __( 'This site is not connected to Profotograaf. Enquiries wait on this site until you connect it.', 'profotograaf' ),
			'reconnect'    => __( 'Profotograaf needs a new permission to receive enquiries. Connect this site again in the Profotograaf settings.', 'profotograaf' ),
		);
		if ( ! isset( $texts[ $problem ] ) ) {
			return '';
		}
		return '<p class="profotograaf-enquiry__warning" role="alert">' . esc_html( $texts[ $problem ] ) . '</p>';
	}

	/**
	 * The message for an outcome.
	 *
	 * @param string $outcome Outcome.
	 */
	private function message( string $outcome ): string {
		if ( ! in_array( $outcome, self::OUTCOMES, true ) ) {
			return '';
		}
		$texts = array(
			'sent'    => __( 'Thank you. Your enquiry was received and I will get back to you soon.', 'profotograaf' ),
			'invalid' => __( 'Check your name and email address and send the form again.', 'profotograaf' ),
			'limited' => __( 'Too many enquiries from your connection. Try again in an hour.', 'profotograaf' ),
			'error'   => __( 'Your enquiry was not received. Try again in a few minutes.', 'profotograaf' ),
		);
		$role  = 'sent' === $outcome ? 'status' : 'alert';
		return '<p class="profotograaf-enquiry__message" role="' . $role . '">' . esc_html( $texts[ $outcome ] ) . '</p>';
	}
}
