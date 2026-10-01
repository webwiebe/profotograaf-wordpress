<?php
/**
 * The telemetry sender.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Counts outcomes, queues the daily usage batch and sends it to the endpoint
 * (docs/telemetry.md, "Authentication and Batching").
 *
 * Every entry point checks consent first, so a site that did not opt in never
 * counts, queues or sends anything, and a revoked site stops at once. A
 * request that carries Do Not Track or Global Privacy Control keeps its queue
 * and sends nothing.
 */
class Telemetry_Sender {

	public const QUEUE_OPTION     = 'profotograaf_telemetry_queued_batches';
	public const COUNTER_OPTION   = 'profotograaf_telemetry_queued_counters';
	public const ATTEMPT_OPTION   = 'profotograaf_telemetry_queued_attempts';
	public const ERRORS_OPTION    = 'profotograaf_telemetry_queued_errors';
	public const BATCH_HOOK       = 'profotograaf_send_telemetry_batch';
	public const RETRY_HOOK       = 'profotograaf_retry_telemetry_batch';
	public const DEFAULT_ENDPOINT = 'https://bugbarn.wiebe.xyz/api/v1/ingest';
	public const MAX_ATTEMPTS     = 3;
	public const MAX_QUEUED       = 7;
	public const BACKOFF          = array( 5, 10, 30 );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Connection, the source of the install id.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings   Settings.
	 * @param Connection|null $connection Connection.
	 */
	public function __construct( Settings $settings, ?Connection $connection = null ) {
		$this->settings   = $settings;
		$this->connection = $connection ?? new Connection();
	}

	/**
	 * Listens for the outcomes that are counted.
	 */
	public function listen(): void {
		add_action( 'profotograaf_refresh_failed', array( $this, 'on_refresh_failed' ) );
		add_action( 'profotograaf_lead_delivered', array( $this, 'on_lead_delivered' ) );
		add_action( 'profotograaf_lead_failed', array( $this, 'on_lead_failed' ), 10, 2 );
	}

	/**
	 * Counts a failed token refresh.
	 *
	 * @param mixed $error The error.
	 */
	public function on_refresh_failed( $error = null ): void {
		$this->count( 'refresh_failed', $error );
	}

	/**
	 * Counts a delivered lead.
	 */
	public function on_lead_delivered(): void {
		$this->count( 'delivery_success' );
	}

