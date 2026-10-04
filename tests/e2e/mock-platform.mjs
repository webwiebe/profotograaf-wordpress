// A stand-in for the Profotograaf platform, for the E2E tests only.
//
// It speaks the device pairing endpoints and the two scoped calls the plugin
// uses, with the shapes documented in the platform repository (docs/features.md
// and docs/embed-api.md). The test approves a pairing with POST /__approve.
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = 8090;
const state = { approved: false, tokens: 0, refreshes: 0, leads: [] };

function json( res, status, body, headers = {} ) {
	res.writeHead( status, { 'content-type': 'application/json', ...headers } );
	res.end( body === undefined ? '' : JSON.stringify( body ) );
}

function readBody( req ) {
	return new Promise( ( resolve ) => {
		let raw = '';
		req.on( 'data', ( chunk ) => ( raw += chunk ) );
		req.on( 'end', () => {
			try {
				resolve( raw ? JSON.parse( raw ) : {} );
			} catch {
				resolve( {} );
			}
		} );
	} );
}

function tokenPair() {
	state.tokens += 1;
	return {
		access_token: `access-${ state.tokens }`,
		refresh_token: `refresh-${ state.tokens }`,
		token_type: 'Bearer',
		expires_in: 900,
		device_id: 'device-e2e',
	};
}

const unauthorized = ( res ) =>
	json( res, 401, { error: 'device token required' } );

const GALLERY = {
	id: 'g-e2e',
	slug: 'spring-wedding',
	title: 'Spring wedding',
	url: 'http://localhost:8090/share/g/spring-wedding',
	embeddable: true,
	available: true,
	photo_count: 3,
	cover_url: 'http://localhost:8090/cover.jpg',
	updated_at: '2026-09-01T10:00:00Z',
};

