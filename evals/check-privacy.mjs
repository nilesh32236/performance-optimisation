/**
 * Privacy check: evals must not store credentials or raw provider payloads,
 * and runtime artifacts must stay git-ignored.
 *
 * - Scans registry.json, discovery.json (when present), results.json and
 *   verdict.json (when present) for credential-like keys.
 * - Scans the same files for raw-payload keys (rawPayload, raw_response, ...).
 * - Asserts evals/.gitignore covers results.json, verdict.json, discovery.json,
 *   and .cache/.
 *
 * Usage: node evals/check-privacy.mjs
 */

import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );

const CREDENTIAL_KEY_PATTERN =
	/(password|passwd|secret|api[_-]?key|apikey|bearer|credential|private[_-]?key|client[_-]?secret|token)/i;
const RAW_PAYLOAD_KEY_PATTERN = /^(raw[_-]?payload|raw[_-]?response|provider[_-]?payload|raw)$/i;
const IGNORED_ARTIFACTS = [ 'results.json', 'verdict.json', 'discovery.json', '.cache/' ];

function findBadKeys( value, pattern, path, hits ) {
	if ( Array.isArray( value ) ) {
		value.forEach( ( item, index ) =>
			findBadKeys( item, pattern, `${ path }[${ index }]`, hits )
		);
		return;
	}
	if ( value !== null && typeof value === 'object' ) {
		for ( const key of Object.keys( value ) ) {
			if ( pattern.test( key ) ) {
				// Allowlist: X-WP-Nonce header names in SECURITY-adjacent fixture text are
				// documentation strings, not stored secrets. Fixture files are excluded
				// from this scan (only registry/discovery/results/verdict are scanned).
				hits.push( `${ path }.${ key }` );
			}
			findBadKeys( value[ key ], pattern, `${ path }.${ key }`, hits );
		}
	}
}

export function scanPayload( payload, label, errors ) {
	const credentialHits = [];
	findBadKeys( payload, CREDENTIAL_KEY_PATTERN, '$', credentialHits );
	// 'token' appears in the discovery note? No — filter exact benign keys.
	const benign = new Set();
	const real = credentialHits.filter( ( hit ) => ! benign.has( hit ) );
	if ( real.length > 0 ) {
		errors.push( `${ label }: credential-like keys stored (${ real.join( ', ' ) })` );
	}
	const payloadHits = [];
	findBadKeys( payload, RAW_PAYLOAD_KEY_PATTERN, '$', payloadHits );
	if ( payloadHits.length > 0 ) {
		errors.push( `${ label }: raw provider payload keys stored (${ payloadHits.join( ', ' ) })` );
	}
}

function main() {
	const errors = [];
	const scanned = [];

	for ( const file of [ 'registry.json', 'discovery.json', 'results.json', 'verdict.json' ] ) {
		const full = resolve( here, file );
		if ( ! existsSync( full ) ) {
			continue;
		}
		try {
			scanPayload( JSON.parse( readFileSync( full, 'utf8' ) ), file, errors );
			scanned.push( file );
		} catch ( error ) {
			errors.push( `${ file }: unreadable (${ error.message })` );
		}
	}

	const gitignorePath = resolve( here, '.gitignore' );
	if ( ! existsSync( gitignorePath ) ) {
		errors.push( 'evals/.gitignore missing' );
	} else {
		const ignored = readFileSync( gitignorePath, 'utf8' );
		for ( const artifact of IGNORED_ARTIFACTS ) {
			if ( ! ignored.includes( artifact ) ) {
				errors.push( `evals/.gitignore must cover ${ artifact }` );
			}
		}
	}

	if ( errors.length > 0 ) {
		for ( const message of errors ) {
			console.error( `privacy: ${ message }` );
		}
		process.exitCode = 1;
		return;
	}
	// eslint-disable-next-line no-console
	console.log( `privacy OK: scanned ${ scanned.join( ', ' ) || 'registry only' }, artifacts git-ignored` );
}

main();
