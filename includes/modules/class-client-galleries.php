<?php
/**
 * Client galleries block ("Find your gallery").
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Config;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Server side output of the `profotograaf/client-galleries` block.
 *
 * The block is a link to the client portal. It needs no data from the
 * connected account: the portal address is a block attribute, or a site wide
 * default from the `profotograaf_client_portal_address` filter. That keeps the
 * block working on a site that is not connected, and makes it a plain link
 * with no request to Profotograaf while a visitor views the page.
 *
 * Portal addresses (see the platform's custom domain documentation):
 *
 * - a slug such as `studio`: `<platform>/studio/client`;
 * - a host such as `studio.profotograaf.nl` or `photos.example.com`, a
 *   photographer subdomain or a custom domain, where the portal is at `/client`;
 * - a full http(s) URL, used as given, with `/client` added when it has no path.
 *
 * The block itself is registered by the Blocks module from
 * blocks/client-galleries/block.json. This module only attaches the render
 * callback and the editor script translations.
 */
class Client_Galleries implements Module {

	public const BLOCK = 'profotograaf/client-galleries';

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		unset( $plugin );
		add_filter( 'register_block_type_args', array( $this, 'add_render_callback' ), 10, 2 );
		add_action( 'init', array( $this, 'load_script_translations' ), 20 );
	}

	/**
	 * Attaches the render callback when the block is registered.
	 *
	 * @param array<string,mixed> $args Block type arguments.
	 * @param string              $name Block name.
	 * @return array<string,mixed>
	 */
	public function add_render_callback( array $args, string $name ): array {
		if ( self::BLOCK === $name ) {
			$args['render_callback'] = array( $this, 'render' );
		}
		return $args;
	}

	/**
	 * Loads the editor script translations from this plugin's languages folder.
	 */
	public function load_script_translations(): void {
		if ( ! function_exists( 'generate_block_asset_handle' ) ) {
			return;
		}
		$handle = generate_block_asset_handle( self::BLOCK, 'editorScript' );
		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_set_script_translations( $handle, 'profotograaf', PROFOTOGRAAF_DIR . 'languages' );
		}
	}

	/**
	 * Turns what a person typed into the portal URL, or an empty string.
	 *
	 * @param string $address Slug, host or URL.
	 */
	public static function portal_url( string $address ): string {
		$address = trim( $address );
		if ( '' === $address || preg_match( '/\s/', $address ) ) {
			return '';
		}

		// A full URL.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $address ) ) {
			$scheme = strtolower( (string) wp_parse_url( $address, PHP_URL_SCHEME ) );
			$host   = (string) wp_parse_url( $address, PHP_URL_HOST );
			if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return '';
			}
			$path = (string) wp_parse_url( $address, PHP_URL_PATH );
			if ( '' === trim( $path, '/' ) ) {
				return rtrim( $address, '/' ) . '/client';
			}
			return $address;
		}

		// A slug on the platform.
		if ( preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $address ) ) {
			return Config::platform_url() . '/' . strtolower( $address ) . '/client';
		}

		// A host name, with an optional port and path.
		if ( preg_match( '#^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?(?:/.*)?$#i', $address ) && false !== strpos( $address, '.' ) ) {
			return self::portal_url( 'https://' . $address );
		}

		return '';
	}

	/**
	 * The address to use: the block's own, else the site wide default.
	 *
	 * @param string $own Address saved in the block.
	 */
	public function resolve_address( string $own ): string {
		if ( '' !== trim( $own ) ) {
			return $own;
		}

		/**
		 * Filters the site wide default client portal address.
		 *
		 * Used by client galleries blocks that have no address of their own.
		 * A slug, host or URL, see the class description.
		 *
		 * @param string $address Default address, empty when there is none.
		 */
		return (string) apply_filters( 'profotograaf_client_portal_address', '' );
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string HTML.
	 */
	public function render( array $attributes ): string {
		$address = $this->resolve_address( isset( $attributes['portal'] ) ? (string) $attributes['portal'] : '' );
		$url     = self::portal_url( $address );

		if ( '' === $url ) {
			return $this->render_notice();
		}

		$heading = $this->text( $attributes, 'heading', __( 'Find your gallery', 'profotograaf' ) );
		$text    = $this->text( $attributes, 'description', __( 'Sign in to the client portal to see your photos.', 'profotograaf' ) );
		$label   = $this->text( $attributes, 'buttonLabel', __( 'Open my gallery', 'profotograaf' ) );
		$new_tab = ! empty( $attributes['openInNewTab'] );

		$rel = $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';

		return sprintf(
			'<div %1$s><h3 class="wp-block-profotograaf-client-galleries__heading">%2$s</h3><p class="wp-block-profotograaf-client-galleries__text">%3$s</p><a class="wp-block-profotograaf-client-galleries__button" href="%4$s"%5$s>%6$s</a></div>',
			$this->wrapper_attributes(),
			esc_html( $heading ),
			esc_html( $text ),
			esc_url( $url ),
			$rel,
			esc_html( $label )
		);
	}

	/**
	 * A note for people who can edit, shown while the block has no address.
	 * Visitors get nothing.
	 */
	private function render_notice(): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return sprintf(
			'<div %1$s><p class="wp-block-profotograaf-client-galleries__notice">%2$s</p></div>',
			$this->wrapper_attributes(),
			esc_html__( 'Only editors see this note. The "Find your gallery" block has no Profotograaf address yet. Enter it in the block settings. Until then visitors do not see this block.', 'profotograaf' )
		);
	}

	/**
	 * The wrapper attributes, with the block support classes and styles.
	 */
	private function wrapper_attributes(): string {
		return function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : 'class="wp-block-profotograaf-client-galleries"';
	}

	/**
	 * A text attribute, or the default when it is empty.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $key        Attribute name.
	 * @param string              $fallback   Default text.
	 */
	private function text( array $attributes, string $key, string $fallback ): string {
		$value = isset( $attributes[ $key ] ) && is_string( $attributes[ $key ] ) ? trim( $attributes[ $key ] ) : '';
		return '' === $value ? $fallback : $value;
	}
}
