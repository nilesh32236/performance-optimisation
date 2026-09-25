/**
 * Task-aware scoring with global priors, repository sample gates,
 * champion/challenger behavior, and hysteresis.
 *
 * - Per-area score: smoothed pass rate with a Beta(2,2) global prior,
 *   i.e. (passes + 2) / (total + 4). Equal weight per area (task-aware).
 * - Repository sample gates: every required area needs >= minSamplesPerArea
 *   verified results, otherwise verdict is "insufficient-evidence".
 * - Champion/challenger: promotion requires executable evidence (verified
 *   passes) AND challenger overall exceeding champion overall by more than
 *   the hysteresis margin. Anything else retains the champion (fail-closed).
 *
 * Usage:
 *   node evals/score.mjs [--results <path>] [--registry <path>] [--output <path>]
 *     [--challenger <id>] [--margin <n>] [--min-samples <n>]
 *
 * Exit 0 with a verdict JSON on stdout (and --output file when given).
 * Promotion verdicts never rewrite registry.json or any workflow file.
 */

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
export const CHAMPION = 'opencode/muse-spark-1.3-contributor-free';

export const DEFAULTS = {
	hysteresisMargin: 0.05,
	minSamplesPerArea: 1,
	priorAlpha: 2,
	priorBeta: 2,
};

export const REQUIRED_AREAS = [
	'typescript',
	'javascript',
	'php',
	'wordpress',
	'react',
	'github-actions',
	'refactoring',
	'bugfix',
	'security',
	'tests',
	'documentation',
];

function mean( values ) {
	if ( values.length === 0 ) {
		return 0;
	}
	return values.reduce( ( sum, value ) => sum + value, 0 ) / values.length;
}

/**
 * Group verified results by area.
 * Only results with a boolean verification.passed count as executable evidence.
 */
export function groupByArea( results ) {
	const groups = new Map();
	for ( const result of results ?? [] ) {
		if ( ! result || typeof result.area !== 'string' ) {
			continue;
		}
		if ( ! result.verification || typeof result.verification.passed !== 'boolean' ) {
			continue;
		}
		if ( ! groups.has( result.area ) ) {
			groups.set( result.area, [] );
		}
		groups.get( result.area ).push( result );
	}
	return groups;
}

export function areaScore( samples, { priorAlpha, priorBeta } = DEFAULTS ) {
	const passes = samples.filter( ( sample ) => sample.verification.passed ).length;
	const total = samples.length;
	return {
		passes,
		total,
		score: ( passes + priorAlpha ) / ( total + priorAlpha + priorBeta ),
		rawRate: total === 0 ? 0 : passes / total,
	};
}

/**
 * Score one model's result set. Pure function (test seam).
 */
export function scoreModel( results, options = {} ) {
	const opts = { ...DEFAULTS, ...options };
	const groups = groupByArea( results );
	const perArea = {};
	const gateFailures = [];

	for ( const area of REQUIRED_AREAS ) {
		const samples = groups.get( area ) ?? [];
		if ( samples.length < opts.minSamplesPerArea ) {
			gateFailures.push(
				`${ area }: ${ samples.length } sample(s), need >= ${ opts.minSamplesPerArea }`
			);
		}
		perArea[ area ] = areaScore( samples, opts );
	}

	return {
		perArea,
		overall: mean( REQUIRED_AREAS.map( ( area ) => perArea[ area ].score ) ),
		gatesPassed: gateFailures.length === 0,
		gateFailures,
	};
}

/**
 * Compare champion vs challenger. Pure function (test seam).
 */
