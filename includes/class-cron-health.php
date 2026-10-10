<?php
/**
 * Detects hosts where WP-Cron does not run.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use Profotograaf\Leads\Delivery;
use Profotograaf\Leads\Option_Job_Store;
use Profotograaf\Leads\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Lead delivery runs from WP-Cron. A host with `DISABLE_WP_CRON` and no system
 * cron never runs it, and leads stay pending.
 *
 * Every delivery run (the hourly sweep and the single events) records a
 * heartbeat. The cron counts as stale when the last heartbeat is older than
 * STALE_AFTER, or when no heartbeat exists and the scheduled sweep is overdue
 * by that much. A site with `DISABLE_WP_CRON` and a fresh heartbeat has a
 * system cron and is healthy.
 *
 * The hourly poll of the platform change feed runs from the same cron. It has
 * its own test: the poll counts as stale when it is watched (the site is
 * connected) and the last successful run is older than STALE_AFTER. A stale
 * poll names the crontab line like a stale delivery does, and "Check now"
 * covers the gap until cron runs.
 *
 * The results show in Site Health (`site_status_tests` filter) and as a notice
 * on the settings page.
 */
final class Cron_Health {

	public const OPTION      = 'profotograaf_cron_heartbeat';
	public const STALE_AFTER = 10800;
	public const TEST        = 'profotograaf_cron';
	public const POLL_TEST   = 'profotograaf_poll';

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Returns whether WP-Cron is disabled.
	 *
	 * @var callable
	 */
	private $disabled;

	/**
	 * Returns whether the change feed poll is expected to run.
	 *
	 * @var callable
	 */
	private $watching;

	/**
	 * Constructor.
	 *
	 * @param Queue         $queue    Lead queue.
	 * @param callable|null $disabled Returns true when WP-Cron is disabled, reads `DISABLE_WP_CRON` by default.
	 * @param callable|null $watching Returns true when the change feed poll should run, true while the site is connected by default.
	 */
	public function __construct( Queue $queue, ?callable $disabled = null, ?callable $watching = null ) {
		$this->queue    = $queue;
		$this->disabled = $disabled ?? static fn(): bool => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$this->watching = $watching ?? static fn(): bool => ( new Connection() )->is_connected();
	}

	/**
	 * Health check for the lead queue stored in the options table.
	 */
	public static function for_site(): self {
		return new self( new Queue( new Option_Job_Store() ) );
	}

