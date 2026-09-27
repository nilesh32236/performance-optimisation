/**
 * Tests for the Overview status model.
 *
 * The important property is honesty: the model must never claim "healthy"
 * without evidence, and must never invent a score. These tests attack the cases
 * where a status model is most likely to lie.
 */

import {
	DETAIL,
	ALL_STATUSES,
	buildStatusModel,
	deriveCacheStatus,
	deriveCompatibilityStatus,
	deriveObjectCacheStatus,
	deriveOverallStatus,
	deriveVitalsStatus,
	needsAttention,
	STATUS,
	ROW,
} from '../overviewStatus';
import { renderLabel } from '../../components/overview/detailCopy';

describe( 'deriveCacheStatus', () => {
	it( 'says not-configured, not unhealthy, when the cache is off', () => {
		const row = deriveCacheStatus( { enableCache: false }, '14 MB' );
		expect( row.status ).toBe( STATUS.NOT_CONFIGURED );
		expect( needsAttention( row.status ) ).toBe( false );
	} );

	it( 'takes the measured size the same way production supplies it', () => {
		// This is the regression that shipped: the function read
		// `stats.cacheStats` from an object while buildStatusModel passed the
		// plain string, so every unit test using the object shape passed and
		// production read `undefined`. This test uses the call-site shape.
		expect(
			deriveCacheStatus( { enableCache: true }, '14 MB' ).status
		).toBe( STATUS.HEALTHY );
	} );

	it( 'reports working from the real cache statistics, not from enableCache', () => {
		// A cache that is switched on but storing nothing is a different
		// situation from one serving pages, so the evidence is the measured
		// statistics rather than the setting.
		const row = deriveCacheStatus( { enableCache: true }, '14 MB' );
		expect( row.status ).toBe( STATUS.HEALTHY );
		expect( row.detailKey ).toBe( DETAIL.cache_active );
		expect( row.detailArgs ).toEqual( { stored: '14 MB' } );
	} );

	it( 'treats a measured zero as a choice, not a fault', () => {
		// A freshly cleared cache legitimately holds nothing, and the next
		// request repopulates it. Calling that "needs attention" would be wrong.
		const row = deriveCacheStatus( { enableCache: true }, '0 B' );
		expect( row.status ).toBe( STATUS.NOT_CONFIGURED );
		expect( needsAttention( row.status ) ).toBe( false );
	} );

	it( 'anchors the zero test, so "0.5 MB" is not zero', () => {
		// `/^0/` matched "0.5 MB" and "0 B cached", so a populated cache was
		// reported as holding nothing. That mutation survived the suite.
		[ '0.5 MB', '0 B cached', '0.00 MB' ].forEach( ( value ) => {
			expect(
				deriveCacheStatus( { enableCache: true }, value ).status
			).toBe( STATUS.HEALTHY );
		} );
		[ '0 B', '0 B ', '0 bytes' ].forEach( ( value ) => {
			expect(
				deriveCacheStatus( { enableCache: true }, value ).status
			).toBe( STATUS.NOT_CONFIGURED );
		} );
	} );

	it( 'claims neither state when the setting key is absent', () => {
		// `! settings.enableCache` treated an absent key as "off".
		const row = deriveCacheStatus( {}, '14 MB' );
		expect( row.status ).not.toBe( STATUS.NOT_CONFIGURED );
		expect( row.status ).not.toBe( STATUS.HEALTHY );
	} );

	it( 'treats the literal "N/A" as unknown, not as zero', () => {
		// Cache::get_cache_stats() reports "N/A" when it cannot measure, which
		// is not the same as having measured nothing.
		expect( deriveCacheStatus( { enableCache: true }, 'N/A' ).status ).toBe(
			STATUS.UNKNOWN
		);
	} );

	it( 'stays honest when the statistics are absent entirely', () => {
		expect( deriveCacheStatus( { enableCache: true } ).status ).toBe(
			STATUS.UNKNOWN
		);
		expect( deriveCacheStatus( { enableCache: true } ).status ).toBe(
			STATUS.UNKNOWN
		);
		expect( deriveCacheStatus( { enableCache: true }, '' ).status ).toBe(
			STATUS.UNKNOWN
		);
	} );

	it( 'reports unavailable rather than throwing on missing data', () => {
		expect( deriveCacheStatus( undefined ).status ).toBe(
			STATUS.UNAVAILABLE
		);
		expect( deriveCacheStatus( null ).status ).toBe( STATUS.UNAVAILABLE );
	} );
} );

