/**
 * Overview status model.
 *
 * Derives the Overview's facts from data the plugin already exposes. It is a
 * pure module on purpose: no fetching, no React, no side effects, so the rules
 * that decide what the Overview claims are directly testable and cannot drift
 * away from what is rendered.
 *
 * ## Why there is no score
 *
 * A composite "92/100" would be decoration, not evidence. Every input the
 * plugin has is already weighted by something other than a made-up formula —
 * PageSpeed weights LCP far above TTFB, and "enabled" says nothing about
 * "working". So the model reports a small set of discrete, honest states and
 * never invents a number the user cannot trace back to a source.
 *
 * The vocabulary is deliberately narrow:
 *
 *   healthy         configured, and the plugin reports it working
 *   attention       configured, and something is measurably wrong
 *   not-configured  the feature is off; nothing is broken
 *   unavailable     the plugin cannot determine this right now
 *   unknown         there is genuinely no data to judge
 *
 * "Not configured" is never dressed up as a problem, and "unavailable" is never
 * dressed up as "healthy".
 *
 * @package
 */

/** The only states the Overview may claim. */
/**
 * The message keys this module can emit.
 *
 * The model deliberately carries no English prose. It returns a key plus its
 * arguments, and `SiteStatusCard` owns the copy, so the whole Overview can be
 * translated. That was the deal from the start — the model stays pure and
 * testable, and never imports `wp.i18n` — but the component side was never
 * finished, so every row rendered an English-only sentence.
 *
 * Exported so the copy map and this list can be checked against each other:
 * a key with no translation would otherwise render as a blank row.
 */
export const DETAIL = Object.freeze( {
	cache_unavailable: 'cache-unavailable',
	cache_unknown: 'cache-unknown',
	cache_off: 'cache-off',
	cache_unreadable: 'cache-unreadable',
	cache_empty: 'cache-empty',
	cache_active: 'cache-active',
	object_unavailable: 'object-unavailable',
	object_off: 'object-off',
	object_unknown: 'object-unknown',
	object_no_extension: 'object-no-extension',
	object_foreign_dropin: 'object-foreign-dropin',
	object_circuit_open: 'object-circuit-open',
	object_bypassed: 'object-bypassed',
	object_unreachable: 'object-unreachable',
	object_reachable: 'object-reachable',
	object_reachability_unknown: 'object-reachability-unknown',
	system_unavailable: 'system-unavailable',
	system_unknown: 'system-unknown',
	system_versions: 'system-versions',
	vital_unmeasured: 'vital-unmeasured',
	vital_good: 'vital-good',
	vital_attention: 'vital-attention',
	vital_poor: 'vital-poor',
} );

/**
 * The metrics the Overview reports.
 *
 * The metric's display name and its advice sentence are **English** and live in
 * `detailCopy.js`, not here. Interpolating them into a translatable format
 * string would produce a mixed-language sentence — a German translator getting
 * `"%1$s ist schlecht bei %2$s. %3$s"` with `%1$s` still reading
 * "Loading (LCP)". The model therefore hands out a key, and the copy layer
 * resolves name, advice and sentence together.
 */
export const ROW = Object.freeze( {
	pageCache: 'page-cache',
	objectCache: 'object-cache',
	compatibility: 'compatibility',
} );

export const VITAL = Object.freeze( {
	lcp: 'lcp',
	cls: 'cls',
	inp: 'inp',
} );

/**
 * The only states the Overview may claim.
 */
export const STATUS = Object.freeze( {
	HEALTHY: 'healthy',
	ATTENTION: 'attention',
	NOT_CONFIGURED: 'not-configured',
	UNAVAILABLE: 'unavailable',
	UNKNOWN: 'unknown',
} );

/** Every valid status, for callers that need to validate one. */
export const ALL_STATUSES = Object.freeze( Object.values( STATUS ) );

/**
 * Whether a state is one the user should be shown as needing action.
 *
 * @param {string} status A STATUS value.
 * @return {boolean} True when the state warrants attention.
 */
export const needsAttention = ( status ) => status === STATUS.ATTENTION;

/**
 * Coerce anything into a known status.
 *
 * Guards against a backend shape change turning into an undefined class name
 * in the UI, which is how a status badge silently disappears.
 *
 * @param {*}      value    Candidate status.
 * @param {string} fallback Status to use when the value is not recognised.
 * @return {string} A known STATUS value.
 */
