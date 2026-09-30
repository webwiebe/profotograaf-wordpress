<?php
/**
 * Plugin Name:       Profotograaf for WordPress
 * Plugin URI:        https://github.com/webwiebe/profotograaf-wordpress
 * Description:       Show your Profotograaf galleries on your own site, offer clients a way in, and send form enquiries to your Profotograaf inbox.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Profotograaf
 * Author URI:        https://profotograaf.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       profotograaf
 * Domain Path:       /languages
 *
 * @package Profotograaf
 */

defined( 'ABSPATH' ) || exit;

define( 'PROFOTOGRAAF_VERSION', '0.1.0' );
define( 'PROFOTOGRAAF_FILE', __FILE__ );
define( 'PROFOTOGRAAF_DIR', plugin_dir_path( __FILE__ ) );
define( 'PROFOTOGRAAF_URL', plugin_dir_url( __FILE__ ) );

require_once PROFOTOGRAAF_DIR . 'includes/class-autoloader.php';

Profotograaf\Autoloader::register( PROFOTOGRAAF_DIR . 'includes/' );

register_deactivation_hook( __FILE__, array( Profotograaf\Plugin::class, 'deactivate' ) );

Profotograaf\Plugin::instance()->boot();
