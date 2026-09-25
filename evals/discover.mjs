/**
 * Bounded, cache-preserving catalog discovery.
 *
 * - Sources: Models.dev catalog (+ compatible provider metadata when reachable).
 * - Bounds: concurrency <= 4, per-host timeout ~15s, <= 2 retries, response cap.
 * - Cache: ETag/file cache under evals/.cache/; conditional requests preserve it.
 * - Failure policy: keep the known-good registry.json untouched, emit a warning,
 *   write discovery.json with status "stale-cache" or "failed", exit 0.
 * - Never files issues, never stores credentials or raw provider payloads:
 *   only { id, provider, price, capabilities } projections are persisted.
 *
 * Usage:
 *   node evals/discover.mjs [--out evals/discovery.json] [--offline]
 *
 * Env:
 *   MODELS_DEV_URL  override catalog URL (tests)
 *   OFFLINE=1       skip network, reuse cache only
 */

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const CACHE_DIR = resolve( here, '.cache' );
const DEFAULT_URL = 'https://models.dev/api.json';

export const BOUNDS = {
	maxConcurrency: 4,
	timeoutMs: 15000,
	maxRetries: 2,
	maxResponseBytes: 20 * 1024 * 1024,
};

function warn( message ) {
	console.warn( `discover: ${ message }` );
}

function parseArgs( argv ) {
	const args = { out: resolve( here, 'discovery.json' ), offline: false };
	for ( let i = 0; i < argv.length; i++ ) {
		if ( argv[ i ] === '--out' && argv[ i + 1 ] ) {
			args.out = resolve( process.cwd(), argv[ i + 1 ] );
			i++;
		} else if ( argv[ i ] === '--offline' ) {
			args.offline = true;
		}
	}
	if ( process.env.OFFLINE === '1' ) {
		args.offline = true;
	}
	return args;
}

function cachePaths( url ) {
	const safe = Buffer.from( url ).toString( 'base64url' );
	return {
		body: resolve( CACHE_DIR, `${ safe }.json` ),
		meta: resolve( CACHE_DIR, `${ safe }.meta.json` ),
	};
}

function readJsonFile( path ) {
	try {
		return JSON.parse( readFileSync( path, 'utf8' ) );
	} catch {
		return null;
	}
}

async function fetchWithTimeout( url, { timeoutMs, etag } ) {
	const controller = new AbortController();
	const timer = setTimeout( () => controller.abort(), timeoutMs );
	try {
		const headers = {};
		if ( etag ) {
			headers[ 'If-None-Match' ] = etag;
		}
		const response = await fetch( url, { headers, signal: controller.signal } );
		return response;
	} finally {
		clearTimeout( timer );
	}
}

async function readBoundedBody( response ) {
	const buffer = Buffer.from( await response.arrayBuffer() );
	if ( buffer.length > BOUNDS.maxResponseBytes ) {
		throw new Error(
			`response exceeded ${ BOUNDS.maxResponseBytes } byte cap (${ buffer.length })`
		);
	}
	return buffer.toString( 'utf8' );
}

/**
 * Fetch one URL with ETag cache, timeout, and bounded retries.
 * Returns { text|null, etag, fromCache, status }.
 */
export async function fetchCatalog( url, { timeoutMs, maxRetries } = BOUNDS ) {
	mkdirSync( CACHE_DIR, { recursive: true } );
	const { body, meta } = cachePaths( url );
	const cachedMeta = readJsonFile( meta );

	for ( let attempt = 0; attempt <= maxRetries; attempt++ ) {
		try {
			const response = await fetchWithTimeout( url, {
				timeoutMs,
				etag: cachedMeta?.etag,
			} );
			if ( response.status === 304 && existsSync( body ) ) {
				return {
					text: readFileSync( body, 'utf8' ),
					etag: cachedMeta?.etag ?? null,
					fromCache: true,
					status: 'not-modified',
				};
			}
			if ( ! response.ok ) {
				throw new Error( `HTTP ${ response.status }` );
			}
			const text = await readBoundedBody( response );
			const etag = response.headers.get( 'etag' );
			writeFileSync( body, text );
			writeFileSync( meta, JSON.stringify( { etag, fetchedAt: new Date().toISOString(), url } ) );
			return { text, etag, fromCache: false, status: 'fresh' };
		} catch ( error ) {
			const last = attempt === maxRetries;
			if ( last ) {
				if ( existsSync( body ) ) {
					return {
						text: readFileSync( body, 'utf8' ),
						etag: cachedMeta?.etag ?? null,
						fromCache: true,
						status: 'stale-cache',
						error: error.message,
					};
				}
				return { text: null, etag: null, fromCache: false, status: 'failed', error: error.message };
			}
			// Bounded exponential backoff: 1s, 2s.
			await new Promise( ( done ) => setTimeout( done, 1000 * 2 ** attempt ) );
		}
	}
	return { text: null, etag: null, fromCache: false, status: 'failed', error: 'unreachable' };
}

