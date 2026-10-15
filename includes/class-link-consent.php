<?php
/**
 * The consent to the "Powered by Profotograaf" link.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * The Free plan shows a small "Powered by Profotograaf" link under galleries
 * and it cannot be switched off. The site owner accepts that condition once,
 * and the gallery blocks and the shortcode refuse to render without it.
 *
 * - The link applies while the plan answer of the platform says `badge`. A paid
 *   plan without the link needs no consent. A site that does not know the plan
 *   yet is treated like a Free one.
 * - The consent is stored per site with the value, the time and the plan it was
 *   given on.
 * - While the platform reports no link, the stored consent is dropped. A later
 *   downgrade then asks again at the next admin visit.
 * - A site that is not connected asks for nothing: the choice is made when an
 *   account connects.
 */
class Link_Consent {

	public const OPTION = 'profotograaf_link_consent';

	public const PRICING_URL = 'https://profotograaf.nl/pricing';

	/**
	 * Connection state.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Stored platform answer, which carries the plan.
	 *
	 * @var Platform_Status
	 */
	private Platform_Status $status;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Connection      $connection Connection state.
	 * @param Platform_Status $status     Stored platform answer.
	 * @param callable|null   $clock      Returns the current unix time, `time` by default.
	 */
	public function __construct( Connection $connection, Platform_Status $status, ?callable $clock = null ) {
		$this->connection = $connection;
		$this->status     = $status;
		$this->clock      = $clock ?? 'time';
	}

	/**
	 * Whether the link applies to this site and the owner has to accept it.
	 */
	public function required(): bool {
		if ( ! $this->connection->is_connected() ) {
			return false;
		}
		$plan = $this->status->stored()['plan'];
		return null === $plan || $plan['badge'];
	}

	/**
	 * Whether the owner accepted the link.
	 */
	public function given(): bool {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) && true === ( $stored['given'] ?? false );
	}

	/**
	 * When the owner accepted it, as a unix time, or 0.
	 */
	public function given_at(): int {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? (int) ( $stored['at'] ?? 0 ) : 0;
	}

	/**
	 * Whether galleries may render.
	 */
	public function allows_render(): bool {
		return ! $this->required() || $this->given();
	}

	/**
	 * Whether the owner has to be asked now.
	 */
	public function pending(): bool {
		return $this->required() && ! $this->given();
	}

	/**
	 * Stores the acceptance with the time and the plan it was given on.
	 */
	public function accept(): void {
		$plan = $this->status->stored()['plan'];
		update_option(
			self::OPTION,
			array(
				'given' => true,
				'at'    => (int) ( $this->clock )(),
				'plan'  => null === $plan ? '' : $plan['slug'],
			),
			false
		);
	}

	/**
	 * Drops the stored consent while the platform reports no link, so a later
	 * downgrade asks again. Call it after a successful status call.
	 */
	public function sync(): void {
		$plan = $this->status->stored()['plan'];
		if ( null !== $plan && ! $plan['badge'] ) {
			$this->forget();
		}
	}

	/**
	 * Removes the stored consent, for a disconnect: another account may have
	 * another plan.
	 */
	public function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * What a gallery shows in place of its markup while the consent is missing.
	 * Visitors see nothing. People who can manage the plugin see why.
	 *
	 * @param string $settings_url Where the owner answers.
	 */
	public function refusal( string $settings_url ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return sprintf(
			'<p class="profotograaf-notice">%s <a href="%s">%s</a></p>',
			esc_html__( 'This Profotograaf gallery is hidden until you accept the Free plan condition (a small "Powered by Profotograaf" link under galleries).', 'profotograaf' ),
			esc_url( $settings_url ),
			esc_html__( 'Review the condition', 'profotograaf' )
		);
	}
}
