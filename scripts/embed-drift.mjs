// Drift checks between the PHP renderers and the @profotograaf/embed package.
// scripts/embed-package.test.mjs runs them on the real sources.

const HOST_FILE = 'class-gallery-renderer.php';
const SHADOW_FILES = /^class-declarative-.*\.php$/;

/**
 * Package fixture files the plugin has to carry: the inputs (`*.json`) and the
 * server golden files (`*.server.html`). The JS-only goldens and the README stay upstream.
 *
 * @param {string[]} upstream Names in the package's fixtures folder.
 * @param {string[]} plugin   Names in tests/fixtures/embed.
 * @return {string[]} Upstream names the plugin lacks.
 */
export function missingFixtures( upstream, plugin ) {
	return upstream.filter( ( name ) => /\.(json|server\.html)$/.test( name ) && ! plugin.includes( name ) );
}

const words = ( text ) => text.split( /\s+/ ).filter( Boolean );

/**
 * Class names a shadow tree renderer writes: `class="..."` literals up to the
 * first PHP expression, and the strings of `$class = ...` lines.
 *
 * @param {string} source PHP source.
 * @return {Set<string>} Class names.
 */
function classesIn( source ) {
	const used = new Set();
	for ( const [ , literal ] of source.matchAll( /class="([^"]*)"/g ) ) {
		// Text after a quote is a PHP expression, such as `' . $layout . '`.
		words( literal.split( "'" )[ 0 ] ).forEach( ( token ) => used.add( token ) );
	}
	for ( const [ , line ] of source.matchAll( /\$class\s*=([^;]*);/g ) ) {
		for ( const [ , text ] of line.matchAll( /'([^']*)'/g ) ) {
			words( text ).forEach( ( token ) => used.add( token ) );
		}
	}
	return used;
}

/**
 * Classes, ARIA attributes and roles of one shadow tree renderer that the contract does not list.
 *
 * @param {string} name     File name.
 * @param {string} source   PHP source.
 * @param {object} contract Parsed contract/markup.json.
 * @return {string[]} Findings.
 */
function shadowFindings( name, source, contract ) {
	const aria = Object.keys( contract.aria ).filter( ( key ) => key.startsWith( 'aria-' ) );
	const roles = contract.aria.role.split( /[^a-z]+/ );
	return [
		...[ ...classesIn( source ) ].filter( ( token ) => ! ( token in contract.classes ) ).map( ( token ) => `${ name }: class "${ token }" is not in contract/markup.json` ),
		...[ ...new Set( source.match( /aria-[a-z]+/g ) ?? [] ) ].filter( ( attribute ) => ! aria.includes( attribute ) ).map( ( attribute ) => `${ name }: ARIA attribute "${ attribute }" is not in contract/markup.json` ),
		...[ ...source.matchAll( /role="([^"]*)"/g ) ].filter( ( [ , role ] ) => ! roles.includes( role ) ).map( ( [ , role ] ) => `${ name }: role "${ role }" is not in contract/markup.json` ),
	];
}

/**
 * Markup the PHP renderers write that the contract does not list, and contract
 * requirements they leave out. The host renderer must write every attribute
 * `host.required` names; the shadow tree renderers (class-declarative-*.php)
 * may write only listed classes, ARIA attributes and roles.
 *
 * @param {Record<string,string>} files    PHP source by file name.
 * @param {object}                contract Parsed contract/markup.json.
 * @return {string[]} One line per finding, empty when the markup follows the contract.
 */
export function lintMarkup( files, contract ) {
	return Object.entries( files ).flatMap( ( [ name, source ] ) => [
		...( SHADOW_FILES.test( name ) ? shadowFindings( name, source, contract ) : [] ),
		...( name === HOST_FILE ? contract.host.required.filter( ( required ) => ! source.includes( required ) ).map( ( required ) => `${ name }: the host element does not write ${ required }, which the contract requires` ) : [] ),
	] );
}
