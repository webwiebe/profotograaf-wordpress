/**
 * Webpack config for wp-scripts: the default config (blocks found through their
 * block.json) plus the admin screens that have no block.json.
 *
 * An admin screen is a folder blocks/<name>/ with an index.tsx and no
 * block.json. It builds to build/<name>/index.js.
 */
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const SCREENS = [ 'import-screen' ];

module.exports = {
	...defaultConfig,
	entry: async () => {
		const base =
			typeof defaultConfig.entry === 'function'
				? await defaultConfig.entry()
				: defaultConfig.entry;
		const screens = {};
		for ( const name of SCREENS ) {
			const file = path.resolve( __dirname, 'blocks', name, 'index.tsx' );
			if ( fs.existsSync( file ) ) {
				screens[ `${ name }/index` ] = file;
			}
		}
		return { ...base, ...screens };
	},
};
