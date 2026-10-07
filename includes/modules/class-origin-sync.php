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
 * The plugin's token may add one origin to that list with POST
 * /api/v1/embed/origins and the body {"origin": "https://<site>"} (scope
 * account:embed-origins), so connect and "Check again" add this site's origin
 * by themselves. A token paired before that scope existed, or a
 * platform that predates the route, answers 401, 403 or 404. Then sync() falls
 * back to verifying: it reads the frame-ancestors of a public page and records
 * `synced` once this site is listed, otherwise the settings page tells the
 * photographer which origin to add.
 */
class Origin_Sync implements Module {

	public const OPTION = 'profotograaf_origin_sync';

	/**
	 * Device-token route for the allowed embed origins.
	 */
	public const ENDPOINT = '/api/v1/embed/origins';

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
			 * @param string|null $path   Path of the endpoint that writes the allowed embed origins.
			 *                            Return null or an empty string to only verify.
			 * @param string      $origin This site's origin.
			 */
			$path = apply_filters( 'profotograaf_origin_sync_endpoint', self::ENDPOINT, $origin );
			if ( is_string( $path ) && '' !== $path && null !== $this->plugin ) {
				$state = $this->write_origin( $path, $origin );
				if ( null === $state ) {
					$state = $this->verify_origin( $origin, $this->plugin );
				}
			} elseif ( null !== $this->plugin ) {
				$state = $this->verify_origin( $origin, $this->plugin );
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
	 * @param Plugin $plugin Service container.
	 * @return array{state:string,origin:string,message:string}
	 */
	private function verify_origin( string $origin, Plugin $plugin ): array {
		$state = array(
			'state'   => 'manual',
			'origin'  => $origin,
			'message' => '',
		);

		$galleries = $plugin->api()->list_galleries();
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

		$allowed = $plugin->api()->framing_allows( $url, $origin );
		if ( is_wp_error( $allowed ) ) {
			$state['message'] = $allowed->get_error_message();
		} elseif ( $allowed ) {
			$state['state'] = 'synced';
		}
		return $state;
	}

	/**
	 * Asks the platform to add this site's origin to the list.
	 *
	 * The route adds only the origin it is given and answers with the whole
	 * list, so origins the photographer added by hand stay in place.
	 *
	 * @param string $path   Endpoint path.
	 * @param string $origin Origin to add.
	 * @return array{state:string,origin:string,message:string}|null Null when the platform
	 *         refuses this token or has no such route, so the caller verifies instead.
	 */
	private function write_origin( string $path, string $origin ): ?array {
		$result = $this->plugin->api()->request( 'POST', $path, array( 'origin' => $origin ) );
		if ( is_wp_error( $result ) ) {
			if ( self::is_unsupported( $result ) ) {
				return null;
			}
			return array(
				'state'   => 'error',
				'origin'  => $origin,
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'state'   => 'synced',
			'origin'  => $origin,
			'message' => '',
		);
	}

	/**
	 * Whether an error means the platform refuses this token or has no such route.
	 *
	 * @param \WP_Error $error Error from the API client.
	 */
	private static function is_unsupported( \WP_Error $error ): bool {
		$data = $error->get_error_data();
		return is_array( $data ) && in_array( (int) ( $data['status'] ?? 0 ), array( 401, 403, 404 ), true );
	}
}
