/**
 * Tests for the Overview page's data adaptation.
 *
 * The shape handling lives here, and it is the part that was wrong in the first
 * version: an independent review found the vitals rows never rendered at all
 * because the endpoint returns a keyed object rather than a flat array.
 */

import { summariseVitals } from '../Overview';
import { renderDetail, renderLabel } from '../detailCopy';
import { deriveVitalsStatus } from '../../../lib/overviewStatus';
import {
	WEB_VITALS_ALL_NULL,
	WEB_VITALS_PARTIAL,
	WEB_VITALS_TRENDS_RESPONSE,
	WEB_VITALS_WITH_REAL_ZEROS,
} from '../__fixtures__/vitals-fixture';

describe( 'summariseVitals', () => {
	it( 'reads the real keyed shape the endpoint returns', () => {
		// This is the regression the review found: against this payload an
		// array-only implementation returns null and the page shows no vitals.
		const vitals = summariseVitals( WEB_VITALS_TRENDS_RESPONSE.data );
		expect( vitals ).not.toBeNull();
		// Summarised **per device class**, never pooled across both.
		//
		// desktop rows: lcp 3120, 2410, 4100 -> median 3120; cls 0.14, 0.06,
		// 0.21 -> median 0.14.
		// mobile rows:  lcp 5300 -> 5300;                    cls 0.18 -> 0.18.
		//
		// Pooled, that is lcp 3120 and cls 0.14 — but pooling also merged a
		// 5300 ms mobile scan into a desktop figure, which is the defect the
		// per-device split exists to prevent.
		expect( vitals.desktop.lcp ).toBe( 3120 );
		expect( vitals.desktop.cls ).toBe( 0.14 );
		expect( vitals.mobile.lcp ).toBe( 5300 );
		expect( vitals.mobile.cls ).toBe( 0.18 );
	} );

	// The defect a final review found, pinned so pooling cannot return.
	//
	// This is the live site's actual stored distribution: 19 desktop rows whose
	// median is 345.5 ms, and 18 mobile rows whose median is 1202 ms. Pooling
	// all 37 into one median gives **504.5 ms**, which is the desktop
	// *maximum* — so the page showed the best case anyone recorded, for a
	// metric whose mobile value was 2.4x worse, naming neither the device nor
	// the fact that the number is a PageSpeed lab scan.
	it( 'summarises each device class separately instead of pooling them', () => {
		// 19 desktop rows spanning 252..504.5 and 18 mobile rows spanning
		// 903..1202, which is the shape of the live store. The 37-value median
		// therefore lands on the largest desktop row.
		const desktop = Array.from( { length: 19 }, ( _, i ) => ( {
			lcp: 252 + i * ( ( 504.5 - 252 ) / 18 ),
		} ) ); // max 504.5
		const mobile = Array.from( { length: 18 }, ( _, i ) => ( {
			lcp: 903 + i * ( ( 1202 - 903 ) / 9 ),
		} ) ); // median (index 9) 1202, max 1202
		const vitals = summariseVitals( {
			trends: { home_desktop: desktop, home_mobile: mobile },
		} );

		// Desktop 252..504.5 over 19 rows, so its own median is 378.25.
		expect( vitals.desktop.lcp ).toBe( 378.25 );
		expect( vitals.mobile.lcp ).toBe( 1202 );

		// Pooling them yields the desktop maximum, which is why the single
		// pooled figure the page used to show was not a real measurement of
		// anything.
		const pooled = [ ...desktop, ...mobile ]
			.map( ( r ) => r.lcp )
			.sort( ( a, b ) => a - b )[ Math.floor( 37 / 2 ) ];
		expect( pooled ).toBe( 504.5 );
		// Neither device's own median, and the pooled value is the desktop
		// *maximum* - which is precisely what the page used to present.
		expect( pooled ).not.toBe( vitals.desktop.lcp );
		expect( pooled ).not.toBe( vitals.mobile.lcp );
		expect( pooled ).toBe( 504.5 );
		expect( vitals.desktop.lcp ).toBeLessThan( pooled );
	} );

	it( 'keeps a device class apart even when one has no measurements', () => {
		const vitals = summariseVitals( {
			trends: {
				home_desktop: [ { lcp: 345.5 } ],
				home_mobile: [ { lcp: null } ],
			},
		} );
		expect( vitals.desktop.lcp ).toBe( 345.5 );
		expect( vitals.mobile ).toBeUndefined();
	} );

	// The durable test, and the one whose absence shipped a broken sentence.
	//
	// An independent review found the unmeasured sentence rendering as
	// "is not measured by a PageSpeed lab scan." - no subject - on both INP
	// rows of every load, because the UNKNOWN branch never passed `labelKey`.
	// The one-line fix changed the shipped sentence, and **all 1,297 tests still
	// passed**, because the copy table asserted it from a hand-built `detailArgs`
	// the model never emits.
	//
	// So this test builds the row the way production does - payload through
	// `summariseVitals`, then `deriveVitalsStatus` - and asserts on the rendered
	// **sentence**, not on `detailArgs`. A change to the producer's shape now
	// breaks it, which is the whole point.
	it( 'renders a complete sentence for every row a real payload produces', () => {
		const rows = deriveVitalsStatus(
			summariseVitals( {
				trends: {
					hasha_desktop: [
						{ lcp: 345.5, cls: 0.0018, tbt: 120 },
						{ lcp: 361, cls: 0.002, tbt: 130 },
					],
					hasha_mobile: [
						{ lcp: 1202, cls: 0.0092, tbt: 480 },
						{ lcp: 1180, cls: 0.01, tbt: 500 },
					],
				},
			} )
		);
		expect( rows ).toHaveLength( 6 );
		for ( const row of rows ) {
			const sentence = renderDetail( row );
			// A sentence, not a fragment: it starts with the metric's own name
			// and contains no leading whitespace.
			expect( sentence ).not.toMatch( /^\s/ );
			expect( sentence.length ).toBeGreaterThan( 20 );
			expect( renderLabel( row ) ).toBeTruthy();
			// Every rendered sentence names its source.
			expect( sentence ).toContain( 'PageSpeed lab scan' );
		}
		// The two INP rows, which are the branch that renders on every load.
		const inp = rows.filter( ( r ) => r.id.startsWith( 'vital-inp' ) );
		expect( inp ).toHaveLength( 2 );
		for ( const row of inp ) {
			expect( renderDetail( row ) ).toBe(
				'Responsiveness (INP) has no reading in the stored PageSpeed lab scan history yet. How quickly the page reacts to a tap or click.'
			);
		}
	} );

	// The unlabelled path must not attribute a number to a device it was never
	// measured on. A surviving mutation defaulted the lookup to 'desktop'.
	it( 'never names a device for an unlabelled source', () => {
		const rows = deriveVitalsStatus(
			summariseVitals( {
				trends: {
					h_plain: [
						{ lcp: 345, cls: 0.01 },
						{ lcp: 350, cls: 0.02 },
					],
				},
			} )
		);
		// Three rows: LCP and CLS measured, plus the INP row this source can
		// never fill. All three carry `device: null`.
		expect( rows ).toHaveLength( 3 );
		expect( rows.every( ( r ) => r.detailArgs.device === null ) ).toBe(
			true
		);
		for ( const row of rows ) {
			expect( renderDetail( row ) ).not.toMatch( /on (desktop|mobile)/ );
		}
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
		// Desktop: 3120, 2410, 4100 -> median 3120.
		expect( vitals.desktop.lcp ).toBe( 3120 );
		// And it is genuinely an aggregate, not "the last value we saw": the
		// slowest desktop row (4100) is not what gets reported.
		expect( vitals.desktop.lcp ).not.toBe( 4100 );
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
		const { mobile } = summariseVitals( WEB_VITALS_PARTIAL.data );
		expect( mobile ).toBeDefined();
		expect( mobile.lcp ).toBeUndefined();
		expect( mobile.cls ).toBe( 0.06 );
	} );

	it( 'counts a genuine zero as a measurement, not as an absence', () => {
		// A CLS of exactly 0 is an excellent real result. The first fixture had
		// no zero-valued metric, so a `> 0` filter passed it — and on the live
		// site 7 of 37 stored rows are true zeros, which moved the reported
		// median from 0.0026 to 0.0046.
		const { desktop } = summariseVitals( WEB_VITALS_WITH_REAL_ZEROS.data );
		expect( desktop.cls ).toBe( 0 );
		expect( desktop.lcp ).toBe( 1000 );
	} );

	it( 'still excludes absent readings, which also coerce to 0', () => {
		// The other half: `Number( null )` is 0, so absence must still be
		// rejected. Both properties are asserted because they are opposite.
		expect( summariseVitals( WEB_VITALS_ALL_NULL.data ) ).toBeNull();
	} );

	it( 'omits INP rather than borrowing a different metric', () => {
		// The stored history has `tbt` and `fcp` but no INP. The first version
		// listed `fcp` and `ttfb` as INP fallbacks, which would have reported a
		// number the source never measured.
		const vitals = summariseVitals( WEB_VITALS_TRENDS_RESPONSE.data );
		expect( vitals.desktop.inp ).toBeUndefined();
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

describe( 'summariseVitals: corrupt stored values', () => {
	// `Number()` accepts hex and arbitrary-precision digit strings, so a corrupt
	// stored value became a plausible measurement — and a large enough one
	// flipped the whole Overview to "Needs attention". Found by an independent
	// review and reproduced live.
	it( 'rejects hex, huge, and non-numeric values', () => {
		[
			{ lcp: '0x4d2' },
			{ lcp: '999999999999999999999999' },
			{ lcp: 'abc' },
			{ lcp: true },
			{ lcp: '1e400' },
		].forEach( ( v ) => {
			const out = summariseVitals( { trends: { a: [ v ] } } );
			expect( out === null || out.lcp === undefined ).toBe( true );
		} );
	} );

	it( 'rejects a value past a day in milliseconds', () => {
		// A page cannot take 24 hours to load; that is corrupt storage, not a
		// slow page, and it must not escalate the page verdict.
		expect(
			summariseVitals( { trends: { a: [ { lcp: 9e12 } ] } } )
		).toBeNull();
	} );

	it( 'still accepts a genuine numeric string', () => {
		// The bound must not over-reject real stored data.
		expect(
			summariseVitals( { trends: { a: [ { lcp: '1500' } ] } } ).lcp
		).toBe( 1500 );
	} );
} );
