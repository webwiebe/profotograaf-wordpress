<?php
/**
 * The platform's embed.js script.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Knows the versioned URL of embed.js and puts it on the page once.
 *
 * The platform serves the script at /share/embed/embed.{version}.js, where the
 * version is 12 hex characters of the content hash, cached for a year
 * (docs/embed-js.md in the platform repository). The unversioned
 * /share/embed/embed.js is served too, with a five minute cache, so it is the
 * URL used until the version is known. The version is learned in the
 * background (a HEAD request answered with the ETag) and from any oEmbed
 * result the platform returned, and stored. A page view never waits on the
 * network.
 */
class Embed_Script {

	public const HANDLE = 'profotograaf-embed';

	public const OPTION = 'profotograaf_embed_version';

	public const REFRESH_HOOK = 'profotograaf_refresh_embed_version';

	private const REFRESH_AFTER = DAY_IN_SECONDS;

	/**
	 * Whether the tag was printed by hand because the footer already ran.
	 *
	 * @var bool
	 */
	private bool $printed = false;

	/**
	 * URL of the script: versioned when the version is known.
	 */
	public function url(): string {
		$version = $this->version();
		$file    = '' === $version ? 'embed.js' : 'embed.' . $version . '.js';
		return Config::platform_endpoint( '/share/embed/' . $file );
	}

	/**
	 * The stored version, or an empty string while it is unknown.
	 */
	public function version(): string {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$found  = isset( $stored['version'] ) ? (string) $stored['version'] : '';

		/**
		 * Filters the embed.js version used in the script URL.
		 *
		 * @param string $version 12 hex characters, or an empty string for the unversioned URL.
		 */
		$version = (string) apply_filters( 'profotograaf_embed_script_version', $found );
		return $this->valid_version( $version ) ? $version : '';
	}

	/**
	 * Whether a value looks like a version the platform serves.
	 *
	 * @param string $version Candidate.
	 */
	public function valid_version( string $version ): bool {
		return 1 === preg_match( '/^[a-f0-9]{12}$/', $version );
	}

	/**
	 * Asks for the script to be printed once on this page.
	 *
	 * Queued in the footer with the async strategy. When the footer scripts
	 * already printed (a block rendered by a late hook), the tag is written
	 * directly, once.
	 */
	public function enqueue(): void {
		$this->schedule_refresh();

		if ( did_action( 'wp_print_footer_scripts' ) ) {
			if ( ! $this->printed ) {
				$this->printed = true;
				printf( '<script async src="%1$s" onerror="%2$s"></script>', esc_url( $this->url() ), esc_attr( $this->error_handler() ) ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the footer already ran, so the queue can no longer print it.
			}
			return;
		}

		// The version is part of the file name, so no ?ver= query is added (null).
		wp_enqueue_script(
			self::HANDLE,
			$this->url(),
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion, Squiz.Commenting.PostStatementComment.Found
			array(
				'in_footer' => true,
				'strategy'  => 'async',
			)
		);
	}

	/**
	 * Adds the failure handler to the script tag the queue prints.
	 *
	 * Hooked to script_loader_tag.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 */
	public function add_error_handler( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle || str_contains( $tag, ' onerror=' ) ) {
			return $tag;
		}
		return (string) preg_replace( '/<script\b/', '<script onerror="' . esc_attr( $this->error_handler() ) . '"', $tag, 1 );
	}

	/**
	 * JavaScript that runs when embed.js cannot be loaded: it logs what to
	 * check to the console and replaces the empty boxes (galleries without a
	 * fallback link) with a plain message.
	 */
	public function error_handler(): string {
		$flags   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
		$console = 'Profotograaf: the gallery script could not be loaded from ' . $this->url() . '. Galleries show their fallback link instead. Check that this site and the visitor can reach that address, and that no ad blocker, firewall or Content-Security-Policy script-src rule blocks it.';
		$message = __( 'This gallery could not be loaded. Please try again later.', 'profotograaf' );

		return 'console.error(' . wp_json_encode( $console, $flags ) . ');'
			. 'document.querySelectorAll("[data-profotograaf-gallery]").forEach(function(e){'
			. 'e.setAttribute("data-profotograaf-failed","");'
			. 'if(!e.querySelector("a")){e.textContent=' . wp_json_encode( $message, $flags ) . '}})';
	}

	/**
	 * Records the version found in a script URL, such as the one in an oEmbed result.
	 *
	 * @param string $html HTML that may hold the platform's script tag.
	 */
	public function learn_from_html( string $html ): void {
		if ( 1 !== preg_match( '#/share/embed/embed\.([a-f0-9]{12})\.js#', $html, $found ) ) {
			return;
		}
		$this->store( $found[1] );
	}

	/**
	 * Schedules a background lookup when the stored version is missing or old.
	 */
	public function schedule_refresh(): void {
		$stored = get_option( self::OPTION, array() );
		$at     = is_array( $stored ) && isset( $stored['checked_at'] ) ? (int) $stored['checked_at'] : 0;
		if ( $at > time() - self::REFRESH_AFTER ) {
			return;
		}
		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::REFRESH_HOOK );
		}
	}

	/**
	 * Reads the current version from the platform. Runs from WP-Cron.
	 *
	 * The route answers with the content hash as ETag, the same hash the
	 * versioned URL carries. Failures leave the stored version alone and try
	 * again the next day.
	 */
	public function refresh(): void {
		$response = wp_remote_head(
			Config::platform_endpoint( '/share/embed/embed.js' ),
			array(
				'timeout'     => Config::http_timeout(),
				'redirection' => 0,
			)
		);

		$version = '';
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$etag = trim( (string) wp_remote_retrieve_header( $response, 'etag' ), " \t\"" );
			$etag = preg_replace( '#^W/#', '', $etag );
			if ( is_string( $etag ) && $this->valid_version( $etag ) ) {
				$version = $etag;
			}
		}
		$this->store( '' === $version ? $this->stored_version() : $version );
	}

	/**
	 * The version in the option, without the filter.
	 */
	private function stored_version(): string {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) && isset( $stored['version'] ) ? (string) $stored['version'] : '';
	}

	/**
	 * Stores a version and the time it was confirmed.
	 *
	 * @param string $version Version, or an empty string to only record the attempt.
	 */
	private function store( string $version ): void {
		update_option(
			self::OPTION,
			array(
				'version'    => $this->valid_version( $version ) ? $version : '',
				'checked_at' => time(),
			),
			false
		);
	}
}
