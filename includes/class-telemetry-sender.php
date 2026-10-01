<?php
/**
 * The telemetry sender skeleton.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the consent check and the queue of telemetry batches. Nothing here
 * sends data yet: dispatch() has no destination until the payload and the
 * endpoint are added (docs/telemetry.md, "Authentication and Batching").
 *
 * Every entry point checks consent first, so a site that did not opt in never
 * queues or sends anything, and a revoked site stops at once.
 */
class Telemetry_Sender {

	public const QUEUE_OPTION = 'profotograaf_telemetry_queued_batches';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the owner opted in and the host allows it. The filter can turn
	 * telemetry off. It cannot turn it on without the owner's consent.
	 */
	public function is_enabled(): bool {
		if ( true !== $this->settings->get( 'telemetry_enabled' ) ) {
			return false;
		}
		/**
		 * Lets a host force telemetry off.
		 *
		 * @param bool $enabled Whether the owner opted in.
		 */
		return (bool) apply_filters( 'profotograaf_telemetry_enabled', true );
	}

	/**
	 * Adds a batch to the queue.
	 *
	 * @param array<string,mixed> $batch Batch payload.
	 * @return bool Whether it was queued. False without consent.
	 */
	public function enqueue( array $batch ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}
		$queue   = $this->queue();
		$queue[] = $batch;
		update_option( self::QUEUE_OPTION, $queue, false );
		return true;
	}

	/**
	 * Batches waiting to be sent.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function queue(): array {
		$stored = get_option( self::QUEUE_OPTION, array() );
		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * Deletes every queued batch.
	 */
	public function clear_queue(): void {
		delete_option( self::QUEUE_OPTION );
	}

	/**
	 * Sends the queued batches.
	 *
	 * @return int Number of batches sent. Always 0 without consent.
	 */
	public function send(): int {
		if ( ! $this->is_enabled() ) {
			return 0;
		}
		$sent = 0;
		foreach ( $this->queue() as $batch ) {
			if ( ! $this->dispatch( $batch ) ) {
				break;
			}
			++$sent;
		}
		return $sent;
	}

	/**
	 * Delivers one batch. No destination exists yet, so nothing is sent.
	 *
	 * @param array<string,mixed> $batch Batch payload.
	 * @return bool Whether the batch was delivered.
	 */
	protected function dispatch( array $batch ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the payload and endpoint arrive with a later change.
		return false;
	}
}
