<?php
/**
 * Stored connection state.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the plugin remembers about the link to a Profotograaf account.
 *
 * All of it lives in wp_options with autoload off, so nothing is loaded on a
 * front-end request unless a module asks for it.
 */
class Connection {

	public const OPTION         = 'profotograaf_connection';
	public const PAIRING_OPTION = 'profotograaf_pairing';
	public const INSTALL_ID     = 'profotograaf_install_id';
	public const LOCK_OPTION    = 'profotograaf_refresh_lock';

	private const LOCK_TTL = 60;

	/**
	 * Whether a token pair is stored.
	 */
	public function is_connected(): bool {
		$data = $this->data();
		return '' !== $data['access_token'] && '' !== $data['refresh_token'];
	}

	/**
	 * Current access token, or an empty string.
	 */
	public function access_token(): string {
		return $this->data()['access_token'];
	}

	/**
	 * Current refresh token, or an empty string.
	 */
	public function refresh_token(): string {
		return $this->data()['refresh_token'];
	}

	/**
	 * Unix time the access token stops working.
	 */
	public function access_expires_at(): int {
		return $this->data()['expires_at'];
	}

	/**
	 * Whether the platform granted this scope to the stored token.
	 *
	 * False while the granted list is unknown (see scopes_known()).
	 *
	 * @param string $scope Scope name, for example "galleries:write".
	 */
	public function has_scope( string $scope ): bool {
		return in_array( $scope, (array) $this->data()['scopes'], true );
	}

	/**
	 * Whether the granted scope list is stored. A connection made before the
	 * plugin kept the list has none until the next token refresh.
	 */
	public function scopes_known(): bool {
		return null !== $this->data()['scopes'];
	}

	/**
	 * Summary for the settings page.
	 *
	 * @return array{state:string,connected_at:int,last_error:string,scope_revision:int,embed_denied:bool}
	 */
	public function status(): array {
		$data = $this->data();
		return array(
			'state'        => $this->is_connected() ? 'connected' : ( '' !== $data['last_error'] ? 'revoked' : 'disconnected' ),
			'connected_at' => $data['connected_at'],
			'last_error'   => $data['last_error'],
		);
	}

	/**
	 * Stores the token pair from a token or refresh response.
	 *
	 * @param array<string,mixed> $response Decoded response body.
	 * @param int                 $now      Current unix time.
	 */
	public function save_tokens( array $response, int $now ): void {
		$previous = $this->data();
		$ttl      = isset( $response['expires_in'] ) ? max( 1, (int) $response['expires_in'] ) : 900;
		$this->write(
			array(
				'access_token'   => (string) ( $response['access_token'] ?? '' ),
				'refresh_token'  => (string) ( $response['refresh_token'] ?? '' ),
				'expires_at'     => $now + $ttl,
				'device_id'      => (string) ( $response['device_id'] ?? $previous['device_id'] ),
				'connected_at'   => $previous['connected_at'] > 0 ? $previous['connected_at'] : $now,
				'last_error'     => '',
				'scope_revision' => $previous['scope_revision'],
				'embed_denied'   => $previous['embed_denied'],
				'scopes'         => $this->scopes_from( $response ),
			)
		);
	}

	/**
	 * Records that a fresh pairing was approved under a scope revision.
	 *
	 * @param int $revision Config::SCOPE_REVISION at the time of pairing.
	 */
	public function record_grant( int $revision ): void {
		$data                   = $this->data();
		$data['scope_revision'] = $revision;
		$data['embed_denied']   = false;
		$this->write( $data );
	}

	/**
	 * Remembers that the platform refused the embed permission (a 403).
	 */
	public function flag_embed_denied(): void {
		$data = $this->data();
		if ( $data['embed_denied'] ) {
			return;
		}
		$data['embed_denied'] = true;
		$this->write( $data );
	}

	/**
	 * Whether the stored token lacks the permission to switch galleries on.
	 *
	 * True for a connection paired before the plugin asked for
	 * galleries:embed, and after the platform answered 403 to that call.
	 */
	public function needs_reconnect(): bool {
		if ( ! $this->is_connected() ) {
			return false;
		}
		$data = $this->data();
		return $data['embed_denied'] || $data['scope_revision'] < Config::SCOPE_REVISION;
	}

	/**
	 * Forgets the tokens.
	 *
	 * @param string $reason Why, when the platform ended the connection. A
	 *                       reason makes the status "revoked" rather than
	 *                       "disconnected".
	 */
	public function clear( string $reason = '' ): void {
		if ( '' === $reason ) {
			delete_option( self::OPTION );
			return;
		}
		$this->write( array_merge( $this->defaults(), array( 'last_error' => $reason ) ) );
	}

