/**
 * In-process static file server for the jsdom harnesses.
 *
 * The harnesses previously depended on a backgrounded `python3 -m http.server`,
 * which kept getting reaped between runs and turned every check into a cascade
 * of ECONNREFUSED failures. Serving from inside the test process removes that
 * dependency entirely.
 */
const http = require( 'http' );
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );

const TYPES = {
	'.html': 'text/html; charset=utf-8',
	'.js': 'text/javascript; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.json': 'application/json; charset=utf-8',
	'.svg': 'image/svg+xml',
	'.md': 'text/markdown; charset=utf-8',
};

/** Start a server on an ephemeral port. Returns the base URL (no trailing slash). */
function start() {
	return new Promise( ( resolve, reject ) => {
		const server = http.createServer( ( req, res ) => {
			const urlPath = decodeURIComponent( req.url.split( '?' )[ 0 ] );
			// Resolve inside ROOT only; refuse anything that escapes it.
			const filePath = path.join( ROOT, path.normalize( urlPath ) );
			if ( ! filePath.startsWith( ROOT ) ) {
				res.writeHead( 403 ).end( 'forbidden' );
				return;
			}
			fs.readFile( filePath, ( err, buf ) => {
				if ( err ) {
					res.writeHead( 404 ).end( 'not found' );
					return;
				}
				res.writeHead( 200, {
					'Content-Type': TYPES[ path.extname( filePath ) ] || 'application/octet-stream',
				} );
				res.end( buf );
			} );
		} );

		server.on( 'error', reject );
		server.listen( 0, '127.0.0.1', () => {
			const { port } = server.address();
			resolve( { base: `http://127.0.0.1:${ port }`, close: () => server.close() } );
		} );
	} );
}

module.exports = { start, ROOT };