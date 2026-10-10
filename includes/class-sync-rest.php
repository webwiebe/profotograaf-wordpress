<?php
/**
 * REST route that runs the change feed poll on demand.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * POST /profotograaf/v1/sync/run { background? } runs one bounded poll for
 * users with upload_files ("Check now"). It covers hosts where WP-Cron does
 * not run (docs/media-lifecycle.md, rule 3).
 *
 * With `background` set, the admin screens ask for an opportunistic run: the
 * route only runs when the last success is older than BACKGROUND_AFTER and no
 * background run started within BACKGROUND_THROTTLE seconds. The caller never
 * waits on the answer. The front end never calls this route.
 */
final class Sync_Rest {

	/** Seconds after which an admin screen asks for a background run. */
	public const BACKGROUND_AFTER = 21600;

	/** Seconds between two background runs. */
	public const BACKGROUND_THROTTLE = 900;

	public const THROTTLE = 'profotograaf_sync_background';

	/**
	 * Poller.
	 *
	 * @var Change_Poller
	 */
	private Change_Poller $poller;

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Constructor.
	 *
	 * @param Change_Poller $poller     Poller.
	 * @param Connection    $connection Connection.
	 */
	public function __construct( Change_Poller $poller, Connection $connection ) {
		$this->poller     = $poller;
		$this->connection = $connection;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			Gallery_Rest::NAMESPACE,
			'/sync/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'can_run' ),
				'args'                => array(
					'background' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 */
	public function can_run(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Whether an admin screen should ask for a background run now: the site is
	 * connected, the last success is old or missing and no background run
	 * started lately.
	 *
	 * @param bool $connected Whether the site is connected.
	 * @param int  $now       Current unix time.
	 */
	public static function background_due( bool $connected, int $now ): bool {
		if ( ! $connected || false !== get_transient( self::THROTTLE ) ) {
			return false;
		}
		$last = Change_Poller::last_ok();
		return null === $last || $now - $last > self::BACKGROUND_AFTER;
	}

	/**
	 * POST /sync/run.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run( $request ) {
		if ( ! $this->connection->is_connected() ) {
			return new \WP_Error( 'profotograaf_not_connected', __( 'Connect this site to Profotograaf first.', 'profotograaf' ), array( 'status' => 409 ) );
		}
		if ( (bool) $request->get_param( 'background' ) ) {
			if ( ! self::background_due( true, time() ) ) {
				return $this->answer( false, array() );
			}
			set_transient( self::THROTTLE, 1, self::BACKGROUND_THROTTLE );
		}
		return $this->answer( true, $this->poller->run() );
	}

	/**
	 * The response body.
	 *
	 * @param bool                $ran    Whether a poll was started.
	 * @param array<string,mixed> $result Result of the poll.
	 * @return array<string,mixed>
	 */
	private function answer( bool $ran, array $result ): array {
		$last = Change_Poller::last_ok();
		return array(
			'ran'             => $ran,
			'ok'              => $ran && ! empty( $result['ok'] ) && empty( $result['busy'] ) && empty( $result['paused'] ),
			'busy'            => ! empty( $result['busy'] ),
			'paused'          => ! empty( $result['paused'] ),
			'error'           => (string) ( $result['error'] ?? '' ),
			'lastChecked'     => $last ?? 0,
			'lastCheckedText' => Sync_Panel::text( $last, time() ),
		);
	}
}
