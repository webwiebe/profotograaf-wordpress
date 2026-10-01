<?php
/**
 * Allowed embed origin sync.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Config;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds this site's origin to "Sites allowed to embed my pages" in the
 * photographer's account, so the platform's framing policy lets this site
 * frame their pages (docs/oembed-and-framing.md in the platform repository).
 *
 * The platform exposes that list at GET and PUT /api/v1/account/embed-origins,
 * but only for a browser session. The plugin's token (galleries:read and
 * leads:write) is refused there. TODO: once the platform lets the WordPress
 * client write the list, return the path from the
 * `profotograaf_origin_sync_endpoint` filter and this module starts syncing
 * after connect, with no other change. Until then sync() only verifies: it reads
 * the frame-ancestors of a public page and records `synced` once this site is
 * listed, otherwise the settings page tells the photographer which origin to add.
 */
class Origin_Sync implements Module {

	public const OPTION = 'profotograaf_origin_sync';

	/**
	 * Plugin container.
	 *
	 * @var Plugin|null
	 */
	private ?Plugin $plugin = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		add_action( 'profotograaf_connected', array( $this, 'sync' ) );
		add_action( 'profotograaf_sync_origins', array( $this, 'sync' ) );
		add_action( 'profotograaf_disconnected', array( $this, 'forget' ) );
	}

	/**
	 * Result of the last sync.
	 *
	 * @return array{state:string,origin:string,message:string}
	 */
	public static function status(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'state'   => (string) ( $stored['state'] ?? 'none' ),
			'origin'  => (string) ( $stored['origin'] ?? '' ),
			'message' => (string) ( $stored['message'] ?? '' ),
		);
	}

	/**
	 * Syncs the origin and records the outcome.
	 *
	 * States: none, synced, manual (the photographer adds it in the account),
	 * insecure (only https origins are accepted) and error.
	 */
	public function sync(): void {
		$origin = Config::site_origin();
		$state  = array(
			'state'   => 'manual',
			'origin'  => $origin,
			'message' => '',
		);

		if ( '' === $origin || 0 !== strpos( $origin, 'https://' ) ) {
			$state['state'] = 'insecure';
		} else {
			/**
			 * Supplies the platform path that writes the allowed embed origins.
			 *
			 * @param string|null $path   Path such as /api/v1/account/embed-origins, null while
			 *                            the platform offers no write for the plugin's token.
			 * @param string      $origin This site's origin.
			 */
			$path = apply_filters( 'profotograaf_origin_sync_endpoint', null, $origin );
			if ( is_string( $path ) && '' !== $path && null !== $this->plugin ) {
				$state = $this->write_origin( $path, $origin );
			} elseif ( null !== $this->plugin ) {
				$state = $this->verify_origin( $origin );
			}
		}

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Forgets the recorded outcome.
	 */
	public function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Checks whether the platform already lets this origin frame the
	 * photographer's public pages, without any credentials.
	 *
	 * @param string $origin Origin to look for.
	 * @return array{state:string,origin:string,message:string}
	 */
	private function verify_origin( string $origin ): array {
		$state = array(
			'state'   => 'manual',
			'origin'  => $origin,
			'message' => '',
		);

		$galleries = $this->plugin->api()->list_galleries();
		if ( is_wp_error( $galleries ) ) {
			$state['message'] = $galleries->get_error_message();
			return $state;
		}
		$url = '';
		foreach ( $galleries as $gallery ) {
			if ( '' !== $gallery['url'] && ( '' === $url || $gallery['embeddable'] ) ) {
				$url = $gallery['url'];
				if ( $gallery['embeddable'] ) {
					break;
				}
			}
		}
		if ( '' === $url ) {
			return $state;
		}

		$allowed = $this->plugin->api()->framing_allows( $url, $origin );
		if ( is_wp_error( $allowed ) ) {
			$state['message'] = $allowed->get_error_message();
		} elseif ( $allowed ) {
			$state['state'] = 'synced';
		}
		return $state;
	}

	/**
	 * Reads the list, adds the origin and writes it back.
	 *
	 * @param string $path   Endpoint path.
	 * @param string $origin Origin to add.
	 * @return array{state:string,origin:string,message:string}
	 */
	private function write_origin( string $path, string $origin ): array {
		$api     = $this->plugin->api();
		$current = $api->request( 'GET', $path );
		if ( is_wp_error( $current ) ) {
			return array(
				'state'   => 'error',
				'origin'  => $origin,
				'message' => $current->get_error_message(),
			);
		}

		$origins = isset( $current['origins'] ) && is_array( $current['origins'] ) ? array_values( array_filter( $current['origins'], 'is_string' ) ) : array();
		if ( ! in_array( $origin, $origins, true ) ) {
			$origins[] = $origin;
			$result    = $api->request( 'PUT', $path, array( 'origins' => $origins ) );
			if ( is_wp_error( $result ) ) {
				return array(
					'state'   => 'error',
					'origin'  => $origin,
					'message' => $result->get_error_message(),
				);
			}
		}
		return array(
			'state'   => 'synced',
			'origin'  => $origin,
			'message' => '',
		);
	}
}
