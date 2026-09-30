// A stand-in for the Profotograaf platform, for the E2E tests only.
//
// It speaks the device pairing endpoints and the two scoped calls the plugin
// uses, with the shapes documented in the platform repository (docs/features.md
// and docs/embed-api.md). The test approves a pairing with POST /__approve.
import { createServer } from 'node:http';

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
			'/share/embed/embed.0123456789ab.js"></script>',
		width: 720,
		height: 540,
		cache_age: 300,
	} );
}

function embedScript( { req, res } ) {
	// The current script. The ETag is its content hash, the version in the versioned URL.
	res.writeHead( 200, { 'content-type': 'text/javascript', etag: '"0123456789ab"' } );
	return res.end( req.method === 'HEAD' ? undefined : '/* embed */' );
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
	'GET /oembed': oembed,
	'POST /api/v1/leads': authed( ( { res, body } ) => {
		state.leads.push( body );
		return json( res, 201, { id: `lead-${ state.leads.length }`, duplicate: false } );
	} ),
};

const EMBEDDABLE = /^\/api\/v1\/embed\/galleries\/[^/]+\/embeddable$/;

function findRoute( method, pathname ) {
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