describe( 'deriveObjectCacheStatus', () => {
	it( 'does NOT report a working cache when enabled is the string "false"', () => {
		// The single most important regression here. A truthiness check reads
		// the string "false" as true, which reported a *disabled* object cache as
		// working. The review reproduced this on the live page.
		//
		// Asserted as NOT_CONFIGURED, not merely "not HEALTHY". A triState that
		// returned null for 'false' would yield UNKNOWN, which also satisfies a
		// `not.toBe(HEALTHY)` test — so the weaker form passed even with the
		// entire string-coercion block deleted.
		[ 'false', '0', 0 ].forEach( ( value ) => {
			expect(
				deriveObjectCacheStatus( {
					enabled: value,
					redis_reachable: true,
				} ).status
			).toBe( STATUS.NOT_CONFIGURED );
		} );
		// A missing or nonsensical value is genuinely "do not know", and must not
		// be reported as switched off either.
		[ null, '', [], {} ].forEach( ( value ) => {
			expect(
				deriveObjectCacheStatus( {
					enabled: value,
					redis_reachable: true,
				} ).status
			).toBe( STATUS.UNKNOWN );
		} );
	} );

	it( 'does NOT report a foreign drop-in when the flag is the string "false"', () => {
		// The mirror bug on the neighbouring field.
		expect(
			deriveObjectCacheStatus( {
				enabled: true,
				redis_reachable: true,
				foreign_dropin: 'false',
			} ).status
		).not.toBe( STATUS.ATTENTION );
	} );

	it( 'reports unknown when the plugin did not say whether it is enabled', () => {
		// Neither on nor off: the row must not pick one.
		expect(
			deriveObjectCacheStatus( { redis_reachable: true } ).status
		).toBe( STATUS.UNKNOWN );
	} );

	it( 'treats an open circuit breaker as a problem, not as working', () => {
		// Reproduced live by the review: bypassed:true, circuit_open:true still
		// rendered a green "Working" badge.
		const row = deriveObjectCacheStatus( {
			enabled: true,
			redis_reachable: true,
			circuit_open: true,
		} );
		expect( row.status ).toBe( STATUS.ATTENTION );
		expect( row.detailKey ).toBe( DETAIL.object_circuit_open );
	} );

	it( 'treats an outage bypass as a problem, not as working', () => {
		expect(
			deriveObjectCacheStatus( {
				enabled: true,
				redis_reachable: true,
				bypassed: true,
			} ).status
		).toBe( STATUS.ATTENTION );
	} );

	it( 'reports a missing extension, not an unreachable server', () => {
		// The real `get_status()` shape: it returns early when the extension is
		// missing, so `redis_reachable` is *always* false alongside it. A test
		// using `redis_reachable: true` here exercised a shape production can
		// never emit, and the branch was unreachable.
		const row = deriveObjectCacheStatus( {
			enabled: true,
			redis_reachable: false,
			redis_missing: true,
		} );
		expect( row.status ).toBe( STATUS.ATTENTION );
		expect( row.detailKey ).toBe( DETAIL.object_no_extension );
	} );

	it( 'reports a working cache only when enabled, reachable, and not bypassed', () => {
		expect(
			deriveObjectCacheStatus( {
				enabled: true,
				redis_reachable: true,
			} ).status
		).toBe( STATUS.HEALTHY );
	} );

	it( 'treats a site without object cache as a choice, not a problem', () => {
		expect( deriveObjectCacheStatus( { enabled: false } ).status ).toBe(
			STATUS.NOT_CONFIGURED
		);
	} );

	it( 'flags attention when enabled but unreachable', () => {
		expect(
			deriveObjectCacheStatus( { enabled: true, redis_reachable: false } )
				.status
		).toBe( STATUS.ATTENTION );
	} );

	it( 'flags attention when another plugin owns the drop-in', () => {
		expect(
			deriveObjectCacheStatus( { enabled: true, foreign_dropin: true } )
				.status
		).toBe( STATUS.ATTENTION );
	} );

	it( 'reports healthy only when enabled AND reachable', () => {
		expect(
			deriveObjectCacheStatus( { enabled: true, redis_reachable: true } )
				.status
		).toBe( STATUS.HEALTHY );
	} );

	it( 'does not invent health when reachable was never reported', () => {
		expect( deriveObjectCacheStatus( { enabled: true } ).status ).not.toBe(
			STATUS.HEALTHY
		);
	} );

	it( 'prefers a foreign drop-in over a reachability claim', () => {
		// A foreign drop-in means this plugin's cache is not running at all,
		// whatever the server says.
		expect(
			deriveObjectCacheStatus( {
				enabled: true,
				redis_reachable: true,
				foreign_dropin: true,
			} ).status
		).toBe( STATUS.ATTENTION );
	} );
} );

