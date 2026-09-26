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
 * @param {Object} settings `cache_settings` slice, or undefined.
 * @return {Object} A status row.
 */
export const deriveCacheStatus = ( settings ) => {
	if ( ! settings || typeof settings !== 'object' ) {
		return {
			id: 'page-cache',
			label: 'Page cache',
			status: coerce( STATUS.UNAVAILABLE ),
			detail: 'Cache state is not available right now.',
		};
	}
	if ( ! settings.enableCache ) {
		return {
			id: 'page-cache',
			label: 'Page cache',
			status: STATUS.NOT_CONFIGURED,
			detail: 'Page cache is turned off. Turning it on is usually the single biggest speed win.',
		};
	}

	// The cache_settings slice the plugin injects carries configuration only —
	// `enableCache`, `cacheLife`, `ttlOverrides` and so on — and never a
	// "is it currently serving" flag. So a positive claim needs a backend that
	// actually supplies one; when none does, this row says Unknown rather than
	// guessing. Guessing here is how a dashboard tells a user their cache is
	// working when it is not.
	const running = triState(
		settings.cache_enabled ?? settings.cacheEnabled ?? settings.active
	);
	if ( running === true ) {
		return {
			id: 'page-cache',
			label: 'Page cache',
			status: STATUS.HEALTHY,
			detail: 'Page cache is on and serving cached pages.',
		};
	}
	if ( running === false ) {
		return {
			id: 'page-cache',
			label: 'Page cache',
			status: STATUS.ATTENTION,
			detail: 'Page cache is switched on but is not serving cached pages. Check the cache settings under Speed.',
		};
	}
	// Enabled, but the backend did not report a running state. Honest, neither
	// optimistic nor an invented problem.
	return {
		id: 'page-cache',
		label: 'Page cache',
		status: STATUS.UNKNOWN,
		detail: 'Page cache is switched on. This page cannot confirm it is serving — open Speed to check the cache status.',
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
			label: 'Object cache',
			status: coerce( STATUS.UNAVAILABLE ),
			detail: 'Object cache state is not available right now.',
		};
	}
	if ( ! state.enabled ) {
		return {
			id: 'object-cache',
			label: 'Object cache',
			status: STATUS.NOT_CONFIGURED,
			detail: 'Object cache (Redis or Memcached) is off. Useful for busy or dynamic sites; not needed everywhere.',
		};
	}
	if ( state.foreign_dropin ) {
		return {
			id: 'object-cache',
			label: 'Object cache',
			status: STATUS.ATTENTION,
			detail: 'Another plugin installed the object-cache drop-in, so this one is not active.',
		};
	}
	// The endpoint reports `redis_reachable`; `reachable` is accepted so a
	// filtered or older payload still works. Reading only `reachable` left the
	// row permanently "Unknown" against the real backend.
	const reachable = triState( state.redis_reachable ?? state.reachable );
	if ( reachable === false ) {
		return {
			id: 'object-cache',
			label: 'Object cache',
			status: STATUS.ATTENTION,
			detail: 'Object cache is enabled but the server is not reachable, so it is not speeding anything up.',
		};
	}
	// Reachable is the whole claim. If the backend never reported it we do not
	// know whether the cache is doing anything, and "healthy" would be a guess
	// dressed as a fact.
	if ( reachable === true ) {
		return {
			id: 'object-cache',
			label: 'Object cache',
			status: STATUS.HEALTHY,
			detail: 'Object cache is enabled and the server is reachable.',
		};
	}
	return {
		id: 'object-cache',
		label: 'Object cache',
		status: STATUS.UNKNOWN,
		detail: 'Object cache is enabled, but the plugin has not reported whether the server is reachable.',
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
			label: 'Compatibility',
			status: coerce( STATUS.UNAVAILABLE ),
			detail: 'Server details are not available right now.',
		};
	}
	// The real `system_info` response nests these: `php.version` and
	// `wordpress.version`. The flat names are accepted too, because a filter or
	// an older endpoint could hand those back — but the nested shape is what
	// the plugin actually returns, and reading only the flat names is what made
	// this row say "did not report the version" on a working site.
	const php = String( info?.php?.version ?? info?.php_version ?? '' );
	const wp = String( info?.wordpress?.version ?? info?.wp_version ?? '' );
	if ( ! php || ! wp ) {
		return {
			id: 'compatibility',
			label: 'Compatibility',
			status: coerce( STATUS.UNKNOWN ),
			detail: 'The plugin did not report the PHP or WordPress version.',
		};
	}
	return {
		id: 'compatibility',
		label: 'Compatibility',
		status: STATUS.HEALTHY,
		detail: `Running on WordPress ${ wp } and PHP ${ php }.`,
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
			label: 'Loading (LCP)',
			good: 2500,
			poor: 4000,
			hint: 'How long the main content takes to appear.',
		},
		{
			id: 'vital-cls',
			key: 'cls',
			label: 'Visual stability (CLS)',
			good: 0.1,
			poor: 0.25,
			hint: 'How much the page jumps around while loading.',
		},
		{
			id: 'vital-inp',
			key: 'inp',
			label: 'Responsiveness (INP)',
			good: 200,
			poor: 500,
			hint: 'How quickly the page reacts to a tap or click.',
		},
	];

	return measures
		.filter( ( measure ) => measure.key in vitals )
		.map( ( measure ) => {
			const raw = Number( vitals[ measure.key ] );
			if ( ! Number.isFinite( raw ) || raw < 0 ) {
				return {
					id: measure.id,
					label: measure.label,
					status: STATUS.UNKNOWN,
					detail: `No real-user data yet. ${ measure.hint }`,
				};
			}
			const value =
				measure.key === 'cls'
					? raw.toFixed( 3 )
					: `${ Math.round( raw ) } ms`;
			if ( raw <= measure.good ) {
				return {
					id: measure.id,
					label: measure.label,
					status: STATUS.HEALTHY,
					detail: `${ measure.label } is good at ${ value }.`,
				};
			}
			if ( raw <= measure.poor ) {
				return {
					id: measure.id,
					label: measure.label,
					status: STATUS.ATTENTION,
					detail: `${ measure.label } could be better (${ value }). ${ measure.hint }`,
				};
			}
			return {
				id: measure.id,
				label: measure.label,
				status: STATUS.ATTENTION,
				detail: `${ measure.label } is poor at ${ value }. ${ measure.hint }`,
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
		deriveCacheStatus( payload.cacheSettings ),
		deriveObjectCacheStatus( payload.objectCache ),
		deriveCompatibilityStatus( payload.systemInfo ),
	];
	const vitals = deriveVitalsStatus( payload.vitals );
	const all = [ ...rows, ...vitals ];
	return { rows: all, vitals, overall: coerce( deriveOverallStatus( all ) ) };
};
