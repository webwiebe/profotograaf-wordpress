<?php
/**
 * State of the review request.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers what the site owner did with the review notice and queues the
 * events the platform wants to hear about (`shown`, `clicked`, `later`,
 * `dismissed`). The queue survives a failed status call: an event leaves it
 * only after the platform accepted it.
 *
 * The state belongs to the site, not to a user, because the platform asks one
 * question per site.
 */
class Review_Prompt {

	public const OPTION = 'profotograaf_review_state';

	/**
	 * Days "Later" hides the notice.
	 */
	public const LATER_DAYS = 14;

	/**
	 * Most events kept while the platform cannot be reached.
	 */
	private const QUEUE_LIMIT = 10;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Returns the current unix time, `time` by default.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Whether the notice shows, given the platform's stored review_prompt block.
	 *
	 * @param array{eligible:bool,reason:string,at:string} $prompt Stored block.
	 */
	public function is_due( array $prompt ): bool {
		if ( true !== $prompt['eligible'] ) {
			return false;
		}
		$state = $this->state();
		return ! $state['dismissed'] && ! $state['clicked'] && $this->now() >= $state['later_until'];
	}

	/**
	 * Records that the notice was shown. Queues `shown` once per showing
	 * period: the first time, and again after a "Later" period ended.
	 *
	 * @return bool True when `shown` was queued by this call.
	 */
	public function mark_shown(): bool {
		$state = $this->state();
		if ( $state['shown'] ) {
			return false;
		}
		$state['shown'] = true;
		$this->save( $state, 'shown' );
		return true;
	}

	/**
	 * Hides the notice for LATER_DAYS days.
	 */
	public function later(): void {
		$state                = $this->state();
		$state['later_until'] = $this->now() + self::LATER_DAYS * DAY_IN_SECONDS;
		$state['shown']       = false;
		$this->save( $state, 'later' );
	}

	/**
	 * Never asks again.
	 */
	public function dismiss(): void {
		$state              = $this->state();
		$state['dismissed'] = true;
		$this->save( $state, 'dismissed' );
	}

	/**
	 * The owner went to the review page. Never asks again.
	 */
	public function click(): void {
		$state            = $this->state();
		$state['clicked'] = true;
		$this->save( $state, 'clicked' );
	}

	/**
	 * The oldest event the platform has not accepted yet.
	 */
	public function next_event(): ?string {
		$queue = $this->state()['queue'];
		return $queue[0] ?? null;
	}

	/**
	 * Removes an event after the platform accepted it.
	 *
	 * @param string $event The event that was sent.
	 */
	public function acknowledge( string $event ): void {
		$state = $this->state();
		if ( ( $state['queue'][0] ?? null ) !== $event ) {
			return;
		}
		array_shift( $state['queue'] );
		$this->save( $state );
	}

	/**
	 * Number of events waiting for the platform.
	 */
	public function pending(): int {
		return count( $this->state()['queue'] );
	}

	/**
	 * The stored state with defaults.
	 *
	 * @return array{later_until:int,dismissed:bool,clicked:bool,shown:bool,queue:string[]}
	 */
	public function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$queue  = is_array( $stored['queue'] ?? null ) ? $stored['queue'] : array();

		return array(
			'later_until' => (int) ( $stored['later_until'] ?? 0 ),
			'dismissed'   => true === ( $stored['dismissed'] ?? false ),
			'clicked'     => true === ( $stored['clicked'] ?? false ),
			'shown'       => true === ( $stored['shown'] ?? false ),
			'queue'       => array_values( array_filter( $queue, fn( $event ) => in_array( $event, Platform_Status::REVIEW_EVENTS, true ) ) ),
		);
	}

	/**
	 * Writes the state, optionally queueing an event.
	 *
	 * @param array<string,mixed> $state State to write.
	 * @param string|null         $event Event to queue.
	 */
	private function save( array $state, ?string $event = null ): void {
		if ( null !== $event ) {
			$state['queue'][] = $event;
			$state['queue']   = array_slice( $state['queue'], -self::QUEUE_LIMIT );
		}
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->clock )();
	}
}
