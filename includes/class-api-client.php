<?php
/**
 * Authenticated client for the Profotograaf API.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The one way modules talk to the platform.
 *
 * Every method returns data or a WP_Error and never throws, so a slow or
 * broken platform cannot take a front-end request down. Every request has a
 * timeout (see Config::http_timeout()). Access tokens are refreshed
 * automatically: before a request when they are about to expire, and once more
 * after a 401.
 *
 * Errors carry these WP_Error codes:
 *
 *  - profotograaf_not_connected: no token pair is stored, or the platform ended it.
 *  - profotograaf_network: the request did not complete (timeout, DNS, TLS).
 *  - profotograaf_http: the platform answered 400 or above.
 *  - profotograaf_invalid: the payload was rejected before sending.
 *  - profotograaf_reconnect: the platform refused a call because the token lacks a
 *    permission the plugin now asks for; connecting again grants it.
 *  - profotograaf_storage_full: an upload got 413, the account is at its storage cap.
 *  - profotograaf_refresh_busy: another request is refreshing the token.
 *
 * The error data is always an array with `status` (HTTP status, 0 when no
 * response came) and `retryable` (true when trying again later can succeed:
 * network errors, 408, 429 and 5xx). HTTP errors add `code` (the platform's
 * machine readable code) and `retry_after` (seconds, 0 when not given).
 */
class Api_Client {

	use Api_Client_Writes;

	/**
	 * Seconds before expiry at which a token counts as expired.
	 */
	private const SKEW = 30;

	/**
	 * Seconds an upload may take.
	 */
	private const UPLOAD_TIMEOUT = 120;

	private const LEAD_FIELDS = array(
		'name',
		'email',
		'phone',
		'event_date',
		'event_type',
		'message',
		'source',
		'source_form',
		'page_url',
		'extra_fields',
	);

	/**
	 * Stored connection state.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Network layer.
	 *
	 * @var Transport
	 */
	private Transport $transport;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Connection     $connection Stored connection state.
	 * @param Transport|null $transport  Network layer, the WordPress HTTP API by default.
	 * @param callable|null  $clock      Returns the current unix time, `time` by default.
	 */
	public function __construct( Connection $connection, ?Transport $transport = null, ?callable $clock = null ) {
		$this->connection = $connection;
		$this->transport  = $transport ?? new Wp_Transport();
		$this->clock      = $clock ?? 'time';
	}

