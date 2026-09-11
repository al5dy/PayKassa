const fs = require( 'node:fs' );
const http = require( 'node:http' );
const https = require( 'node:https' );

const [ backendPortValue, listenPortValue, certificatePath, keyPath ] = process.argv.slice( 2 );
const backendPort = Number.parseInt( backendPortValue || '', 10 );
const listenPort = Number.parseInt( listenPortValue || '', 10 );

if ( ! Number.isInteger( backendPort ) || ! Number.isInteger( listenPort ) || ! certificatePath || ! keyPath ) {
	throw new Error( 'Usage: node https-reverse-proxy.js <backend-port> <listen-port> <certificate> <key>' );
}

const server = https.createServer(
	{
		cert: fs.readFileSync( certificatePath ),
		key: fs.readFileSync( keyPath ),
	},
	( request, response ) => {
		const forwarded = http.request(
			{
				hostname: '127.0.0.1',
				port: backendPort,
				method: request.method,
				path: request.url,
				headers: {
					...request.headers,
					'x-forwarded-proto': 'https',
					'x-forwarded-host': request.headers.host || '',
				},
			},
			upstream => {
				response.writeHead( upstream.statusCode || 502, upstream.headers );
				upstream.pipe( response );
			}
		);
		forwarded.on( 'error', () => {
			if ( ! response.headersSent ) {
				response.writeHead( 502, { 'content-type': 'text/plain; charset=utf-8' } );
			}
			response.end( 'Proxy error' );
		} );
		request.pipe( forwarded );
	}
);

server.listen( listenPort, '0.0.0.0' );
