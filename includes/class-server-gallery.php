<?php
/**
 * The gallery markup the server writes for a host div.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the declarative shadow root of a gallery, written next to the host
 * div's fallback link: the gallery shows without a request from the visitor,
 * and embed.js adopts it. Empty when the gallery cannot be drawn on the server.
 *
 * The payload comes from the five minute cache of Full_Page_Payload. A failed
 * request is remembered for a minute, so a platform that is down does not slow
 * every page view.
 */
final class Server_Gallery {

	/** Prefix of the transient that remembers a failed payload request; the gallery id follows. */
	private const FAILED_PREFIX = 'profotograaf_decl_failed_';

	/**
	 * Payload source; null draws nothing on the server.
	 *
	 * @var Full_Page_Payload|null
	 */
	private ?Full_Page_Payload $payloads;

	/**
	 * Constructor.
	 *
	 * @param Full_Page_Payload|null $payloads Payload source.
	 */
	public function __construct( ?Full_Page_Payload $payloads = null ) {
		$this->payloads = $payloads;
	}

	/**
	 * The declarative shadow root of a gallery, or an empty string.
	 *
	 * @param string               $id     Gallery id.
	 * @param array<string,string> $data   Display option data attributes.
	 * @param string               $layout Resolved layout.
	 */
	public function shadow_root( string $id, array $data, string $layout ): string {
		if ( null === $this->payloads || false !== get_transient( self::FAILED_PREFIX . $id ) ) {
			return '';
		}
		$payload = $this->payloads->get( $id );
		if ( is_wp_error( $payload ) ) {
			set_transient( self::FAILED_PREFIX . $id, 1, MINUTE_IN_SECONDS );
			return '';
		}
		$dutch = 0 === strpos( strtolower( get_locale() ), 'nl' );
		$badge = $dutch ? 'Gemaakt met Profotograaf' : 'Made with Profotograaf';
		$more  = $dutch ? 'Toon meer' : 'Show more';
		return (string) Declarative_Renderer::render( $payload, array( 'data-layout' => $layout ) + $data, $badge, $more );
	}
}
