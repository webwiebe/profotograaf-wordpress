<?php
/**
 * CSV export of failed leads.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Turns failed jobs into CSV text. The file holds the personal data of the
 * leads, so only the admin screen calls it, behind a capability check and a
 * nonce.
 */
final class Failed_Export {

	/**
	 * Column names, in order.
	 *
	 * @return string[]
	 */
	public static function columns(): array {
		return array( 'received', 'failed', 'form', 'name', 'email', 'phone', 'event_date', 'message', 'other_fields', 'error', 'http_status', 'attempts' );
	}

	/**
	 * The CSV text, with a byte order mark so spreadsheets read it as UTF-8.
	 *
	 * @param array<string,array<string,mixed>> $jobs Failed jobs.
	 */
	public static function build( array $jobs ): string {
		$out = "\xEF\xBB\xBF" . self::line( self::columns() );
		foreach ( $jobs as $job ) {
			$out .= self::line( self::cells( $job ) );
		}
		return $out;
	}

	/**
	 * The cells of one failed job, in the order of columns().
	 *
	 * @param array<string,mixed> $job Failed job.
	 * @return string[]
	 */
	private static function cells( array $job ): array {
		$payload = (array) ( $job['payload'] ?? array() );
		$cells   = array(
			self::date( (int) ( $job['created_at'] ?? 0 ) ),
			self::date( (int) ( $job['failed_at'] ?? 0 ) ),
		);
		foreach ( array( 'source_form', 'name', 'email', 'phone', 'event_date', 'message' ) as $key ) {
			$cells[] = (string) ( $payload[ $key ] ?? '' );
		}
		$cells[] = self::other_fields( $payload );
		$cells[] = (string) ( $job['last_error'] ?? '' );
		$cells[] = (string) (int) ( $job['last_status'] ?? 0 );
		$cells[] = (string) (int) ( $job['attempts'] ?? 0 );
		return $cells;
	}

	/**
	 * The extra form fields as "label: value" lines.
	 *
	 * @param array<string,mixed> $payload Enquiry payload.
	 */
	private static function other_fields( array $payload ): string {
		$extra = array();
		foreach ( (array) ( $payload['extra_fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) ) {
				$extra[] = (string) ( $field['label'] ?? '' ) . ': ' . (string) ( $field['value'] ?? '' );
			}
		}
		return implode( "\n", $extra );
	}

	/**
	 * One CSV line.
	 *
	 * @param string[] $cells Cell values.
	 */
	private static function line( array $cells ): string {
		return implode( ',', array_map( array( self::class, 'cell' ), $cells ) ) . "\r\n";
	}

	/**
	 * One quoted cell. A value that starts like a spreadsheet formula gets a
	 * leading quote so the spreadsheet shows it as text.
	 *
	 * @param string $value Cell value.
	 */
	private static function cell( string $value ): string {
		if ( 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			$value = "'" . $value;
		}
		return '"' . str_replace( '"', '""', $value ) . '"';
	}

	/**
	 * A UTC timestamp, empty when unknown.
	 *
	 * @param int $time Unix time.
	 */
	private static function date( int $time ): string {
		return $time > 0 ? gmdate( 'Y-m-d H:i:s', $time ) . ' UTC' : '';
	}
}