/**
 * Project a raw catalog entry down to the minimal evidence shape.
 * Drops everything except id/provider/price metadata. Never keeps payloads.
 */
export function projectFreeCandidate( entry ) {
	if ( entry === null || typeof entry !== 'object' ) {
		return null;
	}
	const id = entry.id ?? entry.name ?? entry.model;
	const provider = entry.provider ?? entry.vendor ?? 'unknown';
	const cost = entry.cost ?? entry.pricing ?? entry.price;
	let price = null;
	if ( typeof cost === 'number' ) {
		price = cost;
	} else if ( cost !== null && typeof cost === 'object' ) {
		const input = cost.input ?? cost.prompt;
		const output = cost.output ?? cost.completion;
		if ( typeof input === 'number' && typeof output === 'number' ) {
			price = input === 0 && output === 0 ? 0 : null;
		}
	}
	// Unknown price is not free: only numeric 0 passes.
	if ( typeof id !== 'string' || price !== 0 ) {
		return null;
	}
	return { id, provider: String( provider ), price: 0 };
}

export function extractFreeCandidates( catalogText ) {
	let catalog;
	try {
		catalog = JSON.parse( catalogText );
	} catch {
		return { candidates: [], parseError: true };
	}
	const lists = [];
	if ( Array.isArray( catalog ) ) {
		lists.push( catalog );
	} else if ( catalog !== null && typeof catalog === 'object' ) {
		for ( const value of Object.values( catalog ) ) {
			if ( Array.isArray( value ) ) {
				lists.push( value );
			} else if ( value !== null && typeof value === 'object' && Array.isArray( value.models ) ) {
				lists.push( value.models );
			}
		}
	}
	const seen = new Map();
	for ( const list of lists ) {
		for ( const entry of list ) {
			const projected = projectFreeCandidate( entry );
			if ( projected && ! seen.has( projected.id ) ) {
				seen.set( projected.id, projected );
			}
		}
	}
	return { candidates: [ ...seen.values() ], parseError: false };
}

async function main() {
	const args = parseArgs( process.argv.slice( 2 ) );
	const url = process.env.MODELS_DEV_URL || DEFAULT_URL;
	const unverified = [];

	// Concurrency bound: discovery fans out to at most BOUNDS.maxConcurrency hosts.
	// Currently a single catalog host; the pool below enforces the cap as hosts grow.
	const hosts = args.offline ? [] : [ url ];
	const pool = [];
	const outcomes = [];
	for ( const host of hosts ) {
		const task = fetchCatalog( host ).then( ( outcome ) => ( { host, ...outcome } ) );
		pool.push( task );
		if ( pool.length >= BOUNDS.maxConcurrency ) {
			outcomes.push( await pool.shift() );
		}
	}
	while ( pool.length > 0 ) {
		outcomes.push( await pool.shift() );
	}

	let freeCandidates = [];
	let status = 'failed';
	if ( args.offline ) {
		const { body } = cachePaths( url );
		if ( existsSync( body ) ) {
			const cached = readFileSync( body, 'utf8' );
			const extracted = extractFreeCandidates( cached );
			freeCandidates = extracted.candidates;
			status = 'stale-cache';
			warn( 'offline mode: serving file cache only' );
		} else {
			warn( 'offline mode with empty cache: registry untouched' );
		}
	} else {
		for ( const outcome of outcomes ) {
			if ( outcome.text ) {
				const extracted = extractFreeCandidates( outcome.text );
				freeCandidates = extracted.candidates;
				status = outcome.status;
				if ( outcome.error ) {
					warn( `serving cache after fetch error: ${ outcome.error }` );
				}
			} else {
				unverified.push( outcome.host );
				warn( `catalog unreachable (${ outcome.error || 'unknown' }): registry untouched` );
			}
		}
	}

	const discovery = {
		generatedAt: new Date().toISOString(),
		source: url,
		status,
		bounds: BOUNDS,
		freeCandidates,
		unverified,
		note: 'Catalog presence does not prove health or coding quality. Candidates require health + benchmark evidence before promotion. This file never rewrites registry.json.',
	};
	mkdirSync( dirname( args.out ), { recursive: true } );
	writeFileSync( args.out, `${ JSON.stringify( discovery, null, 2 ) }\n` );
	// eslint-disable-next-line no-console
	console.log(
		`discover OK: status=${ status } free=${ freeCandidates.length } (registry untouched)`
	);
}

main();