export function decide( championResults, challengerResults, options = {} ) {
	const opts = { ...DEFAULTS, ...options };
	const champion = scoreModel( championResults, opts );

	if ( ! challengerResults ) {
		return {
			verdict: champion.gatesPassed ? 'retain-champion' : 'insufficient-evidence',
			reason: 'champion-only evidence; no challenger evaluated',
			champion,
			challenger: null,
			margin: 0,
			hysteresisMargin: opts.hysteresisMargin,
		};
	}

	const challenger = scoreModel( challengerResults, opts );
	if ( ! champion.gatesPassed || ! challenger.gatesPassed ) {
		return {
			verdict: 'insufficient-evidence',
			reason: 'repository sample gates not met',
			champion,
			challenger,
			margin: challenger.overall - champion.overall,
			hysteresisMargin: opts.hysteresisMargin,
		};
	}

	const margin = challenger.overall - champion.overall;
	if ( margin > opts.hysteresisMargin ) {
		return {
			verdict: 'promote-challenger',
			reason: `challenger exceeds champion by ${ margin.toFixed( 4 ) } (> ${ opts.hysteresisMargin })`,
			champion,
			challenger,
			margin,
			hysteresisMargin: opts.hysteresisMargin,
		};
	}
	return {
		verdict: 'retain-champion',
		reason: `margin ${ margin.toFixed( 4 ) } within hysteresis band (±${ opts.hysteresisMargin })`,
		champion,
		challenger,
		margin,
		hysteresisMargin: opts.hysteresisMargin,
	};
}

function parseArgs( argv ) {
	const args = {
		results: resolve( here, 'results.json' ),
		registry: resolve( here, 'registry.json' ),
		output: null,
		challenger: null,
		challengerResults: null,
		margin: DEFAULTS.hysteresisMargin,
		minSamples: DEFAULTS.minSamplesPerArea,
	};
	for ( let i = 0; i < argv.length; i++ ) {
		const flag = argv[ i ];
		const next = argv[ i + 1 ];
		if ( flag === '--results' && next ) {
			args.results = resolve( process.cwd(), next );
			i++;
		} else if ( flag === '--registry' && next ) {
			args.registry = resolve( process.cwd(), next );
			i++;
		} else if ( flag === '--output' && next ) {
			args.output = resolve( process.cwd(), next );
			i++;
		} else if ( flag === '--challenger' && next ) {
			args.challenger = next;
			i++;
		} else if ( flag === '--challenger-results' && next ) {
			args.challengerResults = resolve( process.cwd(), next );
			i++;
		} else if ( flag === '--margin' && next ) {
			args.margin = Number( next );
			i++;
		} else if ( flag === '--min-samples' && next ) {
			args.minSamples = Number( next );
			i++;
		}
	}
	return args;
}

function readJson( path ) {
	return JSON.parse( readFileSync( path, 'utf8' ) );
}

function resultList( payload ) {
	if ( Array.isArray( payload ) ) {
		return payload;
	}
	if ( Array.isArray( payload?.results ) ) {
		return payload.results;
	}
	return null;
}

function main() {
	const args = parseArgs( process.argv.slice( 2 ) );

	let registry = null;
	try {
		registry = readJson( args.registry );
	} catch ( error ) {
		console.error( `score: cannot read registry: ${ error.message }` );
		process.exitCode = 1;
		return;
	}
	const championId = registry.champion || CHAMPION;

	let championResults = null;
	try {
		championResults = resultList( readJson( args.results ) );
	} catch {
		championResults = null;
	}

	let challengerResults = null;
	if ( args.challengerResults ) {
		try {
			challengerResults = resultList( readJson( args.challengerResults ) );
		} catch ( error ) {
			console.error( `score: cannot read challenger results: ${ error.message }` );
			process.exitCode = 1;
			return;
		}
	}

	const options = { hysteresisMargin: args.margin, minSamplesPerArea: args.minSamples };
	let verdict;
	if ( championResults === null ) {
		verdict = {
			verdict: 'insufficient-evidence',
			reason: 'no executable results found; run eval:benchmark first',
			champion: scoreModel( [], options ),
			challenger: null,
			margin: 0,
			hysteresisMargin: args.margin,
		};
	} else {
		verdict = decide( championResults, challengerResults, options );
	}

	const output = {
		generatedAt: new Date().toISOString(),
		championId,
		challengerId: args.challenger,
		...verdict,
		note: 'Promotion verdicts are advisory only; they never rewrite registry.json or workflow routing.',
	};
	if ( args.output ) {
		mkdirSync( dirname( args.output ), { recursive: true } );
		writeFileSync( args.output, `${ JSON.stringify( output, null, 2 ) }\n` );
	}
	// eslint-disable-next-line no-console
	console.log( JSON.stringify( output, null, 2 ) );
}

if ( import.meta.url === `file://${ process.argv[ 1 ] }` ) {
	main();
}
