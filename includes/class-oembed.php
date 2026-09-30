<?php
/**
 * The oEmbed provider registration.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a pasted gallery link turn into an embed.
 *
 * The platform answers GET /oembed for the share URL of an embeddable gallery,
 * on its own host and on a photographer's subdomain (docs/oembed-and-framing.md
 * in the platform repository). Both spellings are registered here, so WordPress
 * asks the platform directly. A gallery on a custom domain is not listed: the
 * platform puts an oEmbed discovery link on every share page, so WordPress finds
 * it by itself, and sites can add hosts with the `profotograaf_oembed_hosts`
 * filter.
 *
 * The result carries the embed.js script tag. It is taken out and queued once
 * for the page, so a page with several galleries loads the script once.
 */
class Oembed {

	/**
	 * Script handling.
	 *
	 * @var Embed_Script
	 */
	private Embed_Script $script;

	/**
	 * Constructor.
	 *
	 * @param Embed_Script $script Script handling.
	 */
	public function __construct( Embed_Script $script ) {
		$this->script = $script;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_providers' ) );
		add_filter( 'embed_oembed_html', array( $this, 'filter_html' ), 10, 2 );
	}

	/**
	 * URL patterns of the share pages this provider answers for.
	 *
	 * The platform host and its subdomains, plus any host a site adds.
	 *
	 * @return string[] Regular expressions for wp_oembed_add_provider().
	 */
	public function patterns(): array {
		$host = strtolower( (string) wp_parse_url( Config::platform_url(), PHP_URL_HOST ) );
		$port = wp_parse_url( Config::platform_url(), PHP_URL_PORT );
		if ( '' === $host ) {
			return array();
		}

		$authorities = array( '(?:[a-z0-9-]+\.)?' . preg_quote( $host, '#' ) . ( $port ? ':' . (int) $port : '' ) );

		/**
		 * Filters extra hosts whose /share/g/{slug} links embed, for example a
		 * custom domain of the photographer.
		 *
		 * @param string[] $hosts Host names, with the port when it is not the default.
		 */
		$extra = apply_filters( 'profotograaf_oembed_hosts', array() );
		foreach ( is_array( $extra ) ? $extra : array() as $extra_host ) {
			$extra_host = strtolower( trim( (string) $extra_host ) );
			if ( 1 === preg_match( '/^[a-z0-9.-]+(?::\d{1,5})?$/', $extra_host ) ) {
				$authorities[] = preg_quote( $extra_host, '#' );
			}
		}

		$patterns = array();
		foreach ( $authorities as $authority ) {
			$patterns[] = '#^https?://' . $authority . '/share/g/[^/?\#]+/?(?:[?\#].*)?$#i';
		}
		return $patterns;
	}

	/**
	 * Registers the provider for every pattern.
	 */
	public function register_providers(): void {
		$endpoint = Config::platform_endpoint( '/oembed' );
		foreach ( $this->patterns() as $pattern ) {
			wp_oembed_add_provider( $pattern, $endpoint, true );
		}
	}

	/**
	 * Swaps the script tag in an oEmbed result for the once-per-page enqueue.
	 *
	 * @param string|false $html Embed HTML.
	 * @param string       $url  The pasted URL.
	 * @return string|false
	 */
	public function filter_html( $html, $url ) {
		if ( ! is_string( $html ) || ! $this->is_ours( (string) $url ) ) {
			return $html;
		}
		$this->script->learn_from_html( $html );

		$stripped = preg_replace( '#<script\b[^>]*\bsrc="[^"]*/share/embed/embed(?:\.[a-f0-9]{12})?\.js"[^>]*>\s*</script>#i', '', $html );
		if ( ! is_string( $stripped ) || $stripped === $html ) {
			return $html;
		}
		$this->script->enqueue();
		return $stripped;
	}

	/**
	 * Whether a pasted URL matches one of the patterns.
	 *
	 * @param string $url Pasted URL.
	 */
	private function is_ours( string $url ): bool {
		foreach ( $this->patterns() as $pattern ) {
			if ( 1 === preg_match( $pattern, $url ) ) {
				return true;
			}
		}
		return false;
	}
}