	/**
	 * Lists the photographer's galleries for a picker.
	 *
	 * Needs the galleries:read scope. `available` says whether the public
	 * embed route serves the gallery right now; `embeddable` is the setting.
	 * `cover_url` is a presigned thumbnail that expires after a few minutes.
	 * `cover_alt` is the alt text of that photo when the platform sends one.
	 *
	 * @return array<int,array{id:string,slug:string,title:string,url:string,embeddable:bool,available:bool,photo_count:int,cover_url:string,cover_alt:string,updated_at:string}>|WP_Error
	 */
	public function list_galleries() {
		$body = $this->request( 'GET', '/api/v1/embed/galleries' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$rows = array();
		foreach ( is_array( $body ) ? $body : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$rows[] = array(
				'id'           => (string) $row['id'],
				'slug'         => (string) ( $row['slug'] ?? '' ),
				'title'        => (string) ( $row['title'] ?? '' ),
				'url'          => (string) ( $row['url'] ?? '' ),
				'embeddable'   => ! empty( $row['embeddable'] ),
				'available'    => ! empty( $row['available'] ),
				'photo_count'  => (int) ( $row['photo_count'] ?? 0 ),
				'cover_url'    => (string) ( $row['cover_url'] ?? '' ),
				'cover_alt'    => (string) ( $row['cover_alt'] ?? '' ),
				'layout'       => Layout_Map::clean( $row['layout'] ?? '' ),
				'embed_layout' => Layout_Map::clean( $row['embed_layout'] ?? '' ),
				'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
			);
		}
		return $rows;
	}

	/**
	 * Sends a lead to the photographer's inbox.
	 *
	 * Needs the leads:write scope. `name` and `email` are required, the rest is
	 * optional: phone, event_date, event_type, message (8000 characters at
	 * most), source (always the plugin's source id), source_form, page_url and extra_fields
	 * (a list of label and value pairs, 30 at most). Unknown keys are dropped.
	 *
	 * A repeated post of the same email and message within ten minutes answers
	 * with `duplicate` true and the existing id, which counts as success.
	 *
	 * Callers retry only when the error data says `retryable`.
	 *
	 * @param array<string,mixed> $payload Lead fields.
	 * @return array{id:string,duplicate:bool}|WP_Error
	 */
	public function post_lead( array $payload ) {
		$lead = array();
		foreach ( self::LEAD_FIELDS as $field ) {
			if ( isset( $payload[ $field ] ) && '' !== $payload[ $field ] && array() !== $payload[ $field ] ) {
				$lead[ $field ] = $payload[ $field ];
			}
		}
		if ( empty( $lead['name'] ) || empty( $lead['email'] ) ) {
			return Api_Errors::make( 'profotograaf_invalid', __( 'A lead needs a name and an email address.', 'profotograaf' ), 0, false );
		}
		$lead['source'] = Config::CLIENT_ID;

		$result = $this->request( 'POST', '/api/v1/leads', $lead );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = is_array( $result ) ? $result : array();
		return array(
			'id'        => (string) ( $result['id'] ?? '' ),
			'duplicate' => ! empty( $result['duplicate'] ),
		);
	}

	/**
	 * Sends any authenticated JSON request, with automatic token refresh.
	 *
	 * For modules that need a call this class has no typed method for.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path starting with a slash.
	 * @param array<string,mixed>|null $body   JSON body.
	 * @return mixed Decoded JSON body (null for an empty one), or WP_Error.
	 */
	public function request( string $method, string $path, ?array $body = null ) {
		return $this->perform( $method, $path, $body, null );
	}

	/**
	 * Runs an authenticated request with a JSON body or a raw one.
	 *
	 * @param string                                                  $method HTTP method.
	 * @param string                                                  $path   Path starting with a slash.
	 * @param array<string,mixed>|null                                $body   JSON body.
	 * @param array{body:string,content_type:string,timeout:int}|null $raw    Raw body, which replaces the JSON body.
	 * @return mixed Decoded JSON body (null for an empty one), or WP_Error.
	 */
	private function perform( string $method, string $path, ?array $body, ?array $raw ) {
		if ( ! $this->connection->is_connected() ) {
			return Api_Errors::not_connected();
		}

		// A connection made before the granted scope list was stored refreshes
		// once to fill it. Nothing prompts the owner.
		if ( $this->connection->access_expires_at() <= $this->now() + self::SKEW ) {
			$refreshed = $this->refresh_tokens();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
		} elseif ( ! $this->connection->scopes_known() ) {
			// The token is still valid, so a failed fill must not fail the call.
			// The next call tries again.
			$this->refresh_tokens();
		}

		$token    = $this->connection->access_token();
		$response = $this->send( $method, $path, $body, $token, $raw );
		if ( ! is_wp_error( $response ) && 401 === $response['status'] ) {
			$refreshed = $this->refresh_tokens( $token );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			$response = $this->send( $method, $path, $body, $this->connection->access_token(), $raw );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['status'] >= 400 ) {
			Logger::warning(
				'The platform answered a request with an error.',
				array(
					'method' => $method,
					'path'   => $path,
					'status' => $response['status'],
					'code'   => is_array( $response['body'] ) && isset( $response['body']['code'] ) && is_string( $response['body']['code'] ) ? $response['body']['code'] : '',
				)
			);
			return Api_Errors::http( $response );
		}
		return $response['body'];
	}

	/**
	 * Sends a request without credentials, for the pairing flow.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path starting with a slash.
	 * @param array<string,mixed>|null $body   JSON body.
	 * @return array{status:int,retry_after:int,body:array<string,mixed>}|WP_Error
	 */
	public function public_request( string $method, string $path, ?array $body = null ) {
		$response = $this->send( $method, $path, $body, '' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'status'      => $response['status'],
			'retry_after' => $response['retry_after'],
			'body'        => is_array( $response['body'] ) ? $response['body'] : array(),
		);
	}

	/**
	 * Whether a public platform page lets `$origin` frame it.
	 *
	 * Requests the page, on the platform host only, without credentials and
	 * reads the frame-ancestors of its Content-Security-Policy.
	 *
	 * @param string $url    Public page URL.
	 * @param string $origin Origin that wants to frame the page.
	 * @return bool|WP_Error True when listed, false when the page names other origins or none.
	 */
	public function framing_allows( string $url, string $origin ) {
		if ( strtolower( (string) wp_parse_url( Config::platform_url(), PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			return Api_Errors::make( 'profotograaf_invalid', __( 'That page is not on the Profotograaf platform.', 'profotograaf' ), 0, false );
		}
		try {
			$result = $this->transport->send( 'GET', $url, array( 'Accept' => 'text/html' ), null, Config::http_timeout() );
		} catch ( \Throwable $e ) {
			Logger::exception( 'The framing check threw.', $e, array( 'method' => 'GET' ) );
			$result = new WP_Error( 'profotograaf_network' );
		}
		if ( is_wp_error( $result ) ) {
			Api_Errors::log_transport( $result, 'GET', 'framing check' );
			return Api_Errors::make( 'profotograaf_network', __( 'Profotograaf could not be reached.', 'profotograaf' ), 0, true );
		}
		if ( (int) $result['status'] >= 400 ) {
			Logger::warning(
				'The public page for the framing check answered with an error.',
				array( 'status' => (int) $result['status'] )
			);
			return Api_Errors::make( 'profotograaf_http', __( 'The public page could not be loaded.', 'profotograaf' ), (int) $result['status'], (int) $result['status'] >= 500 );
		}
		return Frame_Ancestors::allow( (string) ( $result['headers']['content-security-policy'] ?? '' ), $origin );
	}

	/**
	 * Exchanges the refresh token for a new pair.
	 *
	 * Called by request() and by the background refresh. A 401 from the
	 * platform means it ended the connection, so the stored tokens are cleared
	 * and the settings page shows the connection as revoked. Any other failure
	 * keeps the tokens so a later attempt can succeed.
	 *
	 * @param string $rejected_token An access token the platform just refused. When another
	 *                               request has already replaced it, nothing is sent.
	 * @return true|WP_Error
	 */
	public function refresh_tokens( string $rejected_token = '' ) {
		$now = $this->now();
		if ( ! $this->connection->acquire_lock( $now ) ) {
			$this->connection->flush_cache();
			if ( $this->tokens_usable( $now, $rejected_token ) ) {
				return true;
			}
			Logger::info( 'Token refresh skipped because another request holds the lock.' );
			return Api_Errors::make( 'profotograaf_refresh_busy', __( 'The connection is being refreshed. Try again in a moment.', 'profotograaf' ), 0, true );
		}

		try {
			$this->connection->flush_cache();
			if ( $this->tokens_usable( $now, $rejected_token ) && $this->connection->scopes_known() ) {
				return true;
			}
			$refresh = $this->connection->refresh_token();
			if ( '' === $refresh ) {
				return Api_Errors::not_connected();
			}

			$response = $this->send( 'POST', '/api/v1/auth/devices/refresh', array( 'refresh_token' => $refresh ), '' );
			if ( is_wp_error( $response ) ) {
				Logger::error( 'Token refresh failed: the platform could not be reached.' );
				return $response;
			}
			if ( 200 === $response['status'] && is_array( $response['body'] ) && ! empty( $response['body']['access_token'] ) && ! empty( $response['body']['refresh_token'] ) ) {
				$this->connection->save_tokens( $response['body'], $now );
				return true;
			}
			if ( 400 === $response['status'] || 401 === $response['status'] ) {
				Logger::warning( 'Token refresh refused, the connection was ended on the platform.', array( 'status' => $response['status'] ) );
				$this->connection->clear( __( 'The connection was ended in your Profotograaf account.', 'profotograaf' ) );
				/**
				 * Fires when the platform ended the connection.
				 *
				 * @param string $reason Why the connection ended.
				 */
				do_action( 'profotograaf_disconnected', 'revoked' );
				return Api_Errors::not_connected();
			}
			Logger::error( 'Token refresh failed.', array( 'status' => $response['status'] ) );
			return Api_Errors::http( $response );
		} finally {
			$this->connection->release_lock();
		}//end try
	}

	/**
	 * Asks the platform to end the connection. The plugin clears its own copy
	 * whatever this returns.
	 *
	 * The platform answers 204 and revokes the device, so it leaves the
	 * photographer's device list. A token that was already revoked gets 401.
	 * Disconnect clears the local tokens after any answer, including none.
	 *
	 * @return bool True when the platform confirmed the revoke.
	 */
	public function sign_out(): bool {
		if ( ! $this->connection->is_connected() ) {
			return false;
		}
		$response = $this->send( 'POST', '/api/v1/auth/devices/signout', null, $this->connection->access_token() );
		return ! is_wp_error( $response ) && 204 === $response['status'];
	}

	/**
	 * Whether the stored access token can be used now.
	 *
	 * @param int    $now            Current unix time.
	 * @param string $rejected_token Token the platform refused, or an empty string.
	 */
	private function tokens_usable( int $now, string $rejected_token ): bool {
		if ( ! $this->connection->is_connected() ) {
			return false;
		}
		if ( '' !== $rejected_token ) {
			return $this->connection->access_token() !== $rejected_token;
		}
		return $this->connection->access_expires_at() > $now + self::SKEW;
	}

	/**
	 * Performs one request. Never throws.
	 *
	 * @param string                                                  $method HTTP method.
	 * @param string                                                  $path   Path starting with a slash.
	 * @param array<string,mixed>|null                                $body   JSON body.
	 * @param string                                                  $bearer Access token, empty for none.
	 * @param array{body:string,content_type:string,timeout:int}|null $raw    Raw body, which replaces the JSON body.
	 * @return array{status:int,retry_after:int,body:mixed}|WP_Error
	 */
	private function send( string $method, string $path, ?array $body, string $bearer, ?array $raw = null ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $bearer ) {
			$headers['Authorization'] = 'Bearer ' . $bearer;
		}
		$encoded = null;
		$timeout = Config::http_timeout();
		if ( null !== $raw ) {
			$headers['Content-Type'] = $raw['content_type'];
			$encoded                 = $raw['body'];
			$timeout                 = $raw['timeout'];
		} elseif ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
			$encoded                 = wp_json_encode( $body );
			if ( false === $encoded ) {
				return Api_Errors::make( 'profotograaf_invalid', __( 'The request could not be encoded.', 'profotograaf' ), 0, false );
			}
		}

		try {
			$result = $this->transport->send( $method, Config::platform_endpoint( $path ), $headers, $encoded, $timeout );
		} catch ( \Throwable $e ) {
			Logger::exception(
				'The transport threw.',
				$e,
				array(
					'method' => $method,
					'path'   => $path,
				)
			);
			return Api_Errors::make( 'profotograaf_network', __( 'Profotograaf could not be reached.', 'profotograaf' ), 0, true );
		}

		if ( is_wp_error( $result ) ) {
			Api_Errors::log_transport( $result, $method, $path );
			return Api_Errors::make( 'profotograaf_network', __( 'Profotograaf could not be reached.', 'profotograaf' ), 0, true );
		}

		$raw     = (string) $result['body'];
		$decoded = '' === $raw ? null : json_decode( $raw, true );
		return array(
			'status'      => (int) $result['status'],
			'retry_after' => isset( $result['headers']['retry-after'] ) ? max( 0, (int) $result['headers']['retry-after'] ) : 0,
			'body'        => $decoded,
		);
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->clock )();
	}
}