	/**
	 * The pairing in progress, or null.
	 *
	 * @return array{device_code:string,user_code:string,verification_uri:string,expires_at:int,interval:int}|null
	 */
	public function pairing(): ?array {
		$stored = get_option( self::PAIRING_OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['device_code'] ) ) {
			return null;
		}
		return array(
			'device_code'      => (string) $stored['device_code'],
			'user_code'        => (string) ( $stored['user_code'] ?? '' ),
			'verification_uri' => (string) ( $stored['verification_uri'] ?? '' ),
			'expires_at'       => (int) ( $stored['expires_at'] ?? 0 ),
			'interval'         => max( 1, (int) ( $stored['interval'] ?? 5 ) ),
		);
	}

	/**
	 * Remembers a pairing in progress.
	 *
	 * @param array<string,mixed> $pairing Pairing fields.
	 */
	public function save_pairing( array $pairing ): void {
		update_option( self::PAIRING_OPTION, $pairing, false );
	}

	/**
	 * Forgets the pairing in progress.
	 */
	public function clear_pairing(): void {
		delete_option( self::PAIRING_OPTION );
	}

	/**
	 * A stable random id for this WordPress install, sent as device_id so the
	 * platform can show one entry per site under "Connected apps".
	 */
	public function install_id(): string {
		$id = get_option( self::INSTALL_ID, '' );
		if ( is_string( $id ) && '' !== $id ) {
			return $id;
		}
		$id = wp_generate_uuid4();
		update_option( self::INSTALL_ID, $id, false );
		return $id;
	}

	/**
	 * Takes the refresh lock. Refresh tokens rotate on every use, so two
	 * requests refreshing at once would make the second present a dead token.
	 *
	 * @param int $now Current unix time.
	 */
	public function acquire_lock( int $now ): bool {
		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held > 0 && $now - $held < self::LOCK_TTL ) {
			return false;
		}
		delete_option( self::LOCK_OPTION );
		return (bool) add_option( self::LOCK_OPTION, $now, '', false );
	}

	/**
	 * Releases the refresh lock.
	 */
	public function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Drops object-cache copies so a read sees what another request stored.
	 */
	public function flush_cache(): void {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Default stored shape.
	 *
	 * @return array{access_token:string,refresh_token:string,expires_at:int,device_id:string,connected_at:int,last_error:string,scope_revision:int,embed_denied:bool,scopes:list<string>|null}
	 */
	private function defaults(): array {
		return array(
			'access_token'   => '',
			'refresh_token'  => '',
			'expires_at'     => 0,
			'device_id'      => '',
			'connected_at'   => 0,
			'last_error'     => '',
			'scope_revision' => 0,
			'embed_denied'   => false,
			'scopes'         => null,
		);
	}

	/**
	 * Stored data merged over the defaults.
	 *
	 * @return array{access_token:string,refresh_token:string,expires_at:int,device_id:string,connected_at:int,last_error:string,scope_revision:int,embed_denied:bool,scopes:list<string>|null}
	 */
	private function data(): array {
		$stored = get_option( self::OPTION, array() );
		$data   = $this->defaults();
		if ( ! is_array( $stored ) ) {
			return $data;
		}
		foreach ( array( 'access_token', 'refresh_token', 'device_id', 'last_error' ) as $key ) {
			$data[ $key ] = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
		}
		$data['embed_denied'] = ! empty( $stored['embed_denied'] );
		if ( isset( $stored['scopes'] ) && is_array( $stored['scopes'] ) ) {
			$data['scopes'] = array_values( array_filter( array_map( 'strval', $stored['scopes'] ), static fn( string $scope ): bool => '' !== $scope ) );
		}
		foreach ( array( 'expires_at', 'connected_at', 'scope_revision' ) as $key ) {
			$data[ $key ] = isset( $stored[ $key ] ) ? (int) $stored[ $key ] : 0;
		}
		return $data;
	}

	/**
	 * Reads the granted scope list from a token or refresh response. The
	 * platform sends it as a space separated string (RFC 6749 section 3.3).
	 * A response without the field stores an empty list, so the list counts
	 * as known and the connection does not refresh again to fill it.
	 *
	 * @param array<string,mixed> $response Decoded response body.
	 * @return list<string>
	 */
	private function scopes_from( array $response ): array {
		$raw = $response['scope'] ?? '';
		if ( is_array( $raw ) ) {
			$raw = implode( ' ', array_map( 'strval', $raw ) );
		}
		$parts = preg_split( '/\s+/', trim( (string) $raw ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( false === $parts ? array() : $parts ) );
	}

	/**
	 * Persists the stored shape with autoload off.
	 *
	 * @param array<string,mixed> $data Connection data.
	 */
	private function write( array $data ): void {
		update_option( self::OPTION, $data, false );
	}
}
