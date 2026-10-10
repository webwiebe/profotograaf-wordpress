// @ts-check
// The four sample pages that show every gallery layout and the display option
// combinations from docs/gallery-display-matrix.md, on a site connected to the
// real platform. tests/e2e/sample-pages.spec.js reads the same definitions, so
// the pages and the checks cannot drift apart.
//
// Each block carries an anchor (the `id` of the gallery div) that the spec
// looks up. A block names a gallery role. The roles map to the sample
// galleries on the platform, found by title in the plugin's gallery list, or
// given on the command line:
//
//   node tests/e2e/sample-pages.js --compose ../profotograaf-wordpress-cms/docker-compose.yml \
//     --large=<id> --few=<id> --mixed=<id> --png=<id> --empty=<id>
//
// It creates or updates the pages "Sample: Grid", "Sample: Masonry",
// "Sample: Slideshow" and "Sample: Combinations" with wp-cli in the compose
// project's cli service.
const { execFileSync } = require( 'node:child_process' );

/** The platform gallery titles each role is looked up by. */
const ROLE_TITLES = {
	landscape: 'Sample – Landscape',
	portrait: 'Sample – Portrait',
	mixed: 'Sample – Mixed',
	large: 'Sample – Large (40+)',
	few: 'Sample – Few (1 to 3)',
	empty: 'Sample – Empty',
	png: 'Sample – PNG',
};

/** Roles that fall back to another role when the platform has no gallery for them. */
const ROLE_FALLBACK = { landscape: 'large', portrait: 'large', mixed: 'large' };

const DUOTONE = [ '#1a1a2e', '#f5c542' ];

/**
 * @typedef {{anchor:string, role:keyof typeof ROLE_TITLES, attrs?:Record<string,unknown>, expect?:'photos'|'empty'|'png', heading:string}} SampleBlock
 * @typedef {{title:string, slug:string, blocks:SampleBlock[]}} SamplePage
 */

/** @type {SamplePage[]} */
const PAGES = [
	{
		title: 'Sample: Grid',
		slug: 'sample-grid',
		blocks: [
			{ anchor: 'grid-default', role: 'large', heading: 'Grid, every option on its default', attrs: { layout: 'grid' } },
			{ anchor: 'grid-columns', role: 'large', heading: 'Grid, 4 / 3 / 2 columns, 3:2, gap 12', attrs: { layout: 'grid', columns: '4', columnsTablet: '3', columnsMobile: '2', ratio: '3-2', gap: '12', lightbox: 'on' } },
			{ anchor: 'grid-landscape', role: 'landscape', heading: 'Grid of landscape photos, original shape', attrs: { layout: 'grid', ratio: 'original', columns: '3' } },
			{ anchor: 'grid-portrait', role: 'portrait', heading: 'Grid of portrait photos, 2:3', attrs: { layout: 'grid', ratio: '2-3', columns: '4' } },
			{ anchor: 'grid-few', role: 'few', heading: 'Grid of a gallery with a few photos', attrs: { layout: 'grid' } },
		],
	},
	{
		title: 'Sample: Masonry',
		slug: 'sample-masonry',
		blocks: [
			{ anchor: 'masonry-default', role: 'large', heading: 'Masonry, every option on its default', attrs: { layout: 'masonry' } },
			{ anchor: 'masonry-columns', role: 'mixed', heading: 'Masonry, 3 / 2 / 1 columns, gap 16', attrs: { layout: 'masonry', columns: '3', columnsTablet: '2', columnsMobile: '1', gap: '16' } },
			{ anchor: 'masonry-portrait', role: 'portrait', heading: 'Masonry of portrait photos', attrs: { layout: 'masonry' } },
			{ anchor: 'masonry-few', role: 'few', heading: 'Masonry of a gallery with a few photos', attrs: { layout: 'masonry' } },
		],
	},
	{
		title: 'Sample: Slideshow',
		slug: 'sample-slideshow',
		blocks: [
			{ anchor: 'slideshow-large', role: 'large', heading: 'Slideshow of a large gallery', attrs: { layout: 'slideshow', lightbox: 'on' } },
			{ anchor: 'slideshow-portrait', role: 'portrait', heading: 'Slideshow of portrait photos', attrs: { layout: 'slideshow' } },
			{ anchor: 'slideshow-few', role: 'few', heading: 'Slideshow of a gallery with a few photos', attrs: { layout: 'slideshow' } },
			{ anchor: 'slideshow-empty', role: 'empty', expect: 'empty', heading: 'Slideshow of an empty gallery', attrs: { layout: 'slideshow' } },
		],
	},
	{
		title: 'Sample: Combinations',
		slug: 'sample-combinations',
		blocks: [
			{ anchor: 'combo-load-more', role: 'large', heading: 'Grid, 8 per page with Show more', attrs: { layout: 'grid', perPage: '8', loadMore: 'on' } },
			{ anchor: 'combo-first-page', role: 'large', heading: 'Masonry, first 6 only (no Show more)', attrs: { layout: 'masonry', perPage: '6', loadMore: 'off' } },
			{ anchor: 'combo-link-page', role: 'mixed', heading: 'Grid, lightbox off, photos link to their page', attrs: { layout: 'grid', lightbox: 'off', linkTo: 'page', linkNewTab: 'on' } },
			{ anchor: 'combo-captions', role: 'mixed', heading: 'Grid, captions on top, 4:3', attrs: { layout: 'grid', captions: 'overlay', ratio: '4-3' } },
			{ anchor: 'combo-flush', role: 'large', heading: 'Grid, gap 0, square, 12 per page', attrs: { layout: 'grid', gap: '0', ratio: '1-1', columns: '4', perPage: '12', loadMore: 'on' } },
			{ anchor: 'combo-wide', role: 'large', heading: 'Grid, wide alignment, 5 columns', attrs: { layout: 'grid', align: 'wide', columns: '5', perPage: '10', loadMore: 'on' } },
			{ anchor: 'combo-full', role: 'mixed', heading: 'Masonry, full width', attrs: { layout: 'masonry', align: 'full' } },
			{ anchor: 'combo-duotone', role: 'mixed', heading: 'Grid with the block duotone filter', attrs: { layout: 'grid', style: { color: { duotone: DUOTONE } } } },
			{ anchor: 'combo-png', role: 'png', expect: 'png', heading: 'Grid of PNG photos (professionals#2330)', attrs: { layout: 'grid' } },
			{ anchor: 'combo-empty', role: 'empty', expect: 'empty', heading: 'Grid of an empty gallery', attrs: { layout: 'grid' } },
		],
	},
];

