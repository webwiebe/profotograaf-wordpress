/**
 * Webpack config for the single-block plugin: the wp-scripts default, which
 * finds the block through its block.json. The repo root config adds the main
 * plugin's own entries, so this plugin builds with this file instead.
 */
module.exports = require( '@wordpress/scripts/config/webpack.config' );
