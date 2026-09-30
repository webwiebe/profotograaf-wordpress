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

const server = createServer( async ( req, res ) => {
	const url = new URL( req.url, 'http://mock' );
	const body = await readBody( req );
	const bearer = ( req.headers.authorization || '' ).replace( /^Bearer /, '' );

	if ( req.method === 'POST' && url.pathname === '/__approve' ) {
		state.approved = true;
		return json( res, 200, { ok: true } );
	}
	if ( req.method === 'POST' && url.pathname === '/__reset' ) {
		Object.assign( state, { approved: false, tokens: 0, refreshes: 0, leads: [] } );
		return json( res, 200, { ok: true } );
	}
	if ( req.method === 'GET' && url.pathname === '/__state' ) {
		return json( res, 200, state );
	}

	if ( req.method === 'POST' && url.pathname === '/api/v1/auth/devices/initiate' ) {
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
	if ( req.method === 'POST' && url.pathname === '/api/v1/auth/devices/token' ) {
		if ( body.device_code !== 'e2e-device-code' ) {
			return json( res, 404, { error: 'unknown device code', code: 'device.unknown_code' } );
		}
		if ( ! state.approved ) {
			return json( res, 200, { status: 'pending' } );
		}
		state.approved = false;
		return json( res, 200, { status: 'approved', ...tokenPair() } );
	}
	if ( req.method === 'POST' && url.pathname === '/api/v1/auth/devices/refresh' ) {
		state.refreshes += 1;
		return json( res, 200, { status: 'approved', ...tokenPair() } );
	}
	if ( req.method === 'POST' && url.pathname === '/api/v1/auth/devices/signout' ) {
		// The real platform refuses the wordpress client here (scope middleware).
		return json( res, 403, { error: 'this app is not allowed to use this endpoint', code: 'forbidden' } );
	}
	if ( req.method === 'GET' && url.pathname === '/api/v1/embed/galleries' ) {
		if ( ! bearer ) {
			return json( res, 401, { error: 'device token required' } );
		}
		return json( res, 200, [
			{
				id: 'g-e2e',
				slug: 'spring-wedding',
				title: 'Spring wedding',
				url: 'http://localhost:8090/share/g/spring-wedding',
				embeddable: true,
				available: true,
				photo_count: 3,
				updated_at: '2026-09-01T10:00:00Z',
			},
		] );
	}
	if ( req.method === 'POST' && url.pathname === '/api/v1/leads' ) {
		if ( ! bearer ) {
			return json( res, 401, { error: 'device token required' } );
		}
		state.leads.push( body );
		return json( res, 201, { id: `lead-${ state.leads.length }`, duplicate: false } );
	}
	return json( res, 404, { error: 'not found' } );
} );

server.listen( PORT, '0.0.0.0', () => console.log( `mock platform on ${ PORT }` ) );