export const coerce = ( value, fallback = STATUS.UNKNOWN ) =>
	ALL_STATUSES.includes( value ) ? value : fallback;

/**
 * Read a boolean flag that may be absent, true, false, or a string.
 *
 * The REST layer returns real booleans, but a filter can hand back a string,
 * and treating `"false"` as truthy is how a "disabled" feature gets reported
 * as healthy.
 *
 * @param {*} value Candidate value.
 * @return {boolean|null} Tri-state: true, false, or null when unreported.
 */
const triState = ( value ) => {
	if ( typeof value === 'boolean' ) {
		return value;
	}
	if ( value === 'true' || value === 1 || value === '1' ) {
		return true;
	}
	if ( value === 'false' || value === 0 || value === '0' ) {
		return false;
	}
	return null;
};

/**
 * The page-cache check.
 *
 * Reads the same `cache_settings` the rest of the admin uses. When the feature
 * is off this says "not configured" rather than implying a problem.
 *
 * @param {Object} settings  `cache_settings` slice, or undefined.
 * @param {string} cacheSize The measured cache size as the plugin renders it,
 *                           e.g. '14 MB', '0 B' or 'N/A', or undefined.
 * @return {Object} A status row.
 */
export const deriveCacheStatus = ( settings, cacheSize ) => {
	if ( ! settings || typeof settings !== 'object' ) {
		return {
			id: 'page-cache',
			labelKey: ROW.pageCache,
			status: coerce( STATUS.UNAVAILABLE ),
			detailKey: DETAIL.cache_unavailable,
		};
	}
	// `! settings.enableCache` treated an *absent* key as "off". A payload with
	// no `enableCache` at all must claim neither state.
	const cacheEnabled = triState( settings.enableCache );
	if ( cacheEnabled === null ) {
		// Neither on nor off: claiming either would be a guess, and a green
		// "Working" here is exactly the false claim this row exists to avoid.
		return {
			id: 'page-cache',
			labelKey: ROW.pageCache,
			status: STATUS.UNKNOWN,
			detailKey: DETAIL.cache_unknown,
		};
	}
	if ( cacheEnabled === false ) {
		return {
			id: 'page-cache',
			labelKey: ROW.pageCache,
			status: STATUS.NOT_CONFIGURED,
			detailKey: DETAIL.cache_off,
		};
	}

	// The `cache_settings` slice carries configuration only and never a
	// "is it serving" flag. The evidence is the cache **statistics** the plugin
	// already renders into `wppoSettings` from `Cache::get_cache_stats()`,
	// which is why this is not a restatement of `enableCache`: a cache that is
	// switched on but storing nothing is a different situation from one serving
	// pages.
	// A plain string, deliberately. This parameter once accepted an object and
	// read `stats.cacheStats`, while `buildStatusModel` passed the raw string —
	// so production read `undefined` and this row was permanently Unknown, and
	// the unit tests passed because they used the object shape production never
	// took. A second green suite over broken production code.
	const stored = typeof cacheSize === 'string' ? cacheSize.trim() : '';

	// `get_cache_stats()` reports the literal string "N/A" when it cannot
	// measure, which is not the same as measuring zero.
	if ( ! stored || stored === 'N/A' ) {
		return {
			id: 'page-cache',
			labelKey: ROW.pageCache,
			status: STATUS.UNKNOWN,
			detailKey: DETAIL.cache_unreadable,
		};
	}
	// A measured zero is a real fact, but not a fault: a freshly cleared cache
	// legitimately holds nothing, and the next request repopulates it.
	if ( /^0(?:\.0+)?\s*(?:b|bytes?)$/i.test( stored ) ) {
		return {
			id: 'page-cache',
			labelKey: ROW.pageCache,
			status: STATUS.NOT_CONFIGURED,
			detailKey: DETAIL.cache_empty,
		};
	}
	return {
		id: 'page-cache',
		labelKey: ROW.pageCache,
		status: STATUS.HEALTHY,
		detailKey: DETAIL.cache_active,
		detailArgs: { stored },
	};
};

/**
 * The object-cache check.
 *
 * Object cache is optional infrastructure. A site without it is not unhealthy,
 * so this row reads "not configured" rather than "attention" — while a
 * *configured* object cache that is not reachable genuinely is a problem.
 *
 * @param {Object} state Response from the `object_cache` action, or undefined.
 * @return {Object} A status row.
 */