	/**
	 * Counts a lead that failed for good.
	 *
	 * @param mixed $payload Lead payload. Never read.
	 * @param mixed $error   The error.
	 */
	public function on_lead_failed( $payload = null, $error = null ): void {
		unset( $payload );
		$this->count( 'delivery_failed', $error );
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
	 * Whether the current request asks not to be tracked. WP-Cron requests
	 * carry no such header.
	 */
	public function request_opts_out(): bool {
		foreach ( array( 'HTTP_DNT', 'HTTP_SEC_GPC' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) && '1' === sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The endpoint, or an empty string when telemetry has no destination.
	 */
	public function endpoint(): string {
		/**
		 * Filters the telemetry endpoint. An empty string disables sending.
		 *
		 * @param string $url Endpoint URL.
		 */
		$url    = apply_filters( 'profotograaf_telemetry_endpoint', self::DEFAULT_ENDPOINT );
		$url    = trim( (string) $url );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		return '' !== $host && in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}

	/**
	 * The usage payload for the counts since the last batch.
	 *
	 * @return array<string,mixed>
	 */
	public function payload(): array {
		$counters = $this->counters();
		return Telemetry_Payload::build(
			array_merge(
				$counters,
				array(
					'errors'            => $this->pending_errors(),
					'install_id'        => $this->connection->install_id(),
					'plugin_version'    => defined( 'PROFOTOGRAAF_VERSION' ) ? PROFOTOGRAAF_VERSION : '',
					'wordpress_version' => get_bloginfo( 'version' ),
					'php_version'       => PHP_VERSION,
					'locale'            => get_locale(),
					'active_modules'    => $this->active_modules(),
				)
			)
		);
	}

	/**
	 * Daily job: queues the batch for the last 24 hours and sends the queue.
	 *
	 * @return int Number of batches sent. 0 without consent.
	 */
	public function run(): int {
		if ( ! $this->is_enabled() ) {
			return 0;
		}
		$this->enqueue( $this->payload() );
		delete_option( self::COUNTER_OPTION );
		delete_option( self::ERRORS_OPTION );
		return $this->send();
	}

	/**
	 * Cron callback for the daily batch.
	 */
	public function cron_run(): void {
		$this->run();
	}

	/**
	 * Cron callback for a retry.
	 */
	public function cron_retry(): void {
		$this->send();
	}

	/**
	 * Adds a batch to the queue. The newest MAX_QUEUED batches are kept.
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
		update_option( self::QUEUE_OPTION, array_slice( $queue, -self::MAX_QUEUED ), false );
		return true;
	}

	/**
	 * Adds an error event to the next daily batch. The newest
	 * Telemetry_Payload::MAX_ERRORS events are kept.
	 *
	 * @param array<string,mixed> $event Error event.
	 * @return bool Whether it was stored. False without consent.
	 */
	public function add_error( array $event ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}
		$events   = $this->pending_errors();
		$events[] = $event;
		update_option( self::ERRORS_OPTION, array_slice( $events, -Telemetry_Payload::MAX_ERRORS ), false );
		return true;
	}

	/**
	 * Error events waiting for the next daily batch.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function pending_errors(): array {
		$stored = get_option( self::ERRORS_OPTION, array() );
		return is_array( $stored ) ? array_values( $stored ) : array();
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
	 * Deletes every queued batch and the counts waiting for the next one.
	 */
	public function clear_queue(): void {
		delete_option( self::QUEUE_OPTION );
		delete_option( self::COUNTER_OPTION );
		delete_option( self::ERRORS_OPTION );
		delete_option( self::ATTEMPT_OPTION );
	}

	/**
	 * Replaces the install id with a new random one, so telemetry sent before
	 * a disconnect no longer belongs to this site.
	 */
	public function rotate_install_id(): void {
		update_option( Connection::INSTALL_ID, wp_generate_uuid4(), false );
	}

	/**
	 * Sends the queued batches. A failure keeps the batch, schedules a retry
	 * after 5, 10 and then 30 seconds, and drops the queue after the third
	 * failed attempt.
	 *
	 * @return int Number of batches sent. Always 0 without consent, with
	 *             Do Not Track or Global Privacy Control, or without an endpoint.
	 */
	public function send(): int {
		if ( ! $this->is_enabled() || $this->request_opts_out() || '' === $this->endpoint() ) {
			return 0;
		}
		$sent  = 0;
		$queue = $this->queue();
		foreach ( $queue as $batch ) {
			if ( ! $this->dispatch( $batch ) ) {
				$this->failed();
				return $sent;
			}
			++$sent;
			update_option( self::QUEUE_OPTION, array_slice( $queue, $sent ), false );
		}
		delete_option( self::QUEUE_OPTION );
		delete_option( self::ATTEMPT_OPTION );
		return $sent;
	}

	/**
	 * Delivers one batch.
	 *
	 * @param array<string,mixed> $batch Batch payload.
	 * @return bool Whether the endpoint accepted the batch.
	 */
	protected function dispatch( array $batch ): bool {
		$endpoint = $this->endpoint();
		if ( '' === $endpoint ) {
			return false;
		}
		$headers = array( 'Content-Type' => 'application/json' );
		/**
		 * Filters the bearer token for the telemetry endpoint. Empty sends no
		 * Authorization header.
		 *
		 * @param string $token Token.
		 */
		$token = (string) apply_filters( 'profotograaf_telemetry_token', '' );
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => Config::http_timeout(),
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => (string) wp_json_encode( Telemetry_Payload::build( $batch ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			Logger::warning( 'Telemetry could not be sent.', array( 'error' => $response->get_error_code() ) );
			return false;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			Logger::warning( 'The telemetry endpoint refused a batch.', array( 'status' => $status ) );
			return false;
		}
		return true;
	}

	/**
	 * Records a failed attempt and schedules the retry, or gives up.
	 */
	private function failed(): void {
		$attempts = (int) get_option( self::ATTEMPT_OPTION, 0 ) + 1;
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			delete_option( self::QUEUE_OPTION );
			delete_option( self::ATTEMPT_OPTION );
			return;
		}
		update_option( self::ATTEMPT_OPTION, $attempts, false );
		wp_schedule_single_event( time() + self::BACKOFF[ $attempts - 1 ], self::RETRY_HOOK );
	}

	/**
	 * Adds one to a counter and, for an error, to its code. Does nothing
	 * without consent.
	 *
	 * @param string $counter Counter name from Telemetry_Payload::COUNTERS.
	 * @param mixed  $error   Optional WP_Error whose code is counted.
	 */
	private function count( string $counter, $error = null ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$counters             = $this->counters();
		$counters[ $counter ] = ( $counters[ $counter ] ?? 0 ) + 1;
		if ( $error instanceof \WP_Error ) {
			$code = sanitize_key( (string) $error->get_error_code() );
			if ( '' !== $code ) {
				$counters['error_codes'][ $code ] = ( $counters['error_codes'][ $code ] ?? 0 ) + 1;
			}
		}
		update_option( self::COUNTER_OPTION, $counters, false );
	}

	/**
	 * Counts waiting for the next batch.
	 *
	 * @return array<string,mixed>
	 */
	private function counters(): array {
		$stored   = get_option( self::COUNTER_OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$counters = array( 'error_codes' => is_array( $stored['error_codes'] ?? null ) ? $stored['error_codes'] : array() );
		foreach ( Telemetry_Payload::COUNTERS as $name ) {
			$counters[ $name ] = (int) ( $stored[ $name ] ?? 0 );
		}
		return $counters;
	}

	/**
	 * Slugs of the registered modules, for example `gallery_embed`.
	 *
	 * @return string[]
	 */
	private function active_modules(): array {
		$slugs  = array();
		$loader = new Module_Loader( PROFOTOGRAAF_DIR . 'includes/modules' );
		foreach ( $loader->classes() as $class ) {
			$slugs[] = strtolower( substr( (string) strrchr( '\\' . $class, '\\' ), 1 ) );
		}
		return $slugs;
	}
}
