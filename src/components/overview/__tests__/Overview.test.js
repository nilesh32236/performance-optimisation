/**
 * Tests for the Overview page's data adaptation.
 *
 * The shape handling lives here, and it is the part that was wrong in the first
 * version: an independent review found the vitals rows never rendered at all
 * because the endpoint returns a keyed object rather than a flat array.
 */

import { summariseVitals } from '../Overview';
import {
	WEB_VITALS_ALL_NULL,
	WEB_VITALS_PARTIAL,
	WEB_VITALS_TRENDS_RESPONSE,
} from '../__fixtures__/vitals-fixture';

describe( 'summariseVitals', () => {
	it( 'reads the real keyed shape the endpoint returns', () => {
		// This is the regression the review found: against this payload an
		// array-only implementation returns null and the page shows no vitals.
		const vitals = summariseVitals( WEB_VITALS_TRENDS_RESPONSE.data );
		expect( vitals ).not.toBeNull();
		// lcp across all four rows: 2410, 3120, 4100, 5300 -> median 4100.
		expect( vitals.lcp ).toBe( 4100 );
		expect( vitals.cls ).toBe( 0.18 ); // 0.06, 0.14, 0.18, 0.21 -> median 0.18
	} );

	it( 'accepts the full response envelope as well as the payload', () => {
		expect( summariseVitals( WEB_VITALS_TRENDS_RESPONSE ) ).toEqual(
			summariseVitals( WEB_VITALS_TRENDS_RESPONSE.data )
		);
	} );

	it( 'still accepts a flat array', () => {
		expect(
			summariseVitals( { trends: [ { lcp: 1500, cls: 0.05 } ] } )
		).toEqual( { lcp: 1500, cls: 0.05, inp: undefined } );
	} );

	it( 'takes the median across the stored history, not the newest or luckiest', () => {
		// The median is deliberately used, so one slow scan cannot define the
		// site and neither can the newest scan alone.
		const vitals = summariseVitals( WEB_VITALS_TRENDS_RESPONSE.data );
		const newest = 4100; // the most recent desktop row, also the median
		expect( vitals.lcp ).toBe( newest );
		// And it is genuinely an aggregate, not "the last value we saw": the
		// slowest row (5300) is not what gets reported.
		expect( vitals.lcp ).not.toBe( 5300 );
	} );

	it( 'never reports an absent reading as zero', () => {
		// Number( null ) === 0. Counting that as a measurement is how an
		// unmeasured site reports a perfect score.
		const vitals = summariseVitals( WEB_VITALS_ALL_NULL.data );
		expect( vitals ).toBeNull();
	} );

	it( 'omits a metric that is absent everywhere, without zeroing the others', () => {
		// The case that separates an explicit "absent" check from a `> 0`
		// filter. `Number( null )` is 0, so a missing LCP can silently become a
		// real measurement of 0 ms and be reported as a perfect score.
		const vitals = summariseVitals( WEB_VITALS_PARTIAL.data );
		expect( vitals ).not.toBeNull();
		expect( vitals.lcp ).toBeUndefined();
		expect( vitals.cls ).toBe( 0.06 );
	} );

	it( 'omits INP rather than borrowing a different metric', () => {
		// The stored history has `tbt` and `fcp` but no INP. The first version
		// listed `fcp` and `ttfb` as INP fallbacks, which would have reported a
		// number the source never measured.
		const vitals = summariseVitals( WEB_VITALS_TRENDS_RESPONSE.data );
		expect( vitals.inp ).toBeUndefined();
	} );

	it( 'uses INP when a source genuinely provides it', () => {
		expect(
			summariseVitals( { trends: [ { lcp: 1000, inp: 150 } ] } ).inp
		).toBe( 150 );
	} );

	it( 'returns null for shapes that carry no data at all', () => {
		[ undefined, null, {}, { trends: {} }, { trends: null } ].forEach(
			( value ) => expect( summariseVitals( value ) ).toBeNull()
		);
	} );

	it( 'ignores non-object rows without throwing', () => {
		expect(
			summariseVitals( { trends: { a: [ null, 'x', { lcp: 900 } ] } } )
		).toEqual( { lcp: 900, cls: undefined, inp: undefined } );
	} );
} );