export const deriveObjectCacheStatus = ( state ) => {
	if ( ! state || typeof state !== 'object' ) {
		return {
			id: 'object-cache',
			labelKey: ROW.objectCache,
			status: coerce( STATUS.UNAVAILABLE ),
			detailKey: DETAIL.object_unavailable,
		};
	}

	// Every flag goes through `triState`. An independent review reproduced
	// `{ enabled: 'false', redis_reachable: true }` rendering as a *working*
	// object cache, because a truthiness check treats the string "false" as
	// true. A filter can return a string, and that is exactly how a disabled
	// feature gets reported as working.
	const enabled = triState( state.enabled );
	if ( enabled === false ) {
		return {
			id: 'object-cache',
			labelKey: ROW.objectCache,
			status: STATUS.NOT_CONFIGURED,
			detailKey: DETAIL.object_off,
		};
	}
	if ( enabled === null ) {
		// Neither on nor off: say so, rather than quietly picking one.
		return {
			id: 'object-cache',
			labelKey: ROW.objectCache,
			status: STATUS.UNKNOWN,
			detailKey: DETAIL.object_unknown,
		};
	}

	// Order matters here, and it is not arbitrary.
	//
	// `Object_Cache::get_status()` returns **early** when the Redis extension is
	// missing, so `redis_reachable` is *always* false alongside `redis_missing`.
	// Testing reachability first therefore reported "the server is not
	// reachable" when the true cause was a missing extension, and made the
	// specific branch unreachable dead code.
	if ( triState( state.redis_missing ) === true ) {
		return {
			id: 'object-cache',
			labelKey: ROW.objectCache,
			status: STATUS.ATTENTION,
			detailKey: DETAIL.object_no_extension,
		};
	}
	if ( triState( state.foreign_dropin ) === true ) {
		return {
			id: 'object-cache',
			labelKey: ROW.objectCache,
			status: STATUS.ATTENTION,
			detailKey: DETAIL.object_foreign_dropin,
		};
	}

	// The endpoint also reports the outage-bypass flag and the circuit breaker.
	// When either is set the cache is deliberately or currently not in use, so
	// calling it working would be the most misleading claim on the page — a
	// Redis the plugin has stopped trusting, reported as healthy. Reproduced
	// live with `{ bypassed: true, circuit_open: true }`.
	if ( triState( state.circuit_open ) === true ) {
		return {
			id: 'object-cache',
			labelKey: ROW.objectCache,
			status: STATUS.ATTENTION,
			detailKey: DETAIL.object_circuit_open,
		};
	}
	if ( triState( state.bypassed ) === true ) {
		return {
			id: 'object-cache',
			labelKey: ROW.objectCache,
			status: STATUS.ATTENTION,
			detailKey: DETAIL.object_bypassed,
		};
	}

	// The endpoint reports `redis_reachable`; `reachable` is accepted so a
	// filtered or older payload still works. Reading only `reachable` left the
	// row permanently "Unknown" against the real backend.
	const reachable = triState( state.redis_reachable ?? state.reachable );
	if ( reachable === false ) {
		return {
			id: 'object-cache',
			labelKey: ROW.objectCache,
			status: STATUS.ATTENTION,
			detailKey: DETAIL.object_unreachable,
		};
	}
	// Reachable is the whole claim. If the backend never reported it we do not
	// know whether the cache is doing anything, and "healthy" would be a guess
	// dressed as a fact.
	if ( reachable === true ) {
		return {
			id: 'object-cache',
			labelKey: ROW.objectCache,
			status: STATUS.HEALTHY,
			detailKey: DETAIL.object_reachable,
		};
	}
	return {
		id: 'object-cache',
		labelKey: ROW.objectCache,
		status: STATUS.UNKNOWN,
		detailKey: DETAIL.object_reachability_unknown,
	};
};

/**
 * The compatibility check.
 *
 * Uses the versions the plugin itself reports rather than any bundled
 * requirement list, so the row cannot disagree with what the plugin believes.
 *
 * @param {Object} info Response from the `system_info` action, or undefined.
 * @return {Object} A status row.
 */