describe( 'deriveCompatibilityStatus', () => {
	it( 'reports the versions the plugin actually returned', () => {
		// The shape the plugin actually returns — verified against
		// `wp wppo system-info --format=json` on the live site.
		const row = deriveCompatibilityStatus( {
			php: { version: '8.3.33' },
			wordpress: { version: '7.1.2' },
		} );
		expect( row.status ).toBe( STATUS.HEALTHY );
		expect( row.detailKey ).toBe( DETAIL.system_versions );
		expect( row.detailArgs ).toEqual( { wp: '7.1.2', php: '8.3.33' } );
	} );

	it( 'is unknown, not healthy, when EITHER version is missing', () => {
		// The `||` is load-bearing. With `&&` a payload carrying only WordPress
		// was treated as complete and the row rendered a green "Running on
		// WordPress 7.1.2 and PHP ." — claiming a PHP version never reported.
		// That mutation survived 108/108 before this test.
		const onlyWp = deriveCompatibilityStatus( {
			wordpress: { version: '7.1.2' },
		} );
		expect( onlyWp.status ).not.toBe( STATUS.HEALTHY );
		expect( onlyWp.status ).toBe( STATUS.UNKNOWN );
		expect( onlyWp.detailKey ).toBe( DETAIL.system_unknown );

		const onlyPhp = deriveCompatibilityStatus( {
			php: { version: '8.3' },
		} );
		expect( onlyPhp.status ).toBe( STATUS.UNKNOWN );
	} );

	it( 'rejects a version that is a boolean or zero', () => {
		// `String()` turned these into the literals "false" and "0", producing
		// a working "Running on WordPress false and PHP 0." row.
		[
			{ php: { version: false }, wordpress: { version: '7.1.2' } },
			{ php: { version: '8.3' }, wordpress: { version: 0 } },
			{ php: { version: 0 }, wordpress: { version: 0 } },
		].forEach( ( payload ) => {
			const row = deriveCompatibilityStatus( payload );
			expect( row.status ).toBe( STATUS.UNKNOWN );
			expect( row.detailKey ).toBe( DETAIL.system_unknown );
		} );
	} );

	it( 'is unknown, not healthy, when versions are missing', () => {
		expect(
			deriveCompatibilityStatus( { php_version: '', wp_version: '' } )
				.status
		).toBe( STATUS.UNKNOWN );
		expect( deriveCompatibilityStatus( {} ).status ).toBe( STATUS.UNKNOWN );
	} );

	it( 'reports unavailable when the request failed', () => {
		expect( deriveCompatibilityStatus( null ).status ).toBe(
			STATUS.UNAVAILABLE
		);
	} );
} );

