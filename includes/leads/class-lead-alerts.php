<?php
/**
 * Alerts and mail fallback for leads that did not arrive.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Leads;

use Profotograaf\Logger;
use Profotograaf\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the site admin when a lead is lost or about to be, and optionally
 * mails the lead itself to a configured address.
 *
 * The alert never contains personal data: it names the form and the time and
 * links to the Enquiry forms page. The fallback mail does contain the lead, so
 * it only goes to the address the photographer typed in for that purpose.
 */
final class Lead_Alerts {

	public const DROPPED_TRANSIENT = 'profotograaf_leads_dropped_alert';
	public const DROPPED_INTERVAL  = 3600;

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
	 * Seconds a failed lead is kept.
	 */
	public function retention(): int {
		return max( 1, (int) $this->settings->get( 'leads_failed_retention' ) ) * 86400;
	}

	/**
	 * Handles a lead that became permanently failed: one alert, and the
	 * fallback mail when it is configured and was not sent yet.
	 *
	 * @param array<string,mixed> $job The failed job.
	 * @return array<string,mixed> The job with `alerted_at` and `fallback_at` set for what went out.
	 */
	public function failed( array $job ): array {
		$at = (int) ( $job['failed_at'] ?? 0 );
		if ( empty( $job['alerted_at'] ) && $this->alert(
			__( 'An enquiry could not be delivered', 'profotograaf' ),
			array(
				$this->describe( (string) ( $job['payload']['source_form'] ?? '' ), $at ),
				__( 'It is kept on this site. Export it or try again on the Enquiry forms page.', 'profotograaf' ),
			)
		) ) {
			$job['alerted_at'] = $at;
		}
		if ( empty( $job['fallback_at'] ) && $this->fallback( (array) ( $job['payload'] ?? array() ) ) ) {
			$job['fallback_at'] = $at;
		}
		return $job;
	}

	/**
	 * Handles a lead that was not queued because the queue is full. The alert
	 * goes out once an hour, the fallback mail for every lead.
	 *
	 * @param array<string,mixed> $payload The lead.
	 * @param int                 $now     Current unix time.
	 */
	public function dropped( array $payload, int $now ): void {
		$mailed = $this->fallback( $payload );
		if ( false !== get_transient( self::DROPPED_TRANSIENT ) ) {
			return;
		}
		$lines = array(
			$this->describe( (string) ( $payload['source_form'] ?? '' ), $now ),
			__( 'The queue of enquiries is full, so this enquiry was not saved. More enquiries are lost until the queue is empty. Check the connection to Profotograaf.', 'profotograaf' ),
		);
		if ( $mailed ) {
			$lines[] = __( 'The enquiry was sent to your fallback email address.', 'profotograaf' );
		}
		if ( $this->alert( __( 'An enquiry was dropped', 'profotograaf' ), $lines ) ) {
			set_transient( self::DROPPED_TRANSIENT, $now, self::DROPPED_INTERVAL );
		}
	}

	/**
	 * Warns that failed leads were removed after their retention ran out.
	 *
	 * @param string[] $forms Form name of every lead removed.
	 * @return bool Whether the mail went out.
	 */
	public function expired( array $forms ): bool {
		$lines = array(
			sprintf(
				/* translators: %d: number of enquiries. */
				_n( '%d enquiry that could not be delivered was removed because it was kept longer than the retention period.', '%d enquiries that could not be delivered were removed because they were kept longer than the retention period.', count( $forms ), 'profotograaf' ),
				count( $forms )
			),
		);
		foreach ( array_count_values( array_map( 'strval', $forms ) ) as $form => $count ) {
			$lines[] = $count . ' x ' . ( '' !== (string) $form ? (string) $form : __( 'Unknown form', 'profotograaf' ) );
		}
		return $this->alert( __( 'Undeliverable enquiries were removed', 'profotograaf' ), $lines );
	}

	/**
	 * Where alerts go.
	 */
	private function recipient(): string {
		$configured = (string) $this->settings->get( 'leads_alert_email' );
		return '' !== $configured ? $configured : (string) get_option( 'admin_email', '' );
	}

	/**
	 * Names the form and the time.
	 *
	 * @param string $form Form name.
	 * @param int    $at   Unix time.
	 */
	private function describe( string $form, int $at ): string {
		return sprintf(
			/* translators: 1: form name, 2: date and time in UTC. */
			__( 'Form: %1$s. Time: %2$s UTC.', 'profotograaf' ),
			'' !== $form ? $form : __( 'Unknown form', 'profotograaf' ),
			gmdate( 'Y-m-d H:i', $at )
		);
	}

	/**
	 * Subject prefix with the site name.
	 */
	private function prefix(): string {
		return '[' . wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) . '] ';
	}

	/**
	 * Mails the admin.
	 *
	 * @param string   $subject Subject without the site name.
	 * @param string[] $lines   Body lines.
	 */
	private function alert( string $subject, array $lines ): bool {
		$to = $this->recipient();
		if ( '' === $to ) {
			Logger::warning( 'A lead alert has no recipient.' );
			return false;
		}
		$lines[] = admin_url( 'options-general.php?page=profotograaf-leads' );
		$sent    = (bool) wp_mail( $to, $this->prefix() . $subject, implode( "\n\n", $lines ) );
		if ( ! $sent ) {
			Logger::error( 'A lead alert could not be mailed.' );
		}
		return $sent;
	}

	/**
	 * Mails a lead to the fallback address, when there is one.
	 *
	 * @param array<string,mixed> $payload The lead.
	 */
	private function fallback( array $payload ): bool {
		$to = (string) $this->settings->get( 'leads_fallback_email' );
		if ( '' === $to ) {
			return false;
		}
		$lines = array();
		foreach ( array( 'name', 'email', 'phone', 'event_date', 'message' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ) {
				$lines[] = $key . ': ' . $payload[ $key ];
			}
		}
		foreach ( (array) ( $payload['extra_fields'] ?? array() ) as $extra ) {
			if ( is_array( $extra ) ) {
				$lines[] = (string) ( $extra['label'] ?? '' ) . ': ' . (string) ( $extra['value'] ?? '' );
			}
		}
		$form = (string) ( $payload['source_form'] ?? '' );
		$sent = (bool) wp_mail(
			$to,
			$this->prefix() . __( 'Enquiry that could not be delivered', 'profotograaf' ),
			( '' !== $form ? $form . "\n\n" : '' ) . implode( "\n", $lines )
		);
		if ( ! $sent ) {
			Logger::error( 'A lead could not be mailed to the fallback address.' );
		}
		return $sent;
	}
}
