<?php
/**
 * Gallery block, shortcode and oEmbed provider.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Embed_Script;
use Profotograaf\Full_Page_Payload;
use Profotograaf\Gallery_Index;
use Profotograaf\Gallery_Renderer;
use Profotograaf\Gallery_Rest;
use Profotograaf\Link_Consent;
use Profotograaf\Module;
use Profotograaf\Oembed;
use Profotograaf\Plugin;
use Profotograaf\Sections_Rest;
use Profotograaf\Settings;
use Profotograaf\Shortcode;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the three ways a gallery gets onto a page.
 *
 * - The block `profotograaf/gallery` (blocks/gallery, registered by the Blocks
 *   module) is rendered on the server by Gallery_Renderer. This module supplies
 *   the render callback, so the block folder holds no PHP.
 * - The shortcode `[profotograaf_gallery]`, same renderer.
 * - The oEmbed provider for pasted share links.
 * - The REST routes the block's picker uses.
 */
class Gallery_Embed implements Module {

	public const BLOCK = 'profotograaf/gallery';

	/**
	 * Renderer shared by the block and the shortcode.
	 *
	 * @var Gallery_Renderer|null
	 */
	private ?Gallery_Renderer $renderer = null;

	/**
	 * Settings, for the defaults the editor preview shows.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Consent to the Free plan link. The block renders nothing without it.
	 *
	 * @var Link_Consent|null
	 */
	private ?Link_Consent $consent = null;

	/**
	 * Name of the JavaScript global that carries the site defaults.
	 */
	public const EDITOR_GLOBAL = 'profotograafGalleryDefaults';

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$script         = new Embed_Script();
		$index          = new Gallery_Index( $plugin->api() );
		$renderer       = new Gallery_Renderer( $script, $index, $plugin->settings(), new Full_Page_Payload( $plugin->api() ) );
		$this->renderer = $renderer;
		$this->settings = $plugin->settings();
		$this->consent  = $plugin->link_consent();

		( new Shortcode( $renderer, $this->consent ) )->register();
		( new Oembed( $script ) )->register();
		( new Gallery_Rest( $plugin->api(), $index ) )->register();
		( new Sections_Rest( $plugin->api() ) )->register();

		add_action( Gallery_Index::LOOKUP_HOOK, array( $index, 'lookup' ) );
		add_action( Embed_Script::REFRESH_HOOK, array( $script, 'refresh' ) );
		add_filter( 'script_loader_tag', array( $script, 'add_error_handler' ), 10, 2 );
		add_filter( 'register_block_type_args', array( $this, 'block_args' ), 10, 2 );
		add_action( 'init', array( $this, 'load_script_translations' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'localize_editor_defaults' ) );
	}

	/**
	 * Gives the gallery block its render callback.
	 *
	 * @param array<string,mixed> $args Block type arguments.
	 * @param string              $name Block name.
	 * @return array<string,mixed>
	 */
	public function block_args( array $args, string $name ): array {
		if ( self::BLOCK === $name ) {
			$args['render_callback'] = array( $this, 'render_block' );
		}
		return $args;
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string HTML.
	 */
	public function render_block( array $attributes ): string {
		if ( null === $this->renderer ) {
			return '';
		}
		if ( null !== $this->consent && ! $this->consent->allows_render() ) {
			return $this->consent->refusal( Settings_Page::url() );
		}
		$class = 'wp-block-profotograaf-gallery';
		if ( ! empty( $attributes['align'] ) ) {
			$class .= ' align' . sanitize_html_class( (string) $attributes['align'] );
		}
		return $this->renderer->render(
			array(
				'id'     => $attributes['galleryId'] ?? '',
				'layout' => $attributes['layout'] ?? '',
				'title'  => $attributes['galleryTitle'] ?? '',
				'url'    => $attributes['galleryUrl'] ?? '',
				'class'  => $class,
			) + Gallery_Renderer::options_from_block( $attributes ) + $this->wrapper_argument( (string) ( $attributes['anchor'] ?? '' ) )
		);
	}

	/**
	 * The wrapper argument for the renderer. WordPress builds the attribute
	 * string from the block's supports (color, typography, border, spacing,
	 * anchor, extra classes and alignment).
	 *
	 * WordPress 6.9 and older add no anchor to a server-rendered block, so the
	 * anchor becomes the div's id when the supports left it out.
	 *
	 * @param string $anchor The block's HTML anchor.
	 * @return array<string,callable>
	 */
	private function wrapper_argument( string $anchor ): array {
		if ( ! function_exists( 'get_block_wrapper_attributes' ) ) {
			return array();
		}
		return array(
			'wrapper' => static function ( array $attributes ) use ( $anchor ): string {
				$html = get_block_wrapper_attributes( $attributes );
				if ( '' === $anchor || 1 === preg_match( '/(^|\s)id="/', $html ) ) {
					return $html;
				}
				return $html . ' id="' . esc_attr( $anchor ) . '"';
			},
		);
	}

	/**
	 * Loads the editor script translations from this plugin's languages folder.
	 *
	 * The JSON files are named after the built script (build/gallery/index.js),
	 * which is the path WordPress hashes, so the standard lookup finds them.
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
	 * The site defaults the editor preview draws, resolved like the front end.
	 * An empty string means the Profotograaf default.
	 *
	 * @return array{columns:string,columnsTablet:string,columnsMobile:string,gap:string,ratio:string,layout:string}
	 */
	public function editor_defaults(): array {
		if ( null === $this->settings ) {
			return array(
				'columns'       => '',
				'columnsTablet' => '',
				'columnsMobile' => '',
				'gap'           => '',
				'ratio'         => '',
				'layout'        => '',
			);
		}
		return array(
			'columns'       => (string) $this->settings->resolve( 'gallery_columns' ),
			'columnsTablet' => (string) $this->settings->resolve( 'gallery_columns_tablet' ),
			'columnsMobile' => (string) $this->settings->resolve( 'gallery_columns_mobile' ),
			'gap'           => (string) $this->settings->resolve( 'gallery_gap' ),
			'ratio'         => (string) $this->settings->resolve( 'gallery_ratio' ),
			'layout'        => (string) $this->settings->resolve( 'default_layout' ),
		);
	}

	/**
	 * Hands the site defaults to the block editor script.
	 */
	public function localize_editor_defaults(): void {
		if ( ! function_exists( 'generate_block_asset_handle' ) ) {
			return;
		}
		$handle = generate_block_asset_handle( self::BLOCK, 'editorScript' );
		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_localize_script( $handle, self::EDITOR_GLOBAL, $this->editor_defaults() );
		}
	}
}