export const deriveCompatibilityStatus = ( info ) => {
	if ( ! info || typeof info !== 'object' ) {
		return {
			id: 'compatibility',
			labelKey: ROW.compatibility,
			status: coerce( STATUS.UNAVAILABLE ),
			detailKey: DETAIL.system_unavailable,
		};
	}
	// The real `system_info` response nests these: `php.version` and
	// `wordpress.version`. The flat names are accepted too, because a filter or
	// an older endpoint could hand those back — but the nested shape is what
	// the plugin actually returns, and reading only the flat names is what made
	// this row say "did not report the version" on a working site.
	// A version is only a version if it is a non-empty string or a positive
	// number. `String()` alone turned `false` and `0` into the literals "false"
	// and "0", so `{ version: false }` produced a *working* "Running on
	// WordPress false and PHP 0." row. Booleans are rejected explicitly,
	// because the same discipline is applied to every other flag here.
	const readVersion = ( value ) => {
		if ( typeof value === 'string' ) {
			return value.trim();
		}
		if (
			typeof value === 'number' &&
			Number.isFinite( value ) &&
			value > 0
		) {
			return String( value );
		}
		return '';
	};
	const php = readVersion( info?.php?.version ?? info?.php_version );
	const wp = readVersion( info?.wordpress?.version ?? info?.wp_version );
	// `||` is load-bearing: with `&&`, a payload carrying only one version was
	// treated as complete and the row claimed a version never reported —
	// "Running on WordPress 7.1.2 and PHP ." under a green badge. An
	// independent review found that mutation survived 108/108.
	if ( ! php || ! wp ) {
		return {
			id: 'compatibility',
			labelKey: ROW.compatibility,
			status: coerce( STATUS.UNKNOWN ),
			detailKey: DETAIL.system_unknown,
		};
	}
	return {
		id: 'compatibility',
		labelKey: ROW.compatibility,
		status: STATUS.HEALTHY,
		detailKey: DETAIL.system_versions,
		detailArgs: { wp, php },
	};
};

/**
 * Turn real-user Core Web Vitals into status rows.
 *
 * Each vital has its own published threshold, so each becomes its own row
 * rather than being averaged into a single number. A metric that has not been
 * measured yet is "unknown" — never "healthy", and never "attention".
 *
 * @param {Object} vitals `{ lcp, cls, inp }` in milliseconds (CLS unitless).
 * @return {Array<Object>} Status rows.
 */
