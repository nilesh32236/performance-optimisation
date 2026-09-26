/**
 * Regression tests for the shared polling backoff (src/lib/polling.js).
 *
 * The expectation table below is a hard-coded pin of the values the previous
 * inlined implementation produced at useImageJobPolling.js:16-25 and
 * PageSpeedPanel.js:45-73, captured by running the original expression:
 *
 *   Math.min( 5000 * Math.max( 1, Math.ceil( attempts / 10 ) ), 15000 )
 *
 * It is deliberately NOT recomputed from the same formula — deriving the
 * expectation from the implementation would make the pin tautological and let
 * a retune of 5000/60/15000 pass silently, which architecture item P3-020
 * forbids.
 *
 * @since 2.3.0
 */

import {
	getPollDelay,
	MAX_POLL_ATTEMPTS,
	MAX_POLL_DELAY_MS,
	POLL_INTERVAL_MS,
} from '../polling';

const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The de-duplication itself has to be pinned too.
 *
 * Without this, re-inlining a private copy of getPollDelay into a consumer is
 * invisible to every test in this repo: polling.test.js imports the shared
 * module directly, and the consumer suites only advance timers by *at least*
 * one interval, so a shortened local copy still passes. Verified by mutation —
 * a consumer-local POLL_INTERVAL_MS of 3000 left all 60 suites green.
 */
describe( 'polling single-source', () => {
	const CONSUMERS = [
		[ 'src/lib/useImageJobPolling.js', './polling' ],
		[ 'src/components/PageSpeedPanel.js', '../lib/polling' ],
	];

	const SHARED_SYMBOLS = [
		'POLL_INTERVAL_MS',
		'MAX_POLL_ATTEMPTS',
		'MAX_POLL_DELAY_MS',
		'getPollDelay',
	];

	it.each( CONSUMERS )(
		'%s imports the backoff from the shared module',
		( relativePath, specifier ) => {
			const source = fs.readFileSync(
				path.resolve( process.cwd(), relativePath ),
				'utf8'
			);

			expect( source ).toContain( `from '${ specifier }'` );
			expect( source ).toMatch(
				new RegExp( `import\\s*\\{[^}]*getPollDelay[^}]*\\}` )
			);
		}
	);

	it.each( CONSUMERS )(
		'%s does not redeclare a private copy of the backoff',
		( relativePath ) => {
			const source = fs.readFileSync(
				path.resolve( process.cwd(), relativePath ),
				'utf8'
			);

			for ( const symbol of SHARED_SYMBOLS ) {
				expect( source ).not.toMatch(
					new RegExp( `^const ${ symbol }\\b`, 'm' )
				);
			}
		}
	);
} );

describe( 'polling backoff', () => {
	describe( 'pinned constants (P3-020: fetch count and delay contract unchanged)', () => {
		it( 'keeps the base interval at 5000ms', () => {
			expect( POLL_INTERVAL_MS ).toBe( 5000 );
		} );

		it( 'keeps the attempt cap at 60', () => {
			expect( MAX_POLL_ATTEMPTS ).toBe( 60 );
		} );

		it( 'keeps the maximum delay at 15000ms', () => {
			expect( MAX_POLL_DELAY_MS ).toBe( 15000 );
		} );
	} );

	describe( 'getPollDelay( 1..61 )', () => {
		// Hard-coded pin of the pre-extraction output. Attempts 1-10 -> 5000,
		// 11-20 -> 10000, 21+ -> capped 15000.
		const PINNED = new Map();
		for ( let a = 1; a <= 10; a++ ) {
			PINNED.set( a, 5000 );
		}
		for ( let a = 11; a <= 20; a++ ) {
			PINNED.set( a, 10000 );
		}
		for ( let a = 21; a <= 61; a++ ) {
			PINNED.set( a, 15000 );
		}

		it.each( [ ...PINNED.entries() ].map( ( [ a, d ] ) => [ a, d ] ) )(
			'getPollDelay( %i ) === %i',
			( attempts, expected ) => {
				expect( getPollDelay( attempts ) ).toBe( expected );
			}
		);

		it( 'never exceeds MAX_POLL_DELAY_MS across the whole pinned range', () => {
			for ( let a = 1; a <= 61; a++ ) {
				expect( getPollDelay( a ) ).toBeLessThanOrEqual(
					MAX_POLL_DELAY_MS
				);
			}
		} );

		it( 'is monotonically non-decreasing (backoff, never speeds up)', () => {
			for ( let a = 2; a <= 61; a++ ) {
				expect( getPollDelay( a ) ).toBeGreaterThanOrEqual(
					getPollDelay( a - 1 )
				);
			}
		} );
	} );

	describe( 'non-finite / non-numeric attempt counts', () => {
		const HOSTILE_INPUTS = [
			[ 'NaN', NaN ],
			[ 'Infinity', Infinity ],
			[ '-Infinity', -Infinity ],
			[ 'undefined', undefined ],
			[ 'null', null ],
			[ 'non-numeric string', 'abc' ],
			[ 'numeric string', '12' ],
			[ 'plain object', {} ],
			[ 'array', [] ],
			[ 'Symbol()', Symbol( 'x' ) ],
		];

		it.each( HOSTILE_INPUTS )(
			'getPollDelay( %s ) returns a finite positive delay',
			( _label, value ) => {
				const delay = getPollDelay( value );

				expect( Number.isFinite( delay ) ).toBe( true );
				expect( delay ).toBeGreaterThan( 0 );
			}
		);

		it.each( HOSTILE_INPUTS )(
			'getPollDelay( %s ) defaults to the base interval (1 attempt)',
			( _label, value ) => {
				expect( getPollDelay( value ) ).toBe( POLL_INTERVAL_MS );
			}
		);

		it( 'is well above the 1ms hot-loop threshold', () => {
			// setTimeout( NaN ) is coerced to a 1ms delay by the host, which
			// turns a bad count into a hot loop against the REST API.
			for ( const [ , value ] of HOSTILE_INPUTS ) {
				expect( getPollDelay( value ) ).toBeGreaterThan( 1000 );
			}
		} );
	} );

	describe( 'no hot poll loop', () => {
		beforeEach( () => {
			jest.useFakeTimers();
		} );

		afterEach( () => {
			jest.useRealTimers();
		} );

		it( 'does not fire a rescheduled poll before the base interval elapses', () => {
			const tick = jest.fn();

			setTimeout( tick, getPollDelay( NaN ) );

			// A 1ms coercion would already have fired here.
			jest.advanceTimersByTime( 999 );
			expect( tick ).not.toHaveBeenCalled();

			jest.advanceTimersByTime( 1 );
			expect( tick ).not.toHaveBeenCalled();

			// Advance the remaining time up to exactly POLL_INTERVAL_MS.
			jest.advanceTimersByTime( POLL_INTERVAL_MS - 1000 );
			expect( tick ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'fires exactly once per base interval for an undefined count', () => {
			const tick = jest.fn();

			setTimeout( tick, getPollDelay( undefined ) );
			jest.runOnlyPendingTimers();

			expect( tick ).toHaveBeenCalledTimes( 1 );
		} );
	} );
} );
