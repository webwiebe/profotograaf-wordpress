<?php
/**
 * Gallery block, shortcode and oEmbed provider.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Embed_Script;
use Profotograaf\Gallery_Index;
use Profotograaf\Gallery_Renderer;
use Profotograaf\Gallery_Rest;
use Profotograaf\Module;
use Profotograaf\Oembed;
use Profotograaf\Plugin;
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
	 * Source path of the editor script the JSON translations are made from.
	 */
	public const EDITOR_SOURCE = 'blocks/gallery/edit.js';

	/**
	 * Renderer shared by the block and the shortcode.
	 *
	 * @var Gallery_Renderer|null
	 */
	private ?Gallery_Renderer $renderer = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$script         = new Embed_Script();
		$index          = new Gallery_Index( $plugin->api() );
		$renderer       = new Gallery_Renderer( $script, $index, $plugin->settings() );
		$this->renderer = $renderer;

		( new Shortcode( $renderer ) )->register();
		( new Oembed( $script ) )->register();
		( new Gallery_Rest( $plugin->api(), $index ) )->register();

		add_action( Gallery_Index::LOOKUP_HOOK, array( $index, 'lookup' ) );
		add_action( Embed_Script::REFRESH_HOOK, array( $script, 'refresh' ) );
		add_filter( 'register_block_type_args', array( $this, 'block_args' ), 10, 2 );
		add_filter( 'load_script_translation_file', array( $this, 'script_translation_file' ), 10, 3 );
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
			)
		);
	}

	/**
	 * Points the editor script at the shipped JSON translations.
	 *
	 * WordPress looks for `<domain>-<locale>-<md5 of the script path>.json`,
	 * and the block build moves the script to build/gallery/index.js, so the
	 * file made from the source (`wp i18n make-json`) is not where it looks.
	 *
	 * @param string|false $file   File WordPress would load.
	 * @param string       $handle Script handle.
	 * @param string       $domain Text domain.
	 * @return string|false
	 */
	public function script_translation_file( $file, $handle, $domain ) {
		unset( $handle );
		if ( 'profotograaf' !== $domain || ( is_string( $file ) && is_readable( $file ) ) ) {
			return $file;
		}
		$shipped = PROFOTOGRAAF_DIR . 'languages/profotograaf-' . determine_locale() . '-' . md5( self::EDITOR_SOURCE ) . '.json';
		return is_readable( $shipped ) ? $shipped : $file;
	}
}
