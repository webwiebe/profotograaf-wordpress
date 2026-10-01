<?php
/**
 * Block patterns.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `Profotograaf` pattern category and every pattern found in
 * `patterns/*.php`.
 *
 * A pattern file returns an array with `title`, `description`, `keywords` and
 * `content` (block markup). The file name is the pattern slug. Text goes through
 * the translation functions when the file is loaded, so the strings land in the
 * .pot. The patterns use the gallery display options and the client galleries
 * options. An option left out of the markup follows the site default.
 *
 * To add a pattern, add a file to `patterns/`. No PHP changes here.
 */
class Patterns implements Module {

	public const CATEGORY = 'profotograaf';

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		unset( $plugin );
		add_action( 'init', array( $this, 'register_patterns' ) );
	}

	/**
	 * Registers the category and the patterns.
	 */
	public function register_patterns(): void {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}

		if ( function_exists( 'register_block_pattern_category' ) ) {
			register_block_pattern_category(
				self::CATEGORY,
				array(
					'label'       => __( 'Profotograaf', 'profotograaf' ),
					'description' => __( 'Sections for galleries, the client portal and enquiries.', 'profotograaf' ),
				)
			);
		}

		foreach ( $this->files() as $file ) {
			$pattern = require $file;
			if ( ! is_array( $pattern ) || empty( $pattern['title'] ) || empty( $pattern['content'] ) ) {
				continue;
			}
			$pattern['categories'] = array( self::CATEGORY );
			register_block_pattern( 'profotograaf/' . basename( $file, '.php' ), $pattern );
		}
	}

	/**
	 * Serialised block markup: a comment delimiter with JSON attributes.
	 *
	 * @param string              $name       Block name, such as `profotograaf/gallery`.
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function block( string $name, array $attributes = array() ): string {
		$json = array() === $attributes ? '' : ' ' . wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
		return '<!-- wp:' . $name . $json . ' /-->';
	}

	/**
	 * A core heading block.
	 *
	 * @param string $text  Translated text, not yet escaped.
	 * @param int    $level Heading level.
	 */
	public static function heading( string $text, int $level = 2 ): string {
		return sprintf(
			'<!-- wp:heading {"level":%1$d} --><h%1$d class="wp-block-heading">%2$s</h%1$d><!-- /wp:heading -->',
			$level,
			esc_html( $text )
		);
	}

	/**
	 * A core paragraph block.
	 *
	 * @param string $text Translated text, not yet escaped.
	 */
	public static function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * A core group block around inner markup.
	 *
	 * @param string $inner Block markup.
	 */
	public static function group( string $inner ): string {
		return '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide">' . $inner . '</div><!-- /wp:group -->';
	}

	/**
	 * Pattern files, in name order.
	 *
	 * @return array<int,string>
	 */
	private function files(): array {
		$files = glob( PROFOTOGRAAF_DIR . 'patterns/*.php' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		sort( $files );
		return $files;
	}
}
