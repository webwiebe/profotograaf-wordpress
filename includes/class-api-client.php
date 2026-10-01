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
 *  - profotograaf_refresh_busy: another request is refreshing the token.
 *
 * The error data is always an array with `status` (HTTP status, 0 when no
 * response came) and `retryable` (true when trying again later can succeed:
 * network errors, 408, 429 and 5xx). HTTP errors add `code` (the platform's
 * machine readable code) and `retry_after` (seconds, 0 when not given).
 */
class Api_Client {

	/**
	 * Seconds before expiry at which a token counts as expired.
	 */
	private const SKEW = 30;

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
	 *
	 * @return array<int,array{id:string,slug:string,title:string,url:string,embeddable:bool,available:bool,photo_count:int,cover_url:string,updated_at:string}>|WP_Error
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
				'id'          => (string) $row['id'],
				'slug'        => (string) ( $row['slug'] ?? '' ),
				'title'       => (string) ( $row['title'] ?? '' ),
				'url'         => (string) ( $row['url'] ?? '' ),
				'embeddable'  => ! empty( $row['embeddable'] ),
				'available'   => ! empty( $row['available'] ),
				'photo_count' => (int) ( $row['photo_count'] ?? 0 ),
				'cover_url'   => (string) ( $row['cover_url'] ?? '' ),
				'updated_at'  => (string) ( $row['updated_at'] ?? '' ),
			);
		}
		return $rows;
	}

	/**
	 * Marks a gallery as embeddable on other sites.
	 *
	 * Calls `PUT /api/v1/embed/galleries/{id}/embeddable`, which needs the
	 * galleries:embed scope. The answer is `id`, `embeddable` and `available`.
	 * `available` is false when a password, an expiry date, proofing mode or a
	 * client-only setting keeps the gallery off other sites even though the
	 * setting is on; callers tell the photographer.
	 *
	 * A 403 means the token was paired before the scope existed. The result is
	 * then a profotograaf_reconnect error and the connection remembers it, so
	 * the settings page asks the photographer to connect again.
	 *
	 * @param string $gallery_id Gallery id.
	 * @return array{id:string,embeddable:bool,available:bool}|WP_Error
	 */
	public function mark_embeddable( string $gallery_id ) {
		if ( '' === trim( $gallery_id ) ) {
			return $this->error( 'profotograaf_invalid', __( 'A gallery id is required.', 'profotograaf' ), 0, false );
		}

		$request = array(
			'method' => 'PUT',
			'path'   => '/api/v1/embed/galleries/' . rawurlencode( $gallery_id ) . '/embeddable',
			'body'   => array( 'embeddable' => true ),
		);

		/**
		 * Overrides the request that marks a gallery embeddable.
		 *
		 * @param array{method:string,path:string,body:array<string,mixed>} $request    The default request.
		 * @param string                                                    $gallery_id Gallery id.
		 */
		$request = apply_filters( 'profotograaf_mark_embeddable_request', $request, $gallery_id );
		if ( ! is_array( $request ) || empty( $request['method'] ) || empty( $request['path'] ) ) {
			return $this->error( 'profotograaf_invalid', __( 'The request to switch embedding on is not valid.', 'profotograaf' ), 0, false );
		}

		$result = $this->request( (string) $request['method'], (string) $request['path'], isset( $request['body'] ) && is_array( $request['body'] ) ? $request['body'] : null );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( 'profotograaf_http' === $result->get_error_code() && is_array( $data ) && 403 === ( $data['status'] ?? 0 ) ) {
				$this->connection->flag_embed_denied();
				return $this->error(
					'profotograaf_reconnect',
					__( 'This connection may not switch galleries on yet. Connect this site again under Settings > Profotograaf to grant the new permission.', 'profotograaf' ),
					403,
					false
				);
			}
			return $result;
		}
		$result = is_array( $result ) ? $result : array();
		return array(
			'id'         => (string) ( $result['id'] ?? $gallery_id ),
			'embeddable' => ! empty( $result['embeddable'] ),
			'available'  => ! empty( $result['available'] ),
		);
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
			return $this->error( 'profotograaf_invalid', __( 'A lead needs a name and an email address.', 'profotograaf' ), 0, false );
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
		if ( ! $this->connection->is_connected() ) {
			return $this->not_connected();
		}

		if ( $this->connection->access_expires_at() <= $this->now() + self::SKEW ) {
			$refreshed = $this->refresh_tokens();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
		}

		$token    = $this->connection->access_token();
		$response = $this->send( $method, $path, $body, $token );
		if ( ! is_wp_error( $response ) && 401 === $response['status'] ) {
			$refreshed = $this->refresh_tokens( $token );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			$response = $this->send( $method, $path, $body, $this->connection->access_token() );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['status'] >= 400 ) {
			return $this->http_error( $response );
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
	 * Requests the page without credentials and reads the `frame-ancestors`
	 * directive of its Content-Security-Policy. Only pages on the platform's own
	 * host are requested.
	 *
	 * @param string $url    Public page URL.
	 * @param string $origin Origin that wants to frame the page.
	 * @return bool|WP_Error True when listed, false when the page names other origins or none.
	 */
	public function framing_allows( string $url, string $origin ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || strtolower( (string) wp_parse_url( Config::platform_url(), PHP_URL_HOST ) ) !== $host ) {
			return $this->error( 'profotograaf_invalid', __( 'That page is not on the Profotograaf platform.', 'profotograaf' ), 0, false );
		}

		try {
			$result = $this->transport->send( 'GET', $url, array( 'Accept' => 'text/html' ), null, Config::http_timeout() );
		} catch ( \Throwable $e ) {
			return $this->error( 'profotograaf_network', __( 'Profotograaf could not be reached.', 'profotograaf' ), 0, true );
		}
		if ( is_wp_error( $result ) ) {
			return $this->error( 'profotograaf_network', __( 'Profotograaf could not be reached.', 'profotograaf' ), 0, true );
		}
		if ( (int) $result['status'] >= 400 ) {
			return $this->error( 'profotograaf_http', __( 'The public page could not be loaded.', 'profotograaf' ), (int) $result['status'], (int) $result['status'] >= 500 );
		}

		return self::frame_ancestors_allow( (string) ( $result['headers']['content-security-policy'] ?? '' ), $origin );
	}

	/**
	 * Whether every policy in a Content-Security-Policy header that has a
	 * frame-ancestors directive lists `$origin` (or `*`, or its scheme).
	 *
	 * @param string $header Header value, several policies separated by commas.
	 * @param string $origin Origin to look for.
	 */
	public static function frame_ancestors_allow( string $header, string $origin ): bool {
		$origin = strtolower( $origin );
		$scheme = (string) strstr( $origin, '://', true ) . ':';
		$found  = false;
		foreach ( explode( ',', $header ) as $policy ) {
			foreach ( explode( ';', $policy ) as $directive ) {
				$tokens = preg_split( '/\s+/', trim( strtolower( $directive ) ) );
				if ( 'frame-ancestors' !== $tokens[0] ) {
					continue;
				}
				$found = true;
				if ( array() === array_intersect( array_slice( $tokens, 1 ), array( $origin, '*', $scheme ) ) ) {
					return false;
				}
			}
		}
		return $found;
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
			return $this->error( 'profotograaf_refresh_busy', __( 'The connection is being refreshed. Try again in a moment.', 'profotograaf' ), 0, true );
		}

		try {
			$this->connection->flush_cache();
			if ( $this->tokens_usable( $now, $rejected_token ) ) {
				return true;
			}
			$refresh = $this->connection->refresh_token();
			if ( '' === $refresh ) {
				return $this->not_connected();
			}

			$response = $this->send( 'POST', '/api/v1/auth/devices/refresh', array( 'refresh_token' => $refresh ), '' );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( 200 === $response['status'] && is_array( $response['body'] ) && ! empty( $response['body']['access_token'] ) && ! empty( $response['body']['refresh_token'] ) ) {
				$this->connection->save_tokens( $response['body'], $now );
				return true;
			}
			if ( 400 === $response['status'] || 401 === $response['status'] ) {
				$this->connection->clear( __( 'The connection was ended in your Profotograaf account.', 'profotograaf' ) );
				/**
				 * Fires when the platform ended the connection.
				 *
				 * @param string $reason Why the connection ended.
				 */
				do_action( 'profotograaf_disconnected', 'revoked' );
				return $this->not_connected();
			}
			return $this->http_error( $response );
		} finally {
			$this->connection->release_lock();
		}//end try
	}

	/**
	 * Asks the platform to end the connection. The plugin clears its own copy
	 * whatever this returns.
	 *
	 * The WordPress client's token is not allowed on the sign-out route today
	 * (the platform answers 403), so this is best effort: the photographer can
	 * always remove the app under "Connected apps" in Profotograaf.
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
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path starting with a slash.
	 * @param array<string,mixed>|null $body   JSON body.
	 * @param string                   $bearer Access token, empty for none.
	 * @return array{status:int,retry_after:int,body:mixed}|WP_Error
	 */
	private function send( string $method, string $path, ?array $body, string $bearer ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $bearer ) {
			$headers['Authorization'] = 'Bearer ' . $bearer;
		}
		$encoded = null;
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
			$encoded                 = wp_json_encode( $body );
			if ( false === $encoded ) {
				return $this->error( 'profotograaf_invalid', __( 'The request could not be encoded.', 'profotograaf' ), 0, false );
			}
		}

		try {
			$result = $this->transport->send( $method, Config::platform_endpoint( $path ), $headers, $encoded, Config::http_timeout() );
		} catch ( \Throwable $e ) {
			return $this->error( 'profotograaf_network', __( 'Profotograaf could not be reached.', 'profotograaf' ), 0, true );
		}

		if ( is_wp_error( $result ) ) {
			return $this->error( 'profotograaf_network', __( 'Profotograaf could not be reached.', 'profotograaf' ), 0, true );
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
	 * Builds the error for an HTTP status of 400 or above.
	 *
	 * @param array{status:int,retry_after:int,body:mixed} $response Response.
	 */
	private function http_error( array $response ): WP_Error {
		$status = $response['status'];
		$body   = is_array( $response['body'] ) ? $response['body'] : array();
		/* translators: %d: HTTP status code. */
		$message = isset( $body['error'] ) && is_string( $body['error'] ) && '' !== $body['error'] ? $body['error'] : sprintf( __( 'Profotograaf answered with HTTP %d.', 'profotograaf' ), $status );

		return new WP_Error(
			'profotograaf_http',
			$message,
			array(
				'status'      => $status,
				'code'        => isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '',
				'retryable'   => 408 === $status || 429 === $status || $status >= 500,
				'retry_after' => $response['retry_after'],
			)
		);
	}

	/**
	 * Builds an error with the standard data shape.
	 *
	 * @param string $code      Error code.
	 * @param string $message   Message.
	 * @param int    $status    HTTP status, 0 when none.
	 * @param bool   $retryable Whether a later attempt can succeed.
	 */
	private function error( string $code, string $message, int $status, bool $retryable ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'    => $status,
				'retryable' => $retryable,
			)
		);
	}

	/**
	 * Error for a site without a connection.
	 */
	private function not_connected(): WP_Error {
		return $this->error( 'profotograaf_not_connected', __( 'This site is not connected to Profotograaf.', 'profotograaf' ), 0, false );
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->clock )();
	}
}
