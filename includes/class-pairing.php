<?php
/**
 * Device pairing (RFC 8628 device authorization grant).
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Connects this site to a Profotograaf account without a password ever
 * reaching WordPress.
 *
 * 1. start() asks the platform for a short user code and an approval page.
 * 2. The photographer opens the approval page, signs in there and confirms.
 * 3. poll() asks for tokens at the interval the platform returned until the
 *    grant is approved, denied or expired.
 */
class Pairing {

	/**
	 * Seconds added to the polling interval when the platform says to slow down.
	 */
	private const SLOW_DOWN_STEP = 5;

	/**
	 * Longest site address the platform accepts for the account prefill.
	 */
	private const SITE_URL_MAX_LENGTH = 300;

	/**
	 * Languages the platform's sign-up form is available in.
	 */
	private const PREFILL_LANGUAGES = array( 'en', 'nl', 'de', 'fr' );

	/**
	 * Stored connection state.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Connection    $connection Stored connection state.
	 * @param Api_Client    $api        API client.
	 * @param callable|null $clock      Returns the current unix time, `time` by default.
	 */
	public function __construct( Connection $connection, Api_Client $api, ?callable $clock = null ) {
		$this->connection = $connection;
		$this->api        = $api;
		$this->clock      = $clock ?? 'time';
	}

