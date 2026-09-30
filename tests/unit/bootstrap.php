<?php
/**
 * PHPUnit bootstrap. Loads the plugin classes without WordPress.
 *
 * @package Profotograaf
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'PROFOTOGRAAF_VERSION', '0.0.0-test' );
define( 'PROFOTOGRAAF_FILE', dirname( __DIR__, 2 ) . '/profotograaf.php' );
define( 'PROFOTOGRAAF_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'PROFOTOGRAAF_URL', 'https://example.test/wp-content/plugins/profotograaf/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

require_once dirname( __DIR__ ) . '/wp-stubs.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-autoloader.php';

Profotograaf\Autoloader::register( dirname( __DIR__, 2 ) . '/includes/' );
