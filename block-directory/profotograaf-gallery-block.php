<?php
/**
 * Plugin Name:       Profotograaf Gallery Block
 * Plugin URI:        https://github.com/webwiebe/profotograaf-wordpress
 * Description:       A block that shows a Profotograaf client gallery on your site. Paste the gallery link and choose a layout.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Profotograaf
 * Author URI:        https://profotograaf.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       profotograaf-gallery-block
 *
 * @package Profotograaf_Gallery_Block
 */

defined( 'ABSPATH' ) || exit;

define( 'PROFOTOGRAAF_GALLERY_BLOCK_VERSION', '0.1.0' );
define( 'PROFOTOGRAAF_GALLERY_BLOCK_DIR', plugin_dir_path( __FILE__ ) );
define( 'PROFOTOGRAAF_GALLERY_BLOCK_URL', plugin_dir_url( __FILE__ ) );
define( 'PROFOTOGRAAF_GALLERY_BLOCK_PLATFORM', 'https://profotograaf.nl' );

require_once PROFOTOGRAAF_GALLERY_BLOCK_DIR . 'includes/gallery-source.php';
require_once PROFOTOGRAAF_GALLERY_BLOCK_DIR . 'includes/render.php';

add_action( 'init', 'profotograaf_gallery_block_register' );
add_filter( 'script_loader_tag', 'profotograaf_gallery_block_script_tag', 10, 2 );

/**
 * Registers the block from the build folder.
 */
function profotograaf_gallery_block_register(): void {
	register_block_type(
		PROFOTOGRAAF_GALLERY_BLOCK_DIR . 'build/gallery',
		array( 'render_callback' => 'profotograaf_gallery_block_render' )
	);
}

/**
 * Tells the gallery script where the platform is. The script is served from
 * this site, so it cannot learn the origin from its own URL.
 *
 * @param string $tag    Script tag.
 * @param string $handle Script handle.
 */
function profotograaf_gallery_block_script_tag( string $tag, string $handle ): string {
	if ( 'profotograaf-gallery-block-embed' !== $handle ) {
		return $tag;
	}
	return str_replace( ' src=', ' data-platform="' . esc_attr( PROFOTOGRAAF_GALLERY_BLOCK_PLATFORM ) . '" src=', $tag );
}
