/**
 * Webpack config for wp-scripts: the default config (blocks found through their
 * block.json) plus the scripts that have no block.json.
 *
 * - An admin screen is a folder blocks/<name>/ with an index.tsx and no
 *   block.json. It builds to build/<name>/index.js.
 * - The inserter category is an editor script: blocks/inserter-category/index.ts
 *   builds to build/inserter-category/index.js with an index.asset.php next to
 *   it (Media_Source_Inserter loads it).
 * - The media modal tab is a script for admin screens that load the media
 *   views: blocks/media-modal/index.ts builds to build/media-modal/index.js
 *   with an index.asset.php and style-index.css (Media_Modal_Tab loads them).
 */
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const SCREENS = [ 'import-screen' ];

const extraEntries = {
	'inserter-category/index': path.resolve( __dirname, 'blocks/inserter-category/index.ts' ),
	'media-modal/index': path.resolve( __dirname, 'blocks/media-modal/index.ts' ),
};

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
		return { ...base, ...screens, ...extraEntries };
	},
};
