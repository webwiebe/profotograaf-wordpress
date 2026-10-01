<?php
/**
 * Diagnostic log for API, sync and delivery failures.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Records what went wrong so a support request can be diagnosed.
 *
 * Each entry goes to three places: the `profotograaf_log` action, `debug.log`
 * when `WP_DEBUG_LOG` is on, and a ring buffer of the last 100 entries kept in
 * a non-autoloaded option for an admin screen.
 *
 * Entries never hold tokens or personal data. Context keys that name such data
 * are replaced, nested values are dropped, and every string is scrubbed of
 * email addresses, bearer tokens, phone numbers and long token-like runs
 * before it is stored or passed on. Callers should still pass identifiers and
 * counts, never lead field values.
 */
final class Logger {

	public const OPTION   = 'profotograaf_log';
	public const CAPACITY = 100;

	public const ERROR   = 'error';
	public const WARNING = 'warning';
	public const INFO    = 'info';
	public const DEBUG   = 'debug';

	private const REDACTED = '[redacted]';

	private const SENSITIVE_KEYS = '/token|secret|password|passwd|authorization|bearer|cookie|email|name|phone|message|body|payload|field|address|value|url/i';

	/**
	 * Forces writing to debug.log on or off. Null follows WP_DEBUG_LOG.
	 *
	 * @var bool|null
	 */
	private static ?bool $file_logging = null;

	/**
	 * Receives the line for debug.log, `error_log` by default.
	 *
	 * @var callable|null
	 */
	private static $writer = null;

	/**
	 * Overrides where and whether debug.log lines are written. For tests.
	 *
	 * @param bool|null     $enabled Null follows WP_DEBUG_LOG.
	 * @param callable|null $writer  Receives each line, `error_log` when null.
	 */
	public static function configure( ?bool $enabled, ?callable $writer = null ): void {
		self::$file_logging = $enabled;
		self::$writer       = $writer;
	}

	/**
	 * Logs an error.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Identifiers and counts.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( self::ERROR, $message, $context );
	}

	/**
	 * Logs a warning.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Identifiers and counts.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( self::WARNING, $message, $context );
	}

	/**
	 * Logs a notice.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Identifiers and counts.
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( self::INFO, $message, $context );
	}

	/**
	 * Logs a debugging detail.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Identifiers and counts.
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::log( self::DEBUG, $message, $context );
	}

	/**
	 * Logs a Throwable by class and scrubbed message, never its trace.
	 *
	 * @param string              $message Message.
	 * @param \Throwable          $e       What was caught.
	 * @param array<string,mixed> $context Identifiers and counts.
	 */
	public static function exception( string $message, \Throwable $e, array $context = array() ): void {
		$context['exception'] = get_class( $e );
		$context['reason']    = $e->getMessage();
		self::log( self::ERROR, $message, $context );
	}

	/**
	 * Writes one entry. Never throws.
	 *
	 * @param string              $level   One of the level constants.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Identifiers and counts.
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		try {
			$entry = array(
				'time'    => time(),
				'level'   => $level,
				'message' => self::scrub_string( $message ),
				'context' => self::scrub_context( $context ),
			);

			self::remember( $entry );
			self::write_line( $entry );

			/**
			 * Fires for every log entry, after scrubbing.
			 *
			 * @param string              $level   Level.
			 * @param string              $message Message.
			 * @param array<string,mixed> $context Scrubbed context.
			 */
			do_action( 'profotograaf_log', $entry['level'], $entry['message'], $entry['context'] );
		} catch ( \Throwable $e ) {
			// Logging never breaks the request it describes.
			unset( $e );
		}//end try
	}

	/**
	 * The stored entries, oldest first.
	 *
	 * @return array<int,array{time:int,level:string,message:string,context:array<string,mixed>}>
	 */
	public static function entries(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * Empties the ring buffer.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Replaces sensitive context and scrubs the rest.
	 *
	 * @param array<string,mixed> $context Context.
	 * @return array<string,mixed>
	 */
	public static function scrub_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$key = (string) $key;
			if ( 1 === preg_match( self::SENSITIVE_KEYS, $key ) ) {
				$clean[ $key ] = self::REDACTED;
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
				$clean[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = self::scrub_string( $value );
			} else {
				$clean[ $key ] = self::REDACTED;
			}
		}
		return $clean;
	}

	/**
	 * Removes email addresses, bearer tokens, token-like runs and phone numbers.
	 *
	 * @param string $text Text.
	 */
	public static function scrub_string( string $text ): string {
		$text = (string) preg_replace( '/\bBearer\s+\S+/i', 'Bearer ' . self::REDACTED, $text );
		$text = (string) preg_replace( '/[^\s@<>"\']+@[^\s@<>"\']+\.[^\s@<>"\']+/', self::REDACTED, $text );
		$text = (string) preg_replace( '/[A-Za-z0-9_\-+=]{24,}/', self::REDACTED, $text );
		$text = (string) preg_replace( '/\+?\d[\d\s().\-]{7,}\d/', self::REDACTED, $text );
		return mb_substr( $text, 0, 500 );
	}

	/**
	 * Appends to the ring buffer, dropping the oldest entries past the cap.
	 *
	 * @param array<string,mixed> $entry Entry.
	 */
	private static function remember( array $entry ): void {
		$entries   = self::entries();
		$entries[] = $entry;
		$entries   = array_slice( $entries, -self::CAPACITY );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $entries, '', false );
			return;
		}
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Writes the entry to debug.log when WP_DEBUG_LOG is on.
	 *
	 * @param array<string,mixed> $entry Entry.
	 */
	private static function write_line( array $entry ): void {
		$enabled = self::$file_logging ?? ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
		if ( ! $enabled ) {
			return;
		}
		$line = sprintf(
			'[profotograaf] %s: %s %s',
			strtoupper( (string) $entry['level'] ),
			(string) $entry['message'],
			(string) wp_json_encode( $entry['context'] )
		);
		if ( null !== self::$writer ) {
			( self::$writer )( $line );
			return;
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the opt-in debug.log sink.
	}
}
