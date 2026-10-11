<?php
/**
 * The full page template.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Modules;

use Profotograaf\Full_Page_Template as Template;
use Profotograaf\Module;
use Profotograaf\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the "Profotograaf full page" template into block and classic themes.
 */
class Full_Page_Template implements Module {

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function register( Plugin $plugin ): void {
		$template = new Template();
		add_action( 'init', array( $template, 'register_block_template' ) );
		add_filter( 'theme_page_templates', array( $template, 'add_to_list' ) );
		add_filter( 'theme_post_templates', array( $template, 'add_to_list' ) );
		add_filter( 'template_include', array( $template, 'resolve' ) );
	}
}
