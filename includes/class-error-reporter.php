<?php
/**
 * Turns plugin failures into scrubbed telemetry error events.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Consumes the plugin's failure hooks and its logger errors
 * (docs/telemetry.md, "Error telemetry").
 *
 * An event holds the error code, the HTTP status, the plugin location and the
 * plugin, WordPress and PHP versions. Nothing else leaves the site, and every
 * string passes through Scrubber.
 *
 * Nothing happens without consent: each entry point asks the sender first, so
 * no event is built, no state is stored and nothing is queued for a site that
 * did not opt in. Events go to the sender's queue. The daily batch sends them.
 *
 * Rate limit: a fingerprint of (code, location, status) is reported once per
 * 24 hours, and at most MAX_PER_HOUR new fingerprints are reported per hour.
 */
class Error_Reporter {

	public const STATE_OPTION = 'profotograaf_error_reporter';
	public const WINDOW       = 86400;
	public const MAX_PER_HOUR = 10;

	private const HOUR = 3600;

	/**
	 * Sender that holds the queue and the consent check.
	 *
	 * @var Telemetry_Sender
	 */
	private Telemetry_Sender $sender;

	/**
	 * Code and status pairs the logger reported during this request. The hook
	 * for the same failure fires after the log line and adds nothing new.
	 *
	 * @var array<string,bool>
	 */
	private array $logged = array();

	/**
	 * Constructor.
	 *
	 * @param Telemetry_Sender $sender Telemetry sender.
	 */
	public function __construct( Telemetry_Sender $sender ) {
		$this->sender = $sender;
	}

	/**
	 * `profotograaf_refresh_failed`.
	 *
	 * @param mixed $error The WP_Error of the refresh.
	 */
	public function on_refresh_failed( $error ): void {
		$this->report_error( $error, 'refresh_failed' );
	}

	/**
	 * `profotograaf_lead_failed`.
	 *
	 * @param mixed $payload  The lead. Never read.
	 * @param mixed $response The WP_Error of the delivery.
	 */
	public function on_lead_failed( $payload, $response ): void {
		unset( $payload );
		$this->report_error( $response, 'lead_failed' );
	}

	/**
	 * `profotograaf_lead_delivered`.
	 *
	 * @param mixed $payload  The lead. Never read.
	 * @param mixed $response The platform answer. Never read.
	 */
	public function on_lead_delivered( $payload, $response ): void {
		unset( $payload, $response );
		$this->report( 'lead_delivered', 0, false );
	}

	/**
	 * `profotograaf_lead_skipped`.
	 *
	 * @param mixed $submission The submission. Never read.
	 */
	public function on_lead_skipped( $submission ): void {
		unset( $submission );
		$this->report( 'lead_skipped', 0, false );
	}

	/**
	 * `profotograaf_lead_not_queued`.
	 *
	 * @param mixed $result     Why: `full`, `duplicate` and so on.
	 * @param mixed $submission The submission. Never read.
	 */
	public function on_lead_not_queued( $result, $submission ): void {
		unset( $submission );
		$this->report( 'lead_not_queued_' . ( is_string( $result ) ? $result : '' ), 0, false );
	}

	/**
	 * `profotograaf_disconnected`.
	 *
	 * @param mixed $reason `user` or `revoked`.
	 */
	public function on_disconnected( $reason = '' ): void {
		$this->report( 'disconnected_' . ( is_string( $reason ) ? $reason : '' ), 0, false );
	}

	/**
	 * `profotograaf_log`. Reports error-level entries, using the `error` and
	 * `status` context keys when the caller set them.
	 *
	 * @param mixed $level   Level.
	 * @param mixed $message Message. Never read.
	 * @param mixed $context Scrubbed context.
	 */
	public function on_log( $level, $message, $context = array() ): void {
		unset( $message );
		if ( Logger::ERROR !== $level ) {
			return;
		}
		$context = is_array( $context ) ? $context : array();
		$code    = isset( $context['error'] ) && is_string( $context['error'] ) && '' !== $context['error'] ? $context['error'] : 'logged_error';
		$this->report( $code, (int) ( $context['status'] ?? 0 ), true );
	}

	/**
	 * Turns `src/file.php` inside the plugin directory into `file:line`, and
	 * anything else into `unknown`.
	 *
	 * @param string $file Absolute file name.
	 * @param int    $line Line.
	 */
	public static function relative_location( string $file, int $line ): string {
		$dir = (string) PROFOTOGRAAF_DIR;
		if ( 0 !== strpos( $file, $dir ) ) {
			return 'unknown';
		}
		return substr( $file, strlen( $dir ) ) . ':' . $line;
	}