/**
 * The block markup of one sample block.
 *
 * @param {SampleBlock}                              block
 * @param {{id:string,title:string,url:string}}      gallery
 * @return {string}
 */
function blockMarkup( block, gallery ) {
	const attrs = { galleryId: gallery.id, galleryTitle: gallery.title, galleryUrl: gallery.url, anchor: block.anchor, ...block.attrs };
	const heading = `<!-- wp:heading {"level":3} -->\n<h3 class="wp-block-heading">${ block.heading }</h3>\n<!-- /wp:heading -->`;
	return `${ heading }\n\n<!-- wp:profotograaf/gallery ${ JSON.stringify( attrs ) } /-->`;
}

/**
 * The post content of one sample page.
 *
 * @param {SamplePage} page
 * @param {Record<string,{id:string,title:string,url:string}>} galleries Gallery per role.
 * @return {string}
 */
function pageContent( page, galleries ) {
	return page.blocks.map( ( block ) => blockMarkup( block, galleries[ block.role ] ) ).join( '\n\n' );
}

/**
 * The gallery for every role: the id from the command line, else the gallery
 * with the role's title in the plugin's gallery list, else the fallback role.
 *
 * @param {Record<string,{title:string,url:string}>} index  The profotograaf_gallery_index option.
 * @param {Record<string,string>}                    given  Role => gallery id from the command line.
 */
function resolveRoles( index, given ) {
	/** @type {Record<string,{id:string,title:string,url:string}>} */
	const out = {};
	const byTitle = Object.fromEntries( Object.entries( index ).map( ( [ id, entry ] ) => [ entry.title, id ] ) );
	for ( const [ role, title ] of Object.entries( ROLE_TITLES ) ) {
		const id = given[ role ] || byTitle[ title ];
		if ( id ) {
			out[ role ] = { id, title: index[ id ]?.title || title, url: index[ id ]?.url || '' };
		}
	}
	for ( const [ role, fallback ] of Object.entries( ROLE_FALLBACK ) ) {
		if ( ! ( role in out ) && fallback in out ) {
			out[ role ] = out[ fallback ];
		}
	}
	const missing = Object.keys( ROLE_TITLES ).filter( ( role ) => ! ( role in out ) );
	if ( missing.length ) {
		throw new Error( `No gallery for: ${ missing.join( ', ' ) }. Pass --<role>=<gallery id>.` );
	}
	return out;
}

/**
 * Creates or updates the sample pages through wp-cli.
 *
 * @param {string[]} argv Command line arguments.
 */
function main( argv ) {
	const options = Object.fromEntries( argv.map( ( arg ) => /^--([a-z]+)=(.*)$/.exec( arg ) ).filter( Boolean ).map( ( m ) => [ m[ 1 ], m[ 2 ] ] ) );
	const compose = options.compose || 'docker-compose.yml';
	/** @param {string[]} args */
	const wp = ( ...args ) =>
		execFileSync( 'docker', [ 'compose', '-f', compose, 'run', '--rm', '-T', 'cli', 'wp', ...args ], { encoding: 'utf8', stdio: [ 'pipe', 'pipe', 'ignore' ] } ).trim();
	const index = JSON.parse( wp( 'option', 'get', 'profotograaf_gallery_index', '--format=json' ) || '{}' );
	const galleries = resolveRoles( index, options );
	for ( const page of PAGES ) {
		const existing = wp( 'post', 'list', '--post_type=page', `--name=${ page.slug }`, '--field=ID' );
		const fields = [ `--post_title=${ page.title }`, `--post_content=${ pageContent( page, galleries ) }`, '--post_status=publish' ];
		const id = existing
			? ( wp( 'post', 'update', existing, ...fields ), existing )
			: wp( 'post', 'create', '--post_type=page', `--post_name=${ page.slug }`, '--porcelain', ...fields );
		process.stdout.write( `${ page.title }: post ${ id }\n` );
	}
}

if ( require.main === module ) {
	main( process.argv.slice( 2 ) );
}

module.exports = { PAGES, ROLE_TITLES, pageContent, resolveRoles };
