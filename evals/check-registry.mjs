/**
 * Registry gate: validates evals/registry.json against the positive-free policy.
 *
 * Rules:
 * - champion must equal the pinned champion literal.
 * - every candidate must carry numeric freeEvidence.price === 0
 *   (unknown price is not free).
 * - no credentials or raw provider payloads may be stored.
 * - champion id must be present in candidates with status "champion".
 *
 * Usage: node evals/check-registry.mjs [--registry <path>]
 */

import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
export const CHAMPION = 'opencode/muse-spark-1.3-contributor-free';

const CREDENTIAL_KEY_PATTERN =
	/(password|passwd|secret|api[_-]?key|apikey|bearer|credential|private[_-]?key|client[_-]?secret)/i;
const RAW_PAYLOAD_KEY_PATTERN = /^(raw[_-]?payload|raw[_-]?response|provider[_-]?payload|raw)$/i;

function fail( errors ) {
	for ( const message of errors ) {
		// eslint-disable-next-line no-console
		console.error( `registry: ${ message }` );
	}
	process.exitCode = 1;
}

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
				hits.push( `${ path }.${ key }` );
			}
			findBadKeys( value[ key ], pattern, `${ path }.${ key }`, hits );
		}
	}
}

function isValidDateTime( value ) {
	return typeof value === 'string' && ! Number.isNaN( Date.parse( value ) );
}

export function validateRegistry( registry ) {
	const errors = [];

	if ( registry === null || typeof registry !== 'object' ) {
		return [ 'registry root must be an object' ];
	}

	if ( registry.champion !== CHAMPION ) {
		errors.push(
			`champion must remain "${ CHAMPION }" (got ${ JSON.stringify(
				registry.champion
			) })`
		);
	}

	if ( ! isValidDateTime( registry.updatedAt ) ) {
		errors.push( 'updatedAt must be a valid date-time string' );
	}

	if ( ! Array.isArray( registry.candidates ) || registry.candidates.length < 1 ) {
		errors.push( 'candidates must be a non-empty array' );
		return errors;
	}

	let championSeen = false;
	registry.candidates.forEach( ( candidate, index ) => {
		const where = `candidates[${ index }]`;
		if ( candidate.id === CHAMPION && candidate.status === 'champion' ) {
			championSeen = true;
		}
		if ( typeof candidate.id !== 'string' || candidate.id.length === 0 ) {
			errors.push( `${ where }.id must be a non-empty string` );
		}
		if ( typeof candidate.provider !== 'string' || candidate.provider.length === 0 ) {
			errors.push( `${ where }.provider must be a non-empty string` );
		}
		if ( ! [ 'champion', 'challenger', 'excluded' ].includes( candidate.status ) ) {
			errors.push(
				`${ where }.status must be champion|challenger|excluded`
			);
		}
		const evidence = candidate.freeEvidence;
		if ( evidence === null || typeof evidence !== 'object' ) {
			errors.push( `${ where }.freeEvidence must be an object` );
		} else {
			if ( typeof evidence.source !== 'string' || evidence.source.length === 0 ) {
				errors.push( `${ where }.freeEvidence.source must be non-empty` );
			}
			// Positive free gate: numeric 0 only. Unknown/null/string/paid rejected.
			if ( typeof evidence.price !== 'number' || evidence.price !== 0 ) {
				errors.push(
					`${ where }.freeEvidence.price must be numeric 0 (unknown price is not free)`
				);
			}
			if ( ! isValidDateTime( evidence.checkedAt ) ) {
				errors.push(
					`${ where }.freeEvidence.checkedAt must be a valid date-time string`
				);
			}
		}
		const health = candidate.health;
		if ( health === null || typeof health !== 'object' ) {
			errors.push( `${ where }.health must be an object` );
		} else if (
			! [ 'unknown', 'healthy', 'degraded', 'unreachable' ].includes( health.status )
		) {
			errors.push(
				`${ where }.health.status must be unknown|healthy|degraded|unreachable`
			);
		}
		if ( ! Array.isArray( candidate.capabilities ) || candidate.capabilities.length < 1 ) {
			errors.push( `${ where }.capabilities must be a non-empty array` );
		}
	} );

	if ( ! championSeen ) {
		errors.push( 'champion id must appear in candidates with status "champion"' );
	}

	const credentialHits = [];
	findBadKeys( registry, CREDENTIAL_KEY_PATTERN, '$', credentialHits );
	if ( credentialHits.length > 0 ) {
		errors.push(
			`credentials must not be stored (hits: ${ credentialHits.join( ', ' ) })`
		);
	}

	const payloadHits = [];
	findBadKeys( registry, RAW_PAYLOAD_KEY_PATTERN, '$', payloadHits );
	if ( payloadHits.length > 0 ) {
		errors.push(
			`raw provider payloads must not be stored (hits: ${ payloadHits.join( ', ' ) })`
		);
	}

	return errors;
}

function main() {
	const args = process.argv.slice( 2 );
	const flagIndex = args.indexOf( '--registry' );
	const registryPath =
		flagIndex >= 0 && args[ flagIndex + 1 ]
			? resolve( process.cwd(), args[ flagIndex + 1 ] )
			: resolve( here, 'registry.json' );

	let registry;
	try {
		registry = JSON.parse( readFileSync( registryPath, 'utf8' ) );
	} catch ( error ) {
		fail( [ `cannot read/parse ${ registryPath }: ${ error.message }` ] );
		return;
	}

	const errors = validateRegistry( registry );
	if ( errors.length > 0 ) {
		fail( errors );
		return;
	}
	// eslint-disable-next-line no-console
	console.log(
		`registry OK: champion ${ CHAMPION }, ${ registry.candidates.length } candidate(s)`
	);
}

if ( import.meta.url === `file://${ process.argv[ 1 ] }` ) {
	main();
}
