<?php
/**
 * The "Profotograaf full page" page template.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * An optional page template without the theme header and footer, so a
 * full-page gallery fills the page.
 *
 * Block themes get a block template through register_block_template().
 * Classic themes get a page template from the plugin's templates folder.
 */
class Full_Page_Template {

	public const SLUG = 'profotograaf-full-page.php';

	public const BLOCK_TEMPLATE = 'profotograaf//full-page';

	private const FILE = 'templates/full-page.php';

	/**
	 * Registers the block template on a block theme.
	 */
	public function register_block_template(): void {
		if ( ! wp_is_block_theme() ) {
			return;
		}
		register_block_template(
			self::BLOCK_TEMPLATE,
			array(
				'title'       => __( 'Profotograaf full page', 'profotograaf' ),
				'description' => __( 'Shows the page content without the site header and footer, for a full-page gallery.', 'profotograaf' ),
				'post_types'  => array( 'page', 'post' ),
				'content'     => self::block_markup(),
			)
		);
	}

	/**
	 * The block template: the post content in a full width group with no
	 * padding or margin.
	 */
	public static function block_markup(): string {
		return '<!-- wp:group {"tagName":"main","align":"full","style":{"spacing":{"padding":{"top":"0","right":"0","bottom":"0","left":"0"},"margin":{"top":"0","bottom":"0"}}},"layout":{"type":"default"}} -->'
			. '<main class="wp-block-group alignfull" style="margin-top:0;margin-bottom:0;padding-top:0;padding-right:0;padding-bottom:0;padding-left:0">'
			. '<!-- wp:post-content {"align":"full","layout":{"type":"default"}} /-->'
			. '</main>'
			. '<!-- /wp:group -->';
	}

	/**
	 * Adds the template to the template list of a classic theme.
	 *
	 * @param array<string,string> $templates Template names by file.
	 * @return array<string,string>
	 */
	public function add_to_list( array $templates ): array {
		if ( ! wp_is_block_theme() ) {
			$templates[ self::SLUG ] = __( 'Profotograaf full page', 'profotograaf' );
		}
		return $templates;
	}

	/**
	 * Swaps in the plugin's template file when the current page uses it.
	 *
	 * @param string $template Path of the template WordPress picked.
	 * @return string
	 */
	public function resolve( string $template ): string {
		if ( wp_is_block_theme() || ! is_singular() || get_page_template_slug() !== self::SLUG ) {
			return $template;
		}
		return PROFOTOGRAAF_DIR . self::FILE;
	}
}