	/**
	 * Adds the heartbeat and the Site Health test.
	 */
	public function register(): void {
		add_action( Delivery::SWEEP, array( $this, 'beat' ), 1 );
		add_action( Delivery::HOOK, array( $this, 'beat' ), 1 );
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Records that WP-Cron ran a delivery event.
	 */
	public function beat(): void {
		update_option( self::OPTION, $this->queue->now(), false );
	}

	/**
	 * Unix time of the last delivery run, null when there was none.
	 */
	public function last_run(): ?int {
		$value = get_option( self::OPTION, 0 );
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * Whether the site sets `DISABLE_WP_CRON`.
	 */
	public function is_disabled(): bool {
		return (bool) ( $this->disabled )();
	}

	/**
	 * Where cron stands.
	 *
	 * `problem` is true when delivery cannot be relied on. `level` is the Site
	 * Health status: `good`, `recommended` (a problem, no lead waiting) or
	 * `critical` (a problem and leads waiting).
	 *
	 * @return array{disabled:bool,last_run:?int,stale:bool,pending:int,problem:bool,level:string}
	 */
	public function status(): array {
		$now      = $this->queue->now();
		$last_run = $this->last_run();
		$disabled = $this->is_disabled();
		$pending  = $this->queue->counts()['pending'];

		if ( null !== $last_run ) {
			$stale = $now - $last_run > self::STALE_AFTER;
		} else {
			$next  = wp_next_scheduled( Delivery::SWEEP );
			$stale = false !== $next && $now - (int) $next > self::STALE_AFTER;
		}
		$problem = $stale || ( $disabled && null === $last_run );

		return array(
			'disabled' => $disabled,
			'last_run' => $last_run,
			'stale'    => $stale,
			'pending'  => $pending,
			'problem'  => $problem,
			'level'    => ! $problem ? 'good' : ( $pending > 0 ? 'critical' : 'recommended' ),
		);
	}

	/**
	 * Where the change feed poll stands.
	 *
	 * `stale` is true when the poll is watched and has not succeeded for
	 * STALE_AFTER. Before the first success, a poll that is overdue by that
	 * much, or a site with `DISABLE_WP_CRON` and no heartbeat, counts as stale.
	 *
	 * @return array{watched:bool,last_ok:?int,stale:bool}
	 */
	public function poll_status(): array {
		$watched = (bool) ( $this->watching )();
		$last_ok = Change_Poller::last_ok();
		$stale   = false;
		if ( $watched ) {
			$now = $this->queue->now();
			if ( null !== $last_ok ) {
				$stale = $now - $last_ok > self::STALE_AFTER;
			} else {
				$next  = wp_next_scheduled( Modules\Change_Poll::HOOK );
				$stale = ( false !== $next && $now - (int) $next > self::STALE_AFTER ) || ( $this->is_disabled() && null === $this->last_run() );
			}
		}
		return array(
			'watched' => $watched,
			'last_ok' => $last_ok,
			'stale'   => $stale,
		);
	}

	/**
	 * Adds the tests to Site Health.
	 *
	 * @param mixed $tests Tests by type.
	 * @return mixed
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$tests['direct'][ self::TEST ]      = array(
			'label' => __( 'Profotograaf lead delivery', 'profotograaf' ),
			'test'  => array( $this, 'site_health_result' ),
		);
		$tests['direct'][ self::POLL_TEST ] = array(
			'label' => __( 'Profotograaf photo change check', 'profotograaf' ),
			'test'  => array( $this, 'poll_result' ),
		);
		return $tests;
	}

	/**
	 * Result of the Site Health test.
	 *
	 * @return array<string,mixed>
	 */
	public function site_health_result(): array {
		$status = $this->status();
		if ( ! $status['problem'] ) {
			return array(
				'label'       => __( 'Profotograaf can deliver leads', 'profotograaf' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Profotograaf', 'profotograaf' ),
					'color' => 'blue',
				),
				'description' => '<p>' . esc_html__( 'WP-Cron has run recently, so queued leads are delivered on time.', 'profotograaf' ) . '</p>',
				'actions'     => '',
				'test'        => self::TEST,
			);
		}

		return array(
			'label'       => $status['disabled']
				? __( 'WP-Cron is disabled and leads cannot be delivered', 'profotograaf' )
				: __( 'WP-Cron has stopped running and leads cannot be delivered', 'profotograaf' ),
			'status'      => $status['level'],
			'badge'       => array(
				'label' => __( 'Profotograaf', 'profotograaf' ),
				'color' => 'critical' === $status['level'] ? 'red' : 'orange',
			),
			'description' => '<p>' . esc_html( $this->explain( $status ) ) . '</p>',
			'actions'     => '<p>' . esc_html__( 'Run WordPress cron from the server. Add this line to the crontab of the site:', 'profotograaf' ) . '</p><p><code>' . esc_html( self::cron_line() ) . '</code></p>',
			'test'        => self::TEST,
		);
	}

	/**
	 * Result of the Site Health test for the photo change check.
	 *
	 * @return array<string,mixed>
	 */
	public function poll_result(): array {
		$poll = $this->poll_status();
		if ( ! $poll['stale'] ) {
			return array(
				'label'       => __( 'Profotograaf checks for photo changes', 'profotograaf' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Profotograaf', 'profotograaf' ),
					'color' => 'blue',
				),
				'description' => '<p>' . esc_html__( 'Changes to imported photos on Profotograaf are checked on time, or nothing needs checking yet.', 'profotograaf' ) . '</p>',
				'actions'     => '',
				'test'        => self::POLL_TEST,
			);
		}

		return array(
			'label'       => __( 'Profotograaf has not checked for photo changes', 'profotograaf' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Profotograaf', 'profotograaf' ),
				'color' => 'orange',
			),
			'description' => '<p>' . esc_html( $this->explain_poll() ) . '</p>',
			'actions'     => '<p>' . esc_html__( 'Use "Check now" on the Profotograaf settings page, or run WordPress cron from the server. Add this line to the crontab of the site:', 'profotograaf' ) . '</p><p><code>' . esc_html( self::cron_line() ) . '</code></p>',
			'test'        => self::POLL_TEST,
		);
	}

	/**
	 * Prints the settings page notice when leads cannot be delivered or the
	 * photo change check is stale.
	 */
	public function render_notice(): void {
		$status = $this->status();
		$leads  = $status['problem'] && $status['pending'] > 0;
		$poll   = $this->poll_status()['stale'];
		if ( ! $leads && ! $poll ) {
			return;
		}
		$messages = array();
		if ( $leads ) {
			$messages[] = $this->explain( $status );
		}
		if ( $poll ) {
			$messages[] = $this->explain_poll();
		}
		printf(
			'<div class="notice notice-warning">%1$s<p>%2$s <code>%3$s</code></p></div>',
			'<p>' . implode( '</p><p>', array_map( 'esc_html', $messages ) ) . '</p>',
			esc_html__( 'Run WordPress cron from the server with this crontab line:', 'profotograaf' ),
			esc_html( self::cron_line() )
		);
	}

	/**
	 * The crontab line that runs due WordPress events every five minutes.
	 */
	public static function cron_line(): string {
		return '*/5 * * * * curl -fsS "' . site_url( 'wp-cron.php' ) . '?doing_wp_cron" > /dev/null 2>&1';
	}

	/**
	 * One sentence on what is wrong.
	 *
	 * @param array{disabled:bool,last_run:?int,stale:bool,pending:int,problem:bool,level:string} $status Result of status().
	 */
	private function explain( array $status ): string {
		$reason = $status['disabled']
			? __( 'This site sets DISABLE_WP_CRON and no scheduled run has been seen.', 'profotograaf' )
			: __( 'No scheduled run has been seen for more than three hours.', 'profotograaf' );
		if ( $status['pending'] < 1 ) {
			return $reason;
		}
		return $reason . ' ' . sprintf(
			/* translators: %d: number of leads waiting for delivery. */
			_n( '%d lead is waiting for delivery.', '%d leads are waiting for delivery.', $status['pending'], 'profotograaf' ),
			$status['pending']
		);
	}

	/**
	 * One sentence on why the photo change check is stale.
	 */
	private function explain_poll(): string {
		return $this->is_disabled()
			? __( 'This site sets DISABLE_WP_CRON, so photo changes on Profotograaf have not been checked for more than three hours.', 'profotograaf' )
			: __( 'Photo changes on Profotograaf have not been checked for more than three hours.', 'profotograaf' );
	}
}
