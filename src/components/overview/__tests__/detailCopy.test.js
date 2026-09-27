/**
 * Every key the status model can emit must have copy, and the copy must take the
 * model's arguments.
 *
 * The model returns a key plus arguments rather than English prose, so a key
 * with no entry here would render a **blank row** — a silent failure that looks
 * like a layout problem rather than a missing translation. That is exactly the
 * failure mode this file exists to prevent.
 *
 * @package
 */

import { DETAIL_COPY, renderDetail, renderLabel } from '../detailCopy';
import { DETAIL, VITAL } from '../../../lib/overviewStatus';

describe( 'Overview detail copy', () => {
	it( 'has an entry for every key the model can emit', () => {
		const missing = Object.values( DETAIL ).filter(
			( key ) => ! DETAIL_COPY[ key ]
		);
		expect( missing ).toEqual( [] );
	} );

	it( 'has no entry the model cannot emit', () => {
		// A stale entry is dead copy that translators would be asked to
		// translate for a sentence no user can see.
		const emit = new Set( Object.values( DETAIL ) );
		const orphan = Object.keys( DETAIL_COPY ).filter(
			( k ) => ! emit.has( k )
		);
		expect( orphan ).toEqual( [] );
	} );

	// A table, not a spot check.
	//
	// Nine mutations survived when only three keys had their English pinned:
	// swapping `label` and `value`, swapping the `order` array, adding or
	// dropping a placeholder, and making `t()` ignore its `order` altogether
	// all left every other test green while rendering
	// "9 MB is poor at 7.1.2. 8.3". Structural checks — the key exists, there
	// is no orphan, no unresolved `%s` leaks — are invariant under every
	// argument permutation, so they cannot catch any of it.
	//
	// Pinning the finished English for every key is what catches them, and it
	// is also the direct guard for the one thing this refactor is for: the
	// sentence the user reads must not change.
	const RENDERED = [
		[
			DETAIL.cache_unavailable,
			{},
			'Cache state is not available right now.',
		],
		[
			DETAIL.cache_unknown,
			{},
			'The plugin did not report whether the page cache is enabled.',
		],
		[
			DETAIL.cache_off,
			{},
			'Page cache is turned off. Turning it on is usually the single biggest speed win.',
		],
		[
			DETAIL.cache_unreadable,
			{},
			'Page cache is switched on, but the plugin could not read how much it has stored. Open Speed to check the cache status.',
		],
		[
			DETAIL.cache_empty,
			{},
			'Page cache is switched on but nothing is cached yet. The next visitor will generate a page.',
		],
		[
			DETAIL.cache_active,
			{ stored: '9 MB' },
			'Page cache is on, with 9 MB of cached pages stored.',
		],
		[
			DETAIL.object_unavailable,
			{},
			'Object cache state is not available right now.',
		],
		[
			DETAIL.object_off,
			{},
			'Object cache (Redis or Memcached) is off. Useful for busy or dynamic sites; not needed everywhere.',
		],
		[
			DETAIL.object_unknown,
			{},
			'The plugin did not report whether the object cache is enabled.',
		],
		[
			DETAIL.object_no_extension,
			{},
			'Object cache is enabled but the Redis extension is not available, so it cannot be used.',
		],
		[
			DETAIL.object_foreign_dropin,
			{},
			'Another plugin installed the object-cache drop-in, so this one is not active.',
		],
		[
			DETAIL.object_circuit_open,
			{},
			'Object cache is enabled but the safety breaker is open, so it is switched off until it recovers.',
		],
		[
			DETAIL.object_bypassed,
			{},
			'Object cache is enabled but is currently bypassed because of recent failures.',
		],
		[
			DETAIL.object_unreachable,
			{},
			'Object cache is enabled but the server is not reachable, so it is not speeding anything up.',
		],
		[
			DETAIL.object_reachable,
			{},
			'Object cache is enabled and the server is reachable.',
		],
		[
			DETAIL.object_reachability_unknown,
			{},
			'Object cache is enabled, but the plugin has not reported whether the server is reachable.',
		],
		[
			DETAIL.system_unavailable,
			{},
			'Server details are not available right now.',
		],
		[
			DETAIL.system_unknown,
			{},
			'The plugin did not report the PHP or WordPress version.',
		],
		[
			DETAIL.system_versions,
			{ wp: '7.1.2', php: '8.3' },
			'Running on WordPress 7.1.2 and PHP 8.3.',
		],
		[
			DETAIL.vital_unmeasured,
			{ labelKey: VITAL.inp, hintKey: VITAL.inp },
			'No reading in the stored PageSpeed lab scan history yet. How quickly the page reacts to a tap or click.',
		],
		[
			DETAIL.vital_good,
			{ labelKey: VITAL.lcp, value: '505 ms', device: 'desktop' },
			'Good: 505 ms (PageSpeed lab scan).',
		],
		[
			DETAIL.vital_attention,
			{
				labelKey: VITAL.lcp,
				value: '2500 ms',
				hintKey: VITAL.lcp,
				device: 'desktop',
			},
			'Could be better: 2500 ms (PageSpeed lab scan). How long the main content takes to appear.',
		],
		[
			DETAIL.vital_poor,
			{
				labelKey: VITAL.lcp,
				value: '4000 ms',
				hintKey: VITAL.lcp,
				device: 'desktop',
			},
			'Poor: 4000 ms (PageSpeed lab scan). How long the main content takes to appear.',
		],
	];

	it.each( RENDERED )(
		'renders %s exactly, with its arguments in place',
		( key, args, expected ) => {
			expect( renderDetail( { detailKey: key, detailArgs: args } ) ).toBe(
				expected
			);
		}
	);

	// The order of the *arguments* must not matter; only the order declared by
	// the copy does. Without this, a `t()` that ignored its `order` and used
	// `Object.values(args)` would pass every other test, because the model
	// happens to build each argument object in the same order as the map. An
	// independent review found exactly that survivor.
	it.each( [
		[
			DETAIL.vital_poor,
			{
				hintKey: VITAL.cls,
				value: '4000 ms',
				labelKey: VITAL.lcp,
				device: 'desktop',
			},
			'Poor: 4000 ms (PageSpeed lab scan). How much the page jumps around while loading.',
		],
		[
			DETAIL.vital_attention,
			{
				hintKey: VITAL.cls,
				value: '2500 ms',
				labelKey: VITAL.lcp,
				device: 'desktop',
			},
			'Could be better: 2500 ms (PageSpeed lab scan). How much the page jumps around while loading.',
		],
		[
			DETAIL.vital_good,
			{ value: '505 ms', labelKey: VITAL.lcp, device: 'desktop' },
			'Good: 505 ms (PageSpeed lab scan).',
		],
		[
			DETAIL.system_versions,
			{ php: '8.3', wp: '7.1.2' },
			'Running on WordPress 7.1.2 and PHP 8.3.',
		],
	] )(
		'places %s by the declared order, whatever order the args arrive in',
		( key, args, expected ) => {
			expect( renderDetail( { detailKey: key, detailArgs: args } ) ).toBe(
				expected
			);
		}
	);

	it( 'covers every key the model can emit', () => {
		// A key added without a pinned sentence would slip through, so the
		// table is checked against the model rather than trusted.
		expect( RENDERED.map( ( [ key ] ) => key ).sort() ).toEqual(
			Object.values( DETAIL ).sort()
		);
	} );

	// An unrecognised device is not guessed at. A mutation that fell back to
	// `DEVICE_COPY.mobile` for anything unknown passed the whole suite, and a
	// prototype key rendered the function body into the bold heading.
	it.each( [
		'tablet',
		'all',
		'',
		null,
		undefined,
		'toString',
		'constructor',
		'__proto__',
		'hasOwnProperty',
		0,
		false,
	] )( 'never invents a device for %p', ( device ) => {
		expect(
			renderLabel( { labelKey: VITAL.lcp, detailArgs: { device } } )
		).toBe( 'Loading (LCP)' );
		// And the sentence never claims one either.
		expect(
			renderDetail( {
				detailKey: DETAIL.vital_good,
				detailArgs: { labelKey: VITAL.lcp, value: '346 ms', device },
			} )
		).toBe( 'Good: 346 ms (PageSpeed lab scan).' );
	} );

	it( 'names the device exactly once, in the label', () => {
		const label = renderLabel( {
			labelKey: VITAL.lcp,
			detailArgs: { device: 'desktop' },
		} );
		const sentence = renderDetail( {
			detailKey: DETAIL.vital_good,
			detailArgs: {
				labelKey: VITAL.lcp,
				value: '346 ms',
				device: 'desktop',
			},
		} );
		expect( label ).toBe( 'Loading (LCP) on desktop' );
		expect( sentence ).toBe( 'Good: 346 ms (PageSpeed lab scan).' );
		// Read together they say it once, and neither repeats the other.
		expect( label + ' ' + sentence ).toMatch( /desktop/ );
	} );

	it( 'renders a sentence rather than a key or a blank', () => {
		const sentence = renderDetail( {
			detailKey: DETAIL.cache_active,
			detailArgs: { stored: '9 MB' },
		} );
		expect( sentence ).toMatch( /Page cache is on, with 9 MB/ );
		expect( sentence ).not.toContain( 'cache-active' );
	} );

	it( 'interpolates both versions in the compatibility sentence', () => {
		const sentence = renderDetail( {
			detailKey: DETAIL.system_versions,
			detailArgs: { wp: '7.1.2', php: '8.3' },
		} );
		// Positional placeholders, so a translator can reorder them.
		expect( sentence ).toBe( 'Running on WordPress 7.1.2 and PHP 8.3.' );
	} );

	it( 'interpolates the metric, value and advice for a vital', () => {
		const sentence = renderDetail( {
			detailKey: DETAIL.vital_poor,
			detailArgs: {
				labelKey: VITAL.lcp,
				value: '4000 ms',
				hintKey: VITAL.lcp,
				device: 'desktop',
			},
		} );
		// The label owns the metric name and the device; the sentence owns the
		// verdict, the value and the source. Neither repeats the other.
		expect(
			renderLabel( {
				labelKey: VITAL.lcp,
				detailArgs: { device: 'desktop' },
			} )
		).toBe( 'Loading (LCP) on desktop' );
		expect( sentence ).not.toContain( 'Loading (LCP)' );
		expect( sentence ).toContain( '4000 ms' );
		expect( sentence ).toContain( 'PageSpeed lab scan' );
		// The device is named **once**, in the label. The sentence carries the
		// source only, so the pair reads as a whole without repeating it.
		expect( sentence ).not.toContain( 'on desktop' );
		expect( sentence ).toContain( 'PageSpeed lab scan' );
		expect( sentence ).toContain(
			'How long the main content takes to appear.'
		);
	} );

	it( 'translates a metric name and its advice, not an English fragment', () => {
		// The mixed-language case: the model used to hand English into these
		// format strings, so a translator would have got "Loading (LCP)" as an
		// argument. Both now resolve through this layer.
		const sentence = renderDetail( {
			detailKey: DETAIL.vital_poor,
			detailArgs: {
				labelKey: VITAL.inp,
				value: '600 ms',
				hintKey: VITAL.inp,
				device: 'mobile',
			},
		} );
		expect( sentence ).toBe(
			'Poor: 600 ms (PageSpeed lab scan). How quickly the page reacts to a tap or click.'
		);
		expect( renderLabel( { labelKey: VITAL.cls } ) ).toBe(
			'Visual stability (CLS)'
		);
	} );

	it( 'renders nothing for an unknown key, not the key itself', () => {
		expect( renderDetail( { detailKey: 'no-such-key' } ) ).toBe( '' );
		expect( renderDetail( {} ) ).toBe( '' );
		expect( renderDetail( null ) ).toBe( '' );
	} );

	it( 'never emits an unresolved sprintf placeholder', () => {
		// A key whose mapping and args disagree would leak a literal %s into
		// the UI. Every key is rendered with a plausible argument set.
		const args = {
			stored: '1 MB',
			wp: '7.1.2',
			php: '8.3',
			label: 'Metric',
			value: '1 ms',
			hint: 'Advice.',
		};
		Object.values( DETAIL ).forEach( ( key ) => {
			expect(
				renderDetail( { detailKey: key, detailArgs: args } )
			).not.toMatch( /%[sd]/ );
		} );
	} );
} );