describe( 'deriveVitalsStatus', () => {
	it( 'returns nothing when there is no vitals data at all', () => {
		expect( deriveVitalsStatus( undefined ) ).toEqual( [] );
		expect( deriveVitalsStatus( null ) ).toEqual( [] );
	} );

	it( 'reports a vital the source knows about but has not measured', () => {
		// A key present with an undefined value is a metric the source tracks
		// but has not measured. Dropping the row would read as "nothing to say"
		// rather than "not measured yet"; that mutation survived 108/108.
		const rows = deriveVitalsStatus( {
			lcp: 505,
			cls: undefined,
			inp: undefined,
		} );
		expect( rows.map( ( r ) => r.id ) ).toEqual( [
			'vital-lcp',
			'vital-cls',
			'vital-inp',
		] );
		expect( rows[ 1 ].status ).toBe( STATUS.UNKNOWN );
	} );

	it( 'rejects a zero LCP but accepts a zero CLS', () => {
		// 0 ms is not a real LCP; a CLS of exactly 0 is a genuinely excellent
		// measurement, and the live store really does hold seven of them.
		expect( deriveVitalsStatus( { lcp: 0 } )[ 0 ].status ).not.toBe(
			STATUS.HEALTHY
		);
		expect( deriveVitalsStatus( { cls: 0 } )[ 0 ].status ).toBe(
			STATUS.HEALTHY
		);
	} );

	it( 'rejects a value at the upper bound, not just past it', () => {
		// `value > 86400000` let exactly 86400000 through.
		expect( deriveVitalsStatus( { lcp: 86400000 } )[ 0 ].status ).toBe(
			STATUS.UNKNOWN
		);
	} );

	it( 'omits a vital that was not measured rather than calling it good', () => {
		const rows = deriveVitalsStatus( { lcp: 1200 } );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].status ).toBe( STATUS.HEALTHY );
		expect( rows.map( ( r ) => r.id ) ).not.toContain( 'vital-inp' );
	} );

	it( 'treats a value exactly at the threshold as good, not poor', () => {
		// **T4** — `raw <= good` versus `raw < good`. A vital landing exactly on
		// the published boundary is *good*, and flipping that also escalated the
		// overall verdict, because one ATTENTION row drives it. Nothing tested
		// the boundary itself.
		expect( deriveVitalsStatus( { lcp: 2500 } )[ 0 ].status ).toBe(
			STATUS.HEALTHY
		);
		expect( deriveVitalsStatus( { cls: 0.1 } )[ 0 ].status ).toBe(
			STATUS.HEALTHY
		);
		expect( deriveVitalsStatus( { inp: 200 } )[ 0 ].status ).toBe(
			STATUS.HEALTHY
		);
		// The *judged* number is now the *shown* number, so the comparison uses
		// the rounded value. 2500.1 displays as 2500, and 2500 is the published
		// good threshold, so it is good — the previous behaviour reported
		// "could be better (2500 ms)", which showed a good threshold while
		// calling it poor.
		expect( deriveVitalsStatus( { lcp: 2500.1 } )[ 0 ].status ).toBe(
			STATUS.HEALTHY
		);
		expect(
			deriveVitalsStatus( { lcp: 2500.1 } )[ 0 ].detailArgs.value
		).toBe( '2500 ms' );
		expect( deriveVitalsStatus( { lcp: 2500.1 } )[ 0 ].detailKey ).toBe(
			DETAIL.vital_good
		);
		// A value that displays past the boundary still is not good.
		expect( deriveVitalsStatus( { lcp: 2500.6 } )[ 0 ].status ).toBe(
			STATUS.ATTENTION
		);
		// The *poor* boundary is the other `<=` and was unpinned. Both sides of
		// it are ATTENTION, so the comparison alone cannot tell them apart — the
		// message key is what distinguishes "could be better" from "is poor",
		// and it is what a translator now sees.
		expect( deriveVitalsStatus( { lcp: 4000 } )[ 0 ].detailKey ).toBe(
			DETAIL.vital_attention
		);
		// 4000.1 also displays as 4000, which is the poor threshold, so it reads
		// "could be better" — the judged and shown numbers agree again.
		expect( deriveVitalsStatus( { lcp: 4000.1 } )[ 0 ].detailKey ).toBe(
			DETAIL.vital_attention
		);
		expect( deriveVitalsStatus( { lcp: 4000.6 } )[ 0 ].detailKey ).toBe(
			DETAIL.vital_poor
		);
		// And a good boundary value must not drag the page verdict down.
		const { overall } = buildStatusModel( {
			cacheSettings: { enableCache: true },
			cacheStats: '14 MB',
			objectCache: { enabled: true, redis_reachable: true },
			systemInfo: {
				php: { version: '8.3' },
				wordpress: { version: '7.1.2' },
			},
			vitals: { lcp: 2500 },
		} );
		expect( overall ).toBe( STATUS.HEALTHY );
	} );

	it( 'applies the published threshold for each vital', () => {
		expect( deriveVitalsStatus( { lcp: 1000 } )[ 0 ].status ).toBe(
			STATUS.HEALTHY
		);
		expect( deriveVitalsStatus( { lcp: 3000 } )[ 0 ].status ).toBe(
			STATUS.ATTENTION
		);
		expect( deriveVitalsStatus( { lcp: 9000 } )[ 0 ].status ).toBe(
			STATUS.ATTENTION
		);
	} );

	it( 'uses a unitless threshold for CLS, not a millisecond one', () => {
		expect( deriveVitalsStatus( { cls: 0.05 } )[ 0 ].status ).toBe(
			STATUS.HEALTHY
		);
		expect( deriveVitalsStatus( { cls: 0.3 } )[ 0 ].status ).toBe(
			STATUS.ATTENTION
		);
	} );

	it( 'reports an UNREPORTED vital as unknown, never as a perfect zero', () => {
		// Number(null), Number(''), Number([]) and Number(false) are all 0, and
		// 0 <= 2500. The review reproduced three green "good at 0 ms" rows on a
		// site with no measurement at all.
		[ null, '', [], false, NaN, '  ', {} ].forEach( ( value ) => {
			const row = deriveVitalsStatus( { lcp: value } )[ 0 ];
			expect( row.status ).toBe( STATUS.UNKNOWN );
			expect( row.detailKey ).not.toBe( DETAIL.vital_good );
		} );
	} );

	it( 'reports a nonsense measurement as unknown, never as healthy', () => {
		expect( deriveVitalsStatus( { lcp: 'fast' } )[ 0 ].status ).toBe(
			STATUS.UNKNOWN
		);
		expect( deriveVitalsStatus( { lcp: -5 } )[ 0 ].status ).toBe(
			STATUS.UNKNOWN
		);
	} );
} );