	/**
	 * The plugin file and line that raised the event: the nearest caller in the
	 * plugin outside the reporting classes.
	 */
	protected function location(): string {
		$skip = array( 'class-error-reporter.php', 'class-error-reporting.php', 'class-logger.php', 'class-scrubber.php' );
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- reads file and line only, never arguments.
			$file = isset( $frame['file'] ) ? (string) $frame['file'] : '';
			if ( '' === $file || in_array( basename( $file ), $skip, true ) ) {
				continue;
			}
			$location = self::relative_location( $file, (int) ( $frame['line'] ?? 0 ) );
			if ( 'unknown' !== $location ) {
				return $location;
			}
		}
		return 'unknown';
	}

	/**
	 * Current unix time.
	 */
	protected function now(): int {
		return time();
	}

	/**
	 * Reports the code and status of a WP_Error.
	 *
	 * @param mixed  $error    WP_Error, or anything else.
	 * @param string $fallback Code when it is not a WP_Error.
	 */
	private function report_error( $error, string $fallback ): void {
		if ( ! is_wp_error( $error ) ) {
			$this->report( $fallback, 0, false );
			return;
		}
		$data = $error->get_error_data();
		$code = (string) $error->get_error_code();
		$this->report( '' === $code ? $fallback : $code, is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0, false );
	}

	/**
	 * Builds, rate limits and queues one event. Never throws.
	 *
	 * @param string $code     Error code.
	 * @param int    $status   HTTP status, 0 without a response.
	 * @param bool   $from_log Whether the logger raised it.
	 */
	private function report( string $code, int $status, bool $from_log ): void {
		try {
			if ( ! $this->sender->is_enabled() ) {
				return;
			}
			$code   = Scrubber::code( $code );
			$status = max( 0, min( 599, $status ) );
			$pair   = $code . '|' . $status;
			if ( $from_log ) {
				$this->logged[ $pair ] = true;
			} elseif ( isset( $this->logged[ $pair ] ) ) {
				return;
			}

			$location = Scrubber::string( $this->location() );
			if ( ! $this->admit( $code . '|' . $location . '|' . $status ) ) {
				return;
			}

			$event = Scrubber::event(
				array(
					'error_code'        => $code,
					'http_status'       => $status,
					'error_location'    => $location,
					'plugin_version'    => defined( 'PROFOTOGRAAF_VERSION' ) ? (string) PROFOTOGRAAF_VERSION : '',
					'wordpress_version' => $this->major_minor( (string) get_bloginfo( 'version' ) ),
					'php_version'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
					'timestamp'         => gmdate( 'Y-m-d\TH:i:s\Z', $this->now() ),
				)
			);
			$this->sender->enqueue(
				array(
					'type'   => 'error',
					'errors' => array( $event ),
				)
			);
		} catch ( \Throwable $e ) {
			// Reporting never breaks the request it describes.
			unset( $e );
		}//end try
	}

	/**
	 * Applies the fingerprint window and the hourly cap, and records the
	 * fingerprint when it passes.
	 *
	 * @param string $source Text the fingerprint is built from.
	 */
	private function admit( string $source ): bool {
		$now   = $this->now();
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$seen  = isset( $state['seen'] ) && is_array( $state['seen'] ) ? $state['seen'] : array();
		$seen  = array_filter( $seen, fn( $time ) => is_int( $time ) && $now - $time < self::WINDOW );

		$hour_start = (int) ( $state['hour_start'] ?? 0 );
		$hour_count = (int) ( $state['hour_count'] ?? 0 );
		if ( $now - $hour_start >= self::HOUR ) {
			$hour_start = $now;
			$hour_count = 0;
		}

		$fingerprint = substr( sha1( $source ), 0, 16 );
		if ( isset( $seen[ $fingerprint ] ) || $hour_count >= self::MAX_PER_HOUR ) {
			return false;
		}

		$seen[ $fingerprint ] = $now;
		update_option(
			self::STATE_OPTION,
			array(
				'seen'       => $seen,
				'hour_start' => $hour_start,
				'hour_count' => $hour_count + 1,
			),
			false
		);
		return true;
	}

	/**
	 * Reduces `6.9.1` to `6.9`.
	 *
	 * @param string $version Version.
	 */
	private function major_minor( string $version ): string {
		return 1 === preg_match( '/^(\d+)\.(\d+)/', $version, $match ) ? $match[1] . '.' . $match[2] : '';
	}
}
