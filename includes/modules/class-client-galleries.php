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
use Profotograaf\Settings;

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
 * The `/client` part is the portal path. The block attribute `portalPath` sets
 * it for one block, the `client_portal_path` setting for the site, and
 * `/client` is the default.
 *
 * The optional `gallery` attribute holds the share link of one delivered
 * gallery, chosen in the editor. When it is set the block links to that
 * gallery. `linkTo` is `gallery` for blocks inserted through the
 * "Open a delivered gallery" variation, so the editor asks for a gallery.
 *
 * The block itself is registered by the Blocks module from
 * blocks/client-galleries/block.json. This module only attaches the render
 * callback and the editor script translations.
 */
class Client_Galleries implements Module {

	public const BLOCK = 'profotograaf/client-galleries';

	public const DEFAULT_PATH = '/client';

	public const DEFAULT_HEADING_LEVEL = 3;

	/**
	 * `linkTo` value of the "Open a delivered gallery" variation: the block
	 * links to the gallery in its `gallery` attribute.
	 */
	public const LINK_TO_GALLERY = 'gallery';

	/**
	 * Settings.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Settings, or null to create them on first use.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$this->settings = $plugin->settings();
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
	 * Cleans a portal path: a site relative path such as `/client`.
	 *
	 * A missing leading slash is added and a trailing one removed. Returns null
	 * for the root, a host, a query, a fragment, a space or a `..` segment.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null The path, or null when it is not usable.
	 */
	public static function sanitize_path( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$path = trim( (string) $value );
		if ( str_starts_with( $path, '//' ) || ! preg_match( '#^/?[A-Za-z0-9._~%/-]+$#', $path ) ) {
			return null;
		}
		$path = '/' . trim( $path, '/' );
		if ( '/' === $path || in_array( '..', explode( '/', $path ), true ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * Turns what a person typed into the portal URL, or an empty string.
	 *
	 * @param string $address Slug, host or URL.
	 * @param string $path    Portal path, used when the address has none.
	 */
	public static function portal_url( string $address, string $path = self::DEFAULT_PATH ): string {
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
			$own_path = (string) wp_parse_url( $address, PHP_URL_PATH );
			if ( '' === trim( $own_path, '/' ) ) {
				return rtrim( $address, '/' ) . $path;
			}
			return $address;
		}

		// A slug on the platform.
		if ( preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $address ) ) {
			return Config::platform_url() . '/' . strtolower( $address ) . $path;
		}

		// A host name, with an optional port and path.
		if ( preg_match( '#^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?(?:/.*)?$#i', $address ) && false !== strpos( $address, '.' ) ) {
			return self::portal_url( 'https://' . $address, $path );
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
	 * Turns a stored gallery share link into the URL to link to, or an empty
	 * string. Only an absolute http(s) URL with a host is used.
	 *
	 * @param mixed $value Raw `gallery` attribute.
	 */
	public static function gallery_url( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$url = trim( $value );
		if ( '' === $url || preg_match( '/\s/', $url ) || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return '' !== (string) wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
	}

	/**
	 * Renders the block.
	 *
	 * With a `gallery` share link the block links to that gallery, in the same
	 * tab. The share page shows its own install prompt, so the block says
	 * nothing about installing. Without one the block links to the client
	 * portal, as it always did.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string HTML.
	 */
	public function render( array $attributes ): string {
		$gallery = self::gallery_url( $attributes['gallery'] ?? '' );
		if ( '' !== $gallery ) {
			return $this->markup(
				$attributes,
				array(
					'heading' => __( 'Your photos', 'profotograaf' ),
					'text'    => __( 'See the photos in your gallery.', 'profotograaf' ),
					'label'   => __( 'Open your gallery', 'profotograaf' ),
				),
				$gallery,
				false
			);
		}
		if ( self::LINK_TO_GALLERY === ( $attributes['linkTo'] ?? '' ) ) {
			return $this->render_notice( __( 'Only editors see this note. Choose a gallery in the block settings. Until then visitors do not see this block.', 'profotograaf' ) );
		}

		$address = $this->resolve_address( isset( $attributes['portal'] ) ? (string) $attributes['portal'] : '' );
		$path    = (string) $this->settings()->resolve( 'client_portal_path', array( 'client_portal_path' => $attributes['portalPath'] ?? '' ) );
		$url     = self::portal_url( $address, $path );

		if ( '' === $url ) {
			return $this->render_notice( __( 'Only editors see this note. The "Find your gallery" block has no Profotograaf address yet. Enter it in the block settings. Until then visitors do not see this block.', 'profotograaf' ) );
		}

		return $this->markup(
			$attributes,
			array(
				'heading' => __( 'Find your gallery', 'profotograaf' ),
				'text'    => __( 'Sign in to the client portal to see your photos.', 'profotograaf' ),
				'label'   => __( 'Open my gallery', 'profotograaf' ),
			),
			$url,
			! empty( $attributes['openInNewTab'] )
		);
	}

	/**
	 * The block markup: heading, text and a button link.
	 *
	 * @param array<string,mixed>                            $attributes Block attributes.
	 * @param array{heading:string,text:string,label:string} $defaults Text used where the block has none.
	 * @param string                                         $url        Link target.
	 * @param bool                                           $new_tab    Whether the link opens a new tab.
	 */
	private function markup( array $attributes, array $defaults, string $url, bool $new_tab ): string {
		$heading = $this->text( $attributes, 'heading', $defaults['heading'] );
		$text    = $this->text( $attributes, 'description', $defaults['text'] );
		$label   = $this->text( $attributes, 'buttonLabel', $defaults['label'] );

		$rel = $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';

		$tag = self::heading_tag( $attributes['headingLevel'] ?? self::DEFAULT_HEADING_LEVEL );

		return sprintf(
			'<div %1$s><%7$s class="wp-block-profotograaf-client-galleries__heading">%2$s</%7$s><p class="wp-block-profotograaf-client-galleries__text">%3$s</p><div class="wp-block-button"><a class="wp-block-profotograaf-client-galleries__button wp-block-button__link wp-element-button" href="%4$s"%5$s>%6$s</a></div></div>',
			$this->wrapper_attributes(),
			esc_html( $heading ),
			esc_html( $text ),
			esc_url( $url ),
			$rel,
			esc_html( $label ),
			$tag
		);
	}

	/**
	 * The element for a heading level: h2 to h6, or `p` for 0. Anything else
	 * gives the default, so the block never outputs a second h1.
	 *
	 * @param mixed $level Saved level.
	 */
	public static function heading_tag( $level ): string {
		$level = is_numeric( $level ) ? (int) $level : self::DEFAULT_HEADING_LEVEL;
		if ( 0 === $level ) {
			return 'p';
		}
		return $level >= 2 && $level <= 6 ? 'h' . $level : 'h' . self::DEFAULT_HEADING_LEVEL;
	}

	/**
	 * The settings.
	 */
	private function settings(): Settings {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}
		return $this->settings;
	}

	/**
	 * A note for people who can edit, shown while the block has nothing to
	 * link to. Visitors get nothing.
	 *
	 * @param string $message The note.
	 */
	private function render_notice( string $message ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return sprintf(
			'<div %1$s><p class="wp-block-profotograaf-client-galleries__notice">%2$s</p></div>',
			$this->wrapper_attributes(),
			esc_html( $message )
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