function oembed( { req, res, url } ) {
	const match = /\/share\/g\/([^/?#]+)/.exec( url.searchParams.get( 'url' ) || '' );
	if ( ! match || match[ 1 ] !== 'spring-wedding' ) {
		return json( res, 404, { error: 'not found' } );
	}
	const origin = `http://${ req.headers.host }`;
	return json( res, 200, {
		version: '1.0',
		type: 'rich',
		provider_name: 'Profotograaf',
		provider_url: origin,
		title: 'Spring wedding',
		html:
			'<div data-profotograaf-gallery="g-e2e"><a href="' +
			origin +
			'/share/g/spring-wedding">Spring wedding</a></div><script async src="' +
			origin +
			'/share/embed/embed.' +
			EMBED_VERSION +
			'.js"></script>',
		width: 720,
		height: 540,
		cache_age: 300,
	} );
}

// The real embed.js, vendored from the platform (see fixtures/README.md). The
// ETag is the first 12 hex characters of its content hash, the same version the
// platform puts in the versioned URL.
const EMBED_JS = readFileSync( join( dirname( fileURLToPath( import.meta.url ) ), 'fixtures', 'embed.js' ) );
const EMBED_VERSION = createHash( 'sha256' ).update( EMBED_JS ).digest( 'hex' ).slice( 0, 12 );

function embedScript( { req, res } ) {
	res.writeHead( 200, {
		'content-type': 'text/javascript',
		'access-control-allow-origin': '*',
		etag: `"${ EMBED_VERSION }"`,
	} );
	return res.end( req.method === 'HEAD' ? undefined : EMBED_JS );
}

// The public gallery payload (docs/embed-api.md in the platform repository):
// 19 photos in a fixed mix of portrait and landscape ratios, drawn as SVG by
// the mock so the images are deterministic and instant.
const SHAPES = [
	[ 3000, 2000 ], [ 2000, 3000 ], [ 4000, 3000 ], [ 3000, 4000 ], [ 3200, 1800 ], [ 2000, 2500 ], [ 3000, 2000 ],
];
const PUBLIC_PHOTOS = Array.from( { length: 19 }, ( _, index ) => {
	const [ width, height ] = SHAPES[ index % SHAPES.length ];
	return { id: `p-${ index + 1 }`, width, height };
} );

function variantSize( photo, variant ) {
	if ( variant === 'thumb' ) {
		return [ 400, 400 ];
	}
	const scale = 1600 / Math.max( photo.width, photo.height );
	return [ Math.round( photo.width * scale ), Math.round( photo.height * scale ) ];
}

function publicGallery( origin ) {
	return {
		id: 'g-e2e',
		slug: 'spring-wedding',
		title: 'Spring wedding',
		description: '',
		layout: 'grid',
		url: `${ origin }/share/g/spring-wedding`,
		site_url: `${ origin }/studio-e2e`,
		photo_count: PUBLIC_PHOTOS.length,
		badge: { show: false, prominent: false, url: `${ origin }/made-with?ref=embed-badge` },
		photos: PUBLIC_PHOTOS.map( ( photo, index ) => ( {
			...photo,
			alt: '',
			title: `Photo ${ index + 1 }`,
			caption: '',
			url: `${ origin }/share/g/spring-wedding/photo/${ photo.id }`,
			images: [ 'thumb', 'web' ].map( ( variant ) => {
				const [ w, h ] = variantSize( photo, variant );
				return { variant, url: `${ origin }/img/${ photo.id }/${ variant }.svg`, width: w, height: h };
			} ),
		} ) ),
		version: 'e2e0000000000001',
	};
}

const IMAGE = /^\/img\/(p-\d+)\/(thumb|web)\.(?:svg|jpg)$/;

function image( { res, url } ) {
	const [ , id, variant ] = IMAGE.exec( url.pathname );
	const photo = PUBLIC_PHOTOS.find( ( candidate ) => candidate.id === id );
	if ( ! photo ) {
		return json( res, 404, { error: 'not found' } );
	}
	const [ width, height ] = variantSize( photo, variant );
	const hue = ( Number( id.slice( 2 ) ) * 47 ) % 360;
	res.writeHead( 200, { 'content-type': 'image/svg+xml', 'cache-control': 'no-store' } );
	return res.end(
		`<svg xmlns="http://www.w3.org/2000/svg" width="${ width }" height="${ height }" viewBox="0 0 ${ width } ${ height }">` +
			`<rect width="100%" height="100%" fill="hsl(${ hue } 45% 60%)"/>` +
			`<text x="50%" y="50%" font-size="${ Math.round( Math.min( width, height ) / 5 ) }" text-anchor="middle" dominant-baseline="middle" fill="#fff">${ id }</text></svg>`
	);
}

function deviceToken( { res, body } ) {
	if ( body.device_code !== 'e2e-device-code' ) {
		return json( res, 404, { error: 'unknown device code', code: 'device.unknown_code' } );
	}
	if ( ! state.approved ) {
		return json( res, 200, { status: 'pending' } );
	}
	state.approved = false;
	return json( res, 200, { status: 'approved', ...tokenPair() } );
}

function deviceInitiate( { res, body } ) {
	if ( body.client_id !== 'wordpress' ) {
		return json( res, 400, { error: 'unknown client_id', code: 'device.unknown_client' } );
	}
	return json( res, 201, {
		device_code: 'e2e-device-code',
		user_code: 'WXYZ-2346',
		verification_uri: 'http://localhost:8090/app/devices/approve',
		verification_uri_complete: 'http://localhost:8090/app/devices/approve?code=WXYZ-2346',
		expires_in: 600,
		interval: 1,
	} );
}

// Routes are matched on "METHOD /path". Handlers that need a device token
// are wrapped in `authed`.
const authed = ( handler ) => ( ctx ) =>
	ctx.bearer ? handler( ctx ) : unauthorized( ctx.res );

const routes = {
	'POST /__approve': ( { res } ) => {
		state.approved = true;
		return json( res, 200, { ok: true } );
	},
	'POST /__reset': ( { res } ) => {
		Object.assign( state, { approved: false, tokens: 0, refreshes: 0, leads: [] } );
		return json( res, 200, { ok: true } );
	},
	'GET /__state': ( { res } ) => json( res, 200, state ),
	'POST /api/v1/auth/devices/initiate': deviceInitiate,
	'POST /api/v1/auth/devices/token': deviceToken,
	'POST /api/v1/auth/devices/refresh': ( { res } ) => {
		state.refreshes += 1;
		return json( res, 200, { status: 'approved', ...tokenPair() } );
	},
	// The real platform refuses the wordpress client here (scope middleware).
	'POST /api/v1/auth/devices/signout': ( { res } ) =>
		json( res, 403, { error: 'this app is not allowed to use this endpoint', code: 'forbidden' } ),
	'GET /api/v1/embed/galleries': authed( ( { res } ) => json( res, 200, [ GALLERY ] ) ),
	'GET /share/embed/embed.js': embedScript,
	'HEAD /share/embed/embed.js': embedScript,
	'GET /api/v1/embed/galleries/g-e2e': ( { req, res } ) =>
		json( res, 200, publicGallery( `http://${ req.headers.host }` ), { 'access-control-allow-origin': '*' } ),
	'POST /share/embed/view': ( { res } ) => {
		res.writeHead( 204, { 'access-control-allow-origin': '*' } );
		return res.end();
	},
	'GET /oembed': oembed,
	'POST /api/v1/leads': authed( ( { res, body } ) => {
		state.leads.push( body );
		return json( res, 201, { id: `lead-${ state.leads.length }`, duplicate: false } );
	} ),
};

const EMBEDDABLE = /^\/api\/v1\/embed\/galleries\/[^/]+\/embeddable$/;
const PHOTOS = /^\/api\/v1\/embed\/galleries\/g-e2e\/photos$/;

// The photo shape of the public gallery payload (docs/embed-api.md). The token
// list route is the one wiebe-xyz/professionals#1868 asks for.
const PHOTO_LIST = [ 'p-1', 'p-2', 'p-3' ].map( ( id, index ) => ( {
	id,
	width: 3000,
	height: 2000,
	alt: '',
	title: [ 'Bride', 'Groom', 'Rings' ][ index ],
	caption: '',
	url: `http://localhost:8090/share/g/spring-wedding/photo/${ id }`,
	images: [
		{ variant: 'thumb', url: `http://localhost:8090/img/${ id }/thumb.jpg`, width: 400, height: 400 },
		{ variant: 'web', url: `http://localhost:8090/img/${ id }/web.jpg`, width: 1600, height: 1067 },
	],
} ) );

const EMBED_VERSIONED = /^\/share\/embed\/embed\.[a-f0-9]{12}\.js$/;

function findRoute( method, pathname ) {
	if ( ( method === 'GET' || method === 'HEAD' ) && EMBED_VERSIONED.test( pathname ) ) {
		return embedScript;
	}
	if ( method === 'GET' && IMAGE.test( pathname ) ) {
		return image;
	}
	if ( method === 'GET' && PHOTOS.test( pathname ) ) {
		return authed( ( { res } ) => json( res, 200, { photos: PHOTO_LIST } ) );
	}
	if ( method === 'PUT' && EMBEDDABLE.test( pathname ) ) {
		return authed( ( { res } ) =>
			json( res, 200, { id: pathname.split( '/' )[ 5 ], embeddable: true, available: true } )
		);
	}
	return routes[ `${ method } ${ pathname }` ];
}

const server = createServer( async ( req, res ) => {
	const url = new URL( req.url, 'http://mock' );
	const body = await readBody( req );
	const bearer = ( req.headers.authorization || '' ).replace( /^Bearer /, '' );
	const handler = findRoute( req.method, url.pathname );
	if ( ! handler ) {
		return json( res, 404, { error: 'not found' } );
	}
	return handler( { req, res, url, body, bearer } );
} );

server.listen( PORT, '0.0.0.0', () => console.log( `mock platform on ${ PORT }` ) );
