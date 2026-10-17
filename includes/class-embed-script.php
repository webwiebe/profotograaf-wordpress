<?php
/**
 * The gallery script the plugin ships.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Knows the URL of the interactive gallery script and puts it on the page once.
 *
 * The script is `dist/embed.js` of the @profotograaf/embed package, copied to
 * build/embed/ by the block build (bin/blocks.mjs). It is served from this
 * plugin, so no page loads JavaScript from the platform. The query string
 * carries the plugin version, which changes with every release that bumps the
 * package.
 */
class Embed_Script {

	public const HANDLE = 'profotograaf-embed';

	/**
	 * Path of the script below the plugin folder.
	 */
	public const FILE = 'build/embed/embed.js';

	/**
	 * Whether the tag was printed by hand because the footer already ran.
	 *
	 * @var bool
	 */
	private bool $printed = false;

	/**
	 * Whether the connection hint was handed out on this page.
	 *
	 * @var bool
	 */
	private bool $hinted = false;

	/**
	 * Whether the watcher script is queued for the footer.
	 *
	 * @var bool
	 */
	private bool $watching = false;

	/**
	 * URL of the script, from this plugin.
	 */
	public function url(): string {
		return PROFOTOGRAAF_URL . self::FILE;
	}

	/**
	 * The origin of the platform, which the script cannot learn from its own
	 * URL because it is served from this site (professionals#2653).
	 */
	public function platform_origin(): string {
		$url    = Config::platform_url();
		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		return '' === $scheme || '' === $host ? '' : $scheme . '://' . $host . ( $port ? ':' . $port : '' );
	}

	/**
	 * Link tags that open the connection to the platform early, once per page.
	 *
	 * Galleries are rendered after wp_head, so the hint goes next to the first
	 * gallery. `preconnect` is allowed in the body. The second tag with
	 * crossorigin covers the data requests the script makes with fetch, which
	 * use a separate connection.
	 *
	 * @return string HTML, or an empty string after the first call.
	 */
	public function preconnect(): string {
		if ( $this->hinted ) {
			return '';
		}
		$origin = $this->platform_origin();
		if ( '' === $origin ) {
			return '';
		}
		$this->hinted = true;
		$origin       = esc_url( $origin );
		return sprintf( '<link rel="preconnect" href="%1$s"><link rel="preconnect" href="%1$s" crossorigin>', $origin );
	}

	/**
	 * Asks for the script to be printed once on this page.
	 *
	 * Queued in the footer with the async strategy. When the footer scripts
	 * already printed (a block rendered by a late hook), the tag is written
	 * directly, once.
	 */
	public function enqueue(): void {
		if ( did_action( 'wp_print_footer_scripts' ) ) {
			if ( ! $this->printed ) {
				$this->printed = true;
				printf( '<script async src="%1$s?ver=%2$s" data-platform="%3$s" onerror="%4$s"></script>', esc_url( $this->url() ), esc_attr( PROFOTOGRAAF_VERSION ), esc_attr( $this->platform_origin() ), esc_attr( $this->error_handler() ) ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the footer already ran, so the queue can no longer print it.
				$this->print_watcher();
			}
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			$this->url(),
			array(),
			PROFOTOGRAAF_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'async',
			)
		);
		// Not wp_add_inline_script(): an inline script attached to the handle makes
		// WordPress drop the async strategy.
		if ( ! $this->watching ) {
			$this->watching = true;
			add_action( 'wp_footer', array( $this, 'print_watcher' ), 100 );
		}
	}

	/**
	 * Prints the script that marks galleries the script left empty (see
	 * Empty_Gallery). Hooked to wp_footer after the queued scripts.
	 */
	public function print_watcher(): void {
		echo '<script>' . Empty_Gallery::script() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.EnqueuedResources.NonEnqueuedScript -- fixed script, no user input.
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
		return (string) preg_replace( '/<script\b/', '<script data-platform="' . esc_attr( $this->platform_origin() ) . '" onerror="' . esc_attr( $this->error_handler() ) . '"', $tag, 1 );
	}

	/**
	 * JavaScript that runs when the script cannot be loaded: it logs what to
	 * check to the console and replaces the empty boxes (galleries without a
	 * fallback link) with a plain message.
	 */
	public function error_handler(): string {
		$flags   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
		$console = 'Profotograaf: the gallery script could not be loaded from ' . $this->url() . '. Galleries show their fallback link instead. Check that the plugin folder is complete (build/embed/embed.js) and that no ad blocker, firewall or Content-Security-Policy script-src rule blocks it.';
		$message = __( 'This gallery could not be loaded. Please try again later.', 'profotograaf' );

		return 'console.error(' . wp_json_encode( $console, $flags ) . ');'
			. 'document.querySelectorAll("[data-profotograaf-gallery]").forEach(function(e){'
			. 'e.setAttribute("data-profotograaf-failed","");'
			. 'if(!e.querySelector("a")){e.textContent=' . wp_json_encode( $message, $flags ) . '}})';
	}
}
