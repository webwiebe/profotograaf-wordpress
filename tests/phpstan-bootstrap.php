<?php
/**
 * Constants the main plugin file defines at load time, so PHPStan knows them.
 *
 * @package Profotograaf
 */

define( 'PROFOTOGRAAF_URL', 'https://example.test/wp-content/plugins/profotograaf/' );
define( 'PROFOTOGRAAF_DIR', '/var/www/html/wp-content/plugins/profotograaf/' );

require_once __DIR__ . '/wp-cli-stubs.php';
require_once __DIR__ . '/wp-cli-utils-stubs.php';