describe( 'deriveOverallStatus', () => {
	const row = ( status ) => ( { id: 'x', label: 'X', status, detail: '' } );

	it( 'one attention row is enough to need attention', () => {
		expect(
			deriveOverallStatus( [
				row( STATUS.HEALTHY ),
				row( STATUS.ATTENTION ),
			] )
		).toBe( STATUS.ATTENTION );
	} );

	it( 'features that are merely off do NOT drag the verdict down', () => {
		// Turning a feature off is a choice. Reporting it as unhealthy would
		// make a correctly configured minimal site look broken.
		expect(
			deriveOverallStatus( [
				row( STATUS.HEALTHY ),
				row( STATUS.NOT_CONFIGURED ),
			] )
		).toBe( STATUS.HEALTHY );
	} );

	it( 'an unavailable row alone does not imply a problem', () => {
		expect( deriveOverallStatus( [ row( STATUS.UNAVAILABLE ) ] ) ).not.toBe(
			STATUS.ATTENTION
		);
	} );

	it( 'an unknown row among healthy rows is not escalated', () => {
		expect(
			deriveOverallStatus( [
				row( STATUS.HEALTHY ),
				row( STATUS.UNKNOWN ),
			] )
		).toBe( STATUS.HEALTHY );
	} );

	it( 'everything unconfigured reads as not-configured', () => {
		expect(
			deriveOverallStatus( [
				row( STATUS.NOT_CONFIGURED ),
				row( STATUS.NOT_CONFIGURED ),
			] )
		).toBe( STATUS.NOT_CONFIGURED );
	} );

	it( 'never blames the user for a backend failure', () => {
		// Removing the `reportable` filter made an all-unavailable model return
		// `not-configured` — "Not set up" — which reads as the user's fault for
		// a request that never succeeded. An independent review found this
		// unpinned.
		expect(
			deriveOverallStatus( [
				{ id: 'a', label: 'A', status: STATUS.UNAVAILABLE, detail: '' },
			] )
		).toBe( STATUS.UNAVAILABLE );
		expect(
			deriveOverallStatus( [
				{ id: 'a', label: 'A', status: STATUS.UNAVAILABLE, detail: '' },
				{ id: 'b', label: 'B', status: STATUS.UNAVAILABLE, detail: '' },
			] )
		).toBe( STATUS.UNAVAILABLE );
	} );

	it( 'is unavailable with no rows, or a non-array', () => {
		expect( deriveOverallStatus( [] ) ).toBe( STATUS.UNAVAILABLE );
		expect( deriveOverallStatus( undefined ) ).toBe( STATUS.UNAVAILABLE );
	} );
} );