export const deriveVitalsStatus = ( vitals ) => {
	if ( ! vitals || typeof vitals !== 'object' ) {
		return [];
	}
	const measures = [
		{
			id: 'vital-lcp',
			key: 'lcp',
			labelKey: VITAL.lcp,
			good: 2500,
			poor: 4000,
			hintKey: VITAL.lcp,
		},
		{
			id: 'vital-cls',
			key: 'cls',
			labelKey: VITAL.cls,
			good: 0.1,
			poor: 0.25,
			hintKey: VITAL.cls,
		},
		{
			id: 'vital-inp',
			key: 'inp',
			labelKey: VITAL.inp,
			good: 200,
			poor: 500,
			hintKey: VITAL.inp,
		},
	];

	// `in`, not `!== undefined`: a key that exists with an undefined value is a
	// metric the source knows about but has not measured, and the user should be
	// told so. Switching to `!== undefined` silently drops the row instead,
	// which reads as "nothing to say" rather than "not measured yet". An
	// independent review found that mutation survived 108/108.
	return measures
		.filter( ( measure ) => measure.key in vitals )
		.map( ( measure ) => {
			// `Number( null )`, `Number( '' )`, `Number( [] )` and
			// `Number( false )` are all 0, and 0 <= 2500, so an unreported vital
			// scored a *perfect* result. The review reproduced three green
			// "good at 0 ms" rows on a site with no data at all. Only a genuine
			// finite, non-negative number counts as a measurement.
			const candidate = vitals[ measure.key ];
			const isNumber =
				typeof candidate === 'number' ||
				( typeof candidate === 'string' && candidate.trim() !== '' );
			const raw = isNumber ? Number( candidate ) : Number.NaN;
			// Zero is a real measurement for CLS — the live store holds seven
			// true zeros — but not for a latency: "good at 0 ms" is not a
			// plausible LCP, it is a missing reading wearing a number.
			const zeroIsValid = 'cls' === measure.key;
			if (
				! Number.isFinite( raw ) ||
				raw < 0 ||
				( 0 === raw && ! zeroIsValid ) ||
				// A page cannot take a full day to load. The bound lives here as
				// well as in the summariser: `deriveVitalsStatus` is also reachable
				// with a payload the summariser never saw, and an unbounded value
				// renders as "poor at 86400000 ms" with no separator.
				86400000 <= raw
			) {
				return {
					id: measure.id,
					labelKey: measure.labelKey,
					status: STATUS.UNKNOWN,
					detailKey: DETAIL.vital_unmeasured,
					detailArgs: { hintKey: measure.hintKey },
				};
			}
			// The number *judged* is the number *shown*.
			//
			// The verdict compares `raw`, but the message used to format a
			// rounded value, so at the boundary the two disagreed: an LCP of
			// 2500.4 rendered as "could be better (2500 ms)" and one of 2499.6 as
			// "is good at 2500 ms" — the same string with opposite verdicts, and
			// "2500" is the published *good* threshold. On a page whose whole
			// premise is that the numbers it shows are the numbers it judged,
			// that is the one place it was not.
			const rounded =
				measure.key === 'cls'
					? Number( raw.toFixed( 3 ) )
					: Math.round( raw );
			const value =
				measure.key === 'cls'
					? rounded.toFixed( 3 )
					: `${ rounded } ms`;
			if ( rounded <= measure.good ) {
				return {
					id: measure.id,
					labelKey: measure.labelKey,
					status: STATUS.HEALTHY,
					detailKey: DETAIL.vital_good,
					detailArgs: { labelKey: measure.labelKey, value },
				};
			}
			if ( rounded <= measure.poor ) {
				return {
					id: measure.id,
					labelKey: measure.labelKey,
					status: STATUS.ATTENTION,
					detailKey: DETAIL.vital_attention,
					detailArgs: {
						labelKey: measure.labelKey,
						value,
						hintKey: measure.hintKey,
					},
				};
			}
			return {
				id: measure.id,
				labelKey: measure.labelKey,
				status: STATUS.ATTENTION,
				detailKey: DETAIL.vital_poor,
				detailArgs: {
					labelKey: measure.labelKey,
					value,
					hintKey: measure.hintKey,
				},
			};
		} );
};

/**
 * The overall verdict.
 *
 * Deliberately conservative and explainable: a single row needing attention
 * makes the whole Overview "needs attention", because the point of the page is
 * "what should I do next". Rows that are merely off do not drag the verdict
 * down — turning a feature off is a choice, not a fault.
 *
 * @param {Array} rows Rows produced by the derive* functions.
 * @return {string} A STATUS value.
 */
export const deriveOverallStatus = ( rows ) => {
	if ( ! Array.isArray( rows ) || ! rows.length ) {
		return coerce( STATUS.UNAVAILABLE );
	}
	if ( rows.some( ( row ) => row.status === STATUS.ATTENTION ) ) {
		return STATUS.ATTENTION;
	}
	// The verdict answers "is something wrong", not "how much is turned on".
	// A site with a working page cache and no object cache is not unhealthy.
	const reportable = rows.filter(
		( row ) => row.status !== STATUS.UNAVAILABLE
	);
	if ( reportable.some( ( row ) => row.status === STATUS.HEALTHY ) ) {
		return STATUS.HEALTHY;
	}
	if ( reportable.some( ( row ) => row.status === STATUS.UNKNOWN ) ) {
		return STATUS.UNKNOWN;
	}
	return reportable.length ? STATUS.NOT_CONFIGURED : STATUS.UNAVAILABLE;
};

/**
 * Build the Overview status model from one payload.
 *
 * A single entry point, so a card can never be handed a subset of the facts
 * and quietly report a different verdict from the page as a whole.
 *
 * @param {Object} payload Collected authoritative data.
 * @return {{rows: Array, vitals: Array, overall: string}} The status model.
 */
export const buildStatusModel = ( payload = {} ) => {
	const rows = [
		deriveCacheStatus( payload.cacheSettings, payload.cacheStats ),
		deriveObjectCacheStatus( payload.objectCache ),
		deriveCompatibilityStatus( payload.systemInfo ),
	];
	const vitals = deriveVitalsStatus( payload.vitals );
	const all = [ ...rows, ...vitals ];
	return { rows: all, vitals, overall: coerce( deriveOverallStatus( all ) ) };
};