	/**
	 * Starts a pairing and remembers it.
	 *
	 * @param array<int,string> $extra_scopes Scopes to request on top of Config::SCOPES.
	 * @return array{user_code:string,verification_uri:string,expires_at:int,interval:int}|WP_Error
	 */
	public function start( array $extra_scopes = array() ) {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$result = $this->api->public_request(
			'POST',
			'/api/v1/auth/devices/initiate',
			array(
				'client_id'   => Config::CLIENT_ID,
				'device_id'   => $this->connection->install_id(),
				'name'        => (string) get_bloginfo( 'name' ),
				'platform'    => Config::CLIENT_ID,
				'app_version' => defined( 'PROFOTOGRAAF_VERSION' ) ? PROFOTOGRAAF_VERSION : '',
				'wp_version'  => (string) get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'hostname'    => $host,
				'scope'       => implode( ' ', array_values( array_unique( array_merge( Config::SCOPES, array_map( 'strval', $extra_scopes ) ) ) ) ),
			) + $this->prefill()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$body = $result['body'];
		if ( 201 !== $result['status'] || empty( $body['device_code'] ) || empty( $body['user_code'] ) ) {
			return new WP_Error(
				'profotograaf_pairing_failed',
				__( 'Profotograaf did not start the connection. Try again in a moment.', 'profotograaf' ),
				array(
					'status'    => $result['status'],
					'retryable' => $result['status'] >= 500 || 429 === $result['status'],
				)
			);
		}

		$uri = (string) ( $body['verification_uri_complete'] ?? $body['verification_uri'] ?? '' );
		if ( '' === $uri ) {
			$uri = Config::platform_endpoint( '/app/devices/approve' );
		}
		$pairing = array(
			'device_code'      => (string) $body['device_code'],
			'user_code'        => (string) $body['user_code'],
			'verification_uri' => $uri,
			'expires_at'       => $this->now() + max( 1, (int) ( $body['expires_in'] ?? 600 ) ),
			'interval'         => max( 1, (int) ( $body['interval'] ?? 5 ) ),
		);
		$this->connection->save_pairing( $pairing );

		return array(
			'user_code'        => $pairing['user_code'],
			'verification_uri' => $pairing['verification_uri'],
			'expires_at'       => $pairing['expires_at'],
			'interval'         => $pairing['interval'],
		);
	}

	/**
	 * Site details the platform uses to prefill a new account.
	 *
	 * The platform drops a field that fails its validation, so a field that
	 * would fail is left out here.
	 *
	 * @return array<string,string>
	 */
	private function prefill(): array {
		$fields = array();

		$site_url = (string) home_url();
		$scheme   = strtolower( (string) wp_parse_url( $site_url, PHP_URL_SCHEME ) );
		if ( in_array( $scheme, array( 'http', 'https' ), true ) && '' !== (string) wp_parse_url( $site_url, PHP_URL_HOST ) && strlen( $site_url ) <= self::SITE_URL_MAX_LENGTH ) {
			$fields['site_url'] = $site_url;
		}

		$site_name = trim( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' !== $site_name ) {
			$fields['site_name'] = $site_name;
		}

		$email = (string) get_option( 'admin_email', '' );
		if ( '' !== $email && is_email( $email ) ) {
			$fields['email'] = $email;
		}

		$language = strtolower( (string) strtok( (string) get_locale(), '_' ) );
		if ( in_array( $language, self::PREFILL_LANGUAGES, true ) ) {
			$fields['locale'] = $language;
		}

		return $fields;
	}

	/**
	 * Asks the platform once whether the photographer approved.
	 *
	 * Status is one of: none (no pairing running), pending, approved, denied,
	 * expired. `interval` is the number of seconds to wait before the next
	 * call. A WP_Error means this poll failed and a later one can still succeed.
	 *
	 * @return array{status:string,interval:int}|WP_Error
	 */
	public function poll() {
		$pairing = $this->connection->pairing();
		if ( null === $pairing ) {
			return array(
				'status'   => $this->connection->is_connected() ? 'approved' : 'none',
				'interval' => 0,
			);
		}
		if ( $this->now() > $pairing['expires_at'] ) {
			$this->connection->clear_pairing();
			return array(
				'status'   => 'expired',
				'interval' => 0,
			);
		}

		$result = $this->api->public_request( 'POST', '/api/v1/auth/devices/token', array( 'device_code' => $pairing['device_code'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = $result['status'];
		$body   = $result['body'];

		if ( 200 === $status ) {
			return $this->handle_status( (string) ( $body['status'] ?? '' ), $body, $pairing );
		}

		$code = (string) ( $body['code'] ?? '' );
		if ( 400 === $status && 'device.slow_down' === $code ) {
			$pairing['interval'] += self::SLOW_DOWN_STEP;
			$this->connection->save_pairing( $pairing );
			return array(
				'status'   => 'pending',
				'interval' => $pairing['interval'],
			);
		}
		if ( 409 === $status && $this->connection->is_connected() ) {
			// A second browser tab polled after the first one collected the tokens.
			return array(
				'status'   => 'approved',
				'interval' => 0,
			);
		}
		if ( 404 === $status ) {
			$this->connection->clear_pairing();
			return array(
				'status'   => 'expired',
				'interval' => 0,
			);
		}

		return new WP_Error(
			'profotograaf_poll_failed',
			__( 'Could not check the connection. Trying again.', 'profotograaf' ),
			array(
				'status'    => $status,
				'retryable' => true,
			)
		);
	}

	/**
	 * Handles the `status` field of a 200 poll answer.
	 *
	 * @param string              $status  Status from the platform.
	 * @param array<string,mixed> $body    Decoded body.
	 * @param array<string,mixed> $pairing Stored pairing.
	 * @return array{status:string,interval:int}|WP_Error
	 */
	private function handle_status( string $status, array $body, array $pairing ) {
		switch ( $status ) {
			case 'approved':
				if ( empty( $body['access_token'] ) || empty( $body['refresh_token'] ) ) {
					return new WP_Error(
						'profotograaf_poll_failed',
						__( 'Profotograaf approved the connection but sent no tokens. Start again.', 'profotograaf' ),
						array(
							'status'    => 200,
							'retryable' => false,
						)
					);
				}
				$this->connection->save_tokens( $body, $this->now() );
				$this->connection->record_grant( Config::SCOPE_REVISION );
				$this->connection->clear_pairing();

				/**
				 * Fires once after the site connected to a Profotograaf account.
				 */
				do_action( 'profotograaf_connected' );
				return array(
					'status'   => 'approved',
					'interval' => 0,
				);

			case 'denied':
			case 'expired':
				$this->connection->clear_pairing();
				return array(
					'status'   => $status,
					'interval' => 0,
				);

			default:
				return array(
					'status'   => 'pending',
					'interval' => (int) $pairing['interval'],
				);
		}//end switch
	}

	/**
	 * Abandons the pairing in progress.
	 */
	public function cancel(): void {
		$this->connection->clear_pairing();
	}

	/**
	 * Ends the connection: asks the platform to revoke, then forgets the tokens.
	 *
	 * @return bool True when the platform confirmed the revoke.
	 */
	public function disconnect(): bool {
		$revoked = $this->api->sign_out();
		$this->connection->clear();
		$this->connection->clear_pairing();

		/**
		 * Fires after the site disconnected.
		 *
		 * @param string $reason Why: "user" when the photographer disconnected.
		 */
		do_action( 'profotograaf_disconnected', 'user' );
		return $revoked;
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->clock )();
	}
}