describe( 'buildStatusModel', () => {
	it( 'never produces a numeric score of any kind', () => {
		const model = buildStatusModel( {
			cacheSettings: { enableCache: true, cache_enabled: true },
			objectCache: { enabled: true, redis_reachable: true },
			systemInfo: {
				php: { version: '8.3' },
				wordpress: { version: '7.1' },
			},
		} );

		expect( typeof model.overall ).toBe( 'string' );
		expect( model.overall ).toBe( STATUS.HEALTHY );

		// Serialise the WHOLE model, not a destructured subset. An earlier
		// version destructured `{ rows, overall }` first, which made this
		// assertion pass even with a `score` property present — it could not see
		// the field it was written to forbid.
		expect( JSON.stringify( model ) ).not.toMatch(
			/"score"|"rating"|"grade"|"percent"/
		);
		expect( JSON.stringify( model ) ).not.toMatch( /\b\d{1,3}\s*\/\s*100/ );

		// And no numeric leaf anywhere, so a score cannot hide under another key.
		const walk = ( node ) => {
			if ( typeof node === 'number' ) {
				throw new Error(
					'the Overview model must not carry numeric leaves'
				);
			}
			// Only descend into objects and arrays. Recursing into a string is
			// what turned an earlier version of this into infinite recursion:
			// Object.values( 'a' ) yields [ 'a' ] forever.
			if ( node === null || typeof node !== 'object' ) {
				return;
			}
			Object.values( node ).forEach( walk );
		};
		walk( model );
	} );

	it( 'threads cacheStats through to the page-cache row', () => {
		// The single highest-value line in this file, and the mutation the
		// independent review could not catch: renaming `payload.cacheStats` to
		// `payload.cache_stats` in buildStatusModel left 1186/1186 tests green
		// while production reverted to a permanently "Unknown" page-cache row.
		//
		// Every other buildStatusModel test passes cacheSettings without
		// cacheStats, so nothing pinned the *wiring* between the two — and the
		// wiring is exactly where the original bug lived.
		const { rows, overall } = buildStatusModel( {
			cacheSettings: { enableCache: true },
			cacheStats: '14 MB',
		} );
		expect( rows[ 0 ].id ).toBe( 'page-cache' );
		expect( rows[ 0 ].status ).toBe( STATUS.HEALTHY );
		expect( rows[ 0 ].detailArgs.stored ).toBe( '14 MB' );
		expect( overall ).toBe( STATUS.HEALTHY );
	} );

	it( 'emits only known status values, so no badge can go blank', () => {
		const { rows } = buildStatusModel( {
			cacheSettings: { enableCache: 'yes' },
			objectCache: 'garbage',
			systemInfo: 42,
		} );
		rows.forEach( ( row ) =>
			expect( ALL_STATUSES ).toContain( row.status )
		);
	} );

	it( 'gives every row a label, so the card never renders a nameless badge', () => {
		const { rows } = buildStatusModel( {} );
		rows.forEach( ( row ) => {
			// The model hands out a key, not prose; the copy layer turns it
			// into a heading. What matters here is that it is never empty,
			// because an empty key renders a nameless badge.
			expect( typeof row.labelKey ).toBe( 'string' );
			expect( row.labelKey.length ).toBeGreaterThan( 0 );
			expect( Object.values( ROW ) ).toContain( row.labelKey );
			expect( renderLabel( row ) ).toBeTruthy();
		} );
	} );

	it( 'is stable with no payload at all', () => {
		const { rows, overall } = buildStatusModel();
		expect( rows ).toHaveLength( 3 );
		expect( ALL_STATUSES ).toContain( overall );
	} );

	it( 'folds vitals into the verdict, not just the list', () => {
		// A good cache with a poor LCP must not read as healthy overall.
		const { overall } = buildStatusModel( {
			cacheSettings: { enableCache: true, cache_enabled: true },
			objectCache: { enabled: false },
			systemInfo: {
				php: { version: '8.3' },
				wordpress: { version: '7.1' },
			},
			vitals: { lcp: 9000 },
		} );
		expect( overall ).toBe( STATUS.ATTENTION );
	} );
} );
