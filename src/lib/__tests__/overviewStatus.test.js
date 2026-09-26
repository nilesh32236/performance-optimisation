/**
 * Tests for the Overview status model.
 *
 * The important property is honesty: the model must never claim "healthy"
 * without evidence, and must never invent a score. These tests attack the cases
 * where a status model is most likely to lie.
 */

import {
	ALL_STATUSES,
	buildStatusModel,
	deriveCacheStatus,
	deriveCompatibilityStatus,
	deriveObjectCacheStatus,
	deriveOverallStatus,
	deriveVitalsStatus,
	needsAttention,
	STATUS,
} from '../overviewStatus';

describe( 'deriveCacheStatus', () => {
	it( 'says not-configured, not unhealthy, when the cache is off', () => {
		const row = deriveCacheStatus( { enableCache: false } );
		expect( row.status ).toBe( STATUS.NOT_CONFIGURED );
		expect( needsAttention( row.status ) ).toBe( false );
	} );

	it( 'says healthy only when the backend positively reports it running', () => {
		expect(
			deriveCacheStatus( { enableCache: true, cache_enabled: true } )
				.status
		).toBe( STATUS.HEALTHY );
	} );

	it( 'flags attention when enabled but not serving', () => {
		expect(
			deriveCacheStatus( { enableCache: true, cache_enabled: false } )
				.status
		).toBe( STATUS.ATTENTION );
	} );

	it( 'does NOT claim healthy when enabled but the state is unknown', () => {
		const row = deriveCacheStatus( { enableCache: true } );
		expect( row.status ).not.toBe( STATUS.HEALTHY );
		expect( row.status ).toBe( STATUS.UNKNOWN );
		// And the user is told where to look, rather than left guessing.
		expect( row.detail ).toMatch( /Speed/ );
	} );

	it( 'does not treat the string "false" as truthy', () => {
		// A filter can hand back a string. Treating it as truthy is how a
		// disabled cache gets reported as healthy.
		expect(
			deriveCacheStatus( { enableCache: true, cache_enabled: 'false' } )
				.status
		).toBe( STATUS.ATTENTION );
		expect(
			deriveCacheStatus( { enableCache: true, cache_enabled: 'true' } )
				.status
		).toBe( STATUS.HEALTHY );
	} );

	it( 'reports unavailable rather than throwing on missing data', () => {
		expect( deriveCacheStatus( undefined ).status ).toBe(
			STATUS.UNAVAILABLE
		);
		expect( deriveCacheStatus( null ).status ).toBe( STATUS.UNAVAILABLE );
	} );
} );

describe( 'deriveObjectCacheStatus', () => {
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
			deriveObjectCacheStatus( { enabled: true, redis_reachable: true } ).status
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
		expect( row.detail ).toContain( '8.3.33' );
		expect( row.detail ).toContain( '7.1.2' );
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

	it( 'omits a vital that was not measured rather than calling it good', () => {
		const rows = deriveVitalsStatus( { lcp: 1200 } );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].status ).toBe( STATUS.HEALTHY );
		expect( rows.map( ( r ) => r.id ) ).not.toContain( 'vital-inp' );
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
			expect( typeof row.label ).toBe( 'string' );
			expect( row.label.length ).toBeGreaterThan( 0 );
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
