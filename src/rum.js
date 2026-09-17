/**
 * Real-user Web Vitals beacon.
 *
 * Loaded via the `rum_enabled` setting. Reads the inline `window.wppoRum`
 * config (apiUrl, token, path) baked into the page output, collects Core Web
 * Vitals with PerformanceObserver and sends a single aggregated beacon after
 * the page settles or on pagehide.
 *
 * Security note: `window.wppoRum.token` is page-visible by design, so forged
 * beacons replaying a harvested token cannot be stopped client-side and no
 * client-side throttle is attempted. The real defenses are server-side and
 * authoritative: per-path token validation plus per-IP rate limiting in
 * `RUM` (see includes/class-rum.php). Client-side magnitude caps
 * (sanitizeRumValues) only keep extreme observer values out of the payload;
 * never rely on them for abuse prevention. Consider rotating the beacon
 * token periodically server-side.
 *
 * The collector is a plain ES module (no dependencies) so it can be served
 * from the static build directory on cached pages.
 */

/**
 * Classify a device as mobile/desktop from its physical screen width.
 *
 * Resize-stable: the physical screen width does not change when a desktop
 * user narrows their browser window, so a narrowed desktop is not
 * misclassified as mobile. The viewport width is used only when the screen
 * width is unavailable. Tablets in the ~768–1024px band bucket as mobile
 * (coarse 1024px tradeoff documented; narrow the cutoff to 768px only if
 * tablet traffic should count as desktop).
 *
 * @since 2.0.0
 * @param {number} screenWidth   `window.screen.width` (0 when unavailable).
 * @param {number} viewportWidth `window.innerWidth` (0 when unavailable).
 * @return {boolean|null} True when mobile, false when desktop, null when unknown.
 */
export const classifyDeviceWidth = ( screenWidth, viewportWidth ) => {
	const screen = Number( screenWidth );
	let width = 0;
	if ( Number.isFinite( screen ) && screen > 0 ) {
		width = screen;
	} else {
		const viewport = Number( viewportWidth );
		if ( Number.isFinite( viewport ) && viewport > 0 ) {
			width = viewport;
		}
	}
	if ( width <= 0 ) {
		return null;
	}
	return width <= 1024;
};

/**
 * Allowlisted effective connection types for RUM segmentation.
 *
 * Mirrors the server-side allowlist in `RUM::sanitize_sample()` — any other
 * value is omitted client-side so unknown/slow-2g variants never pollute
 * the stored aggregates.
 *
 * @since NEXT
 * @type {string[]}
 */
export const RUM_ALLOWED_CONNECTIONS = [ 'slow-2g', '2g', '3g', '4g' ];

/**
 * Normalize a raw effective connection type to the segmentation allowlist.
 *
 * Fail-open: returns null for missing/invalid values so callers omit the
 * field and the numeric beacon path is unchanged.
 *
 * @since NEXT
 * @param {*} raw Raw `navigator.connection.effectiveType` value.
 * @return {string|null} Allowlisted connection type or null.
 */
export const classifyConnectionType = ( raw ) => {
	if ( typeof raw !== 'string' ) {
		return null;
	}
	const normalized = raw.toLowerCase().trim().slice( 0, 16 );
	return RUM_ALLOWED_CONNECTIONS.indexOf( normalized ) !== -1
		? normalized
		: null;
};

/**
 * Default RUM beacon sample rate (percent of page views sampled).
 *
 * 100 keeps the pre-sampling behavior verbatim (every page view sends).
 * Mirrors `RUM::RUM_SAMPLE_RATE_DEFAULT` in includes/class-rum.php.
 *
 * @since NEXT
 * @type {number}
 */
export const RUM_DEFAULT_SAMPLE_RATE = 100;

/**
 * Sampling decision for one page view (lossy hint only, no PII).
 *
 * Keeps about `rate` percent of page views using a uniform
 * `Math.random()` roll. A rate of 100 (or any missing/invalid value)
 * always sends (fail-open to unsampled current behavior). An explicit
 * `randomValue` makes the decision deterministic for tests; otherwise
 * `Math.random()` is used. Never throws: any failure sends.
 *
 * Boundary alignment: `roll * 100 <= rate` for a continuous roll in
 * [0, 1) is the float-domain equivalent of the server gate
 * (`roll <= rate` for an integer roll in 1–100 in
 * `RUM::should_keep_sample()`) — both keep about `rate` percent, and a
 * roll exactly on the boundary is kept on both sides.
 *
 * Compounding note: the server re-rolls independently at the same
 * effective rate (`RUM::store_sample()`), so end-to-end stored volume
 * is approximately rate²/100. This client gate is a best-effort
 * bandwidth saver; the server gate stays authoritative.
 *
 * @since NEXT
 * @param {*} rate        Configured sample rate (1–100) from `window.wppoRum.sampleRate`.
 * @param {*} randomValue Optional deterministic roll in [0, 1).
 * @return {boolean} True when the beacon should be sent.
 */
export const shouldSendSample = ( rate, randomValue ) => {
	try {
		let parsed = Number( rate );
		if ( ! Number.isFinite( parsed ) ) {
			parsed = RUM_DEFAULT_SAMPLE_RATE;
		}
		const normalized = Math.floor( parsed );
		const clamped =
			normalized >= 1 && normalized <= 100
				? normalized
				: RUM_DEFAULT_SAMPLE_RATE;
		if ( clamped >= 100 ) {
			return true;
		}
		const roll =
			typeof randomValue === 'number' ? randomValue : Math.random();
		if ( ! Number.isFinite( roll ) ) {
			return true;
		}
		return roll * 100 <= clamped;
	} catch {
		return true;
	}
};

/**
 * Upper bound (ms) accepted for time-based Web Vitals metrics before the
 * beacon is sent. Mirrors the server-side 0–60000 range enforced by
 * `RUM::sanitize_sample()` — extreme observer values (or forged beacons
 * replaying the page-visible token) are dropped client-side so they cannot
 * pollute the stored aggregates. Server-side per-IP rate limiting and
 * per-path token validation remain authoritative.
 *
 * @since 2.0.0
 * @type {number}
 */
export const RUM_MAX_METRIC_MS = 60000;

/**
 * Maximum length (chars) for the LCP element selector attribution.
 *
 * Mirrors `RUM::LCP_SELECTOR_MAX_LENGTH` in includes/class-rum.php.
 *
 * @since NEXT
 * @type {number}
 */
export const RUM_MAX_LCP_SELECTOR_LENGTH = 256;

/**
 * Maximum slow-resource entries attached to one beacon.
 *
 * Mirrors `RUM::SLOW_RESOURCES_MAX_COUNT` in includes/class-rum.php.
 *
 * @since NEXT
 * @type {number}
 */
export const RUM_MAX_SLOW_RESOURCES = 5;

/**
 * Slow-resource duration threshold (ms): only entries slower than this
 * are considered for the audit. Mirrors the server-side clamp range.
 *
 * @since NEXT
 * @type {number}
 */
export const RUM_SLOW_RESOURCE_THRESHOLD_MS = 300;

/**
 * Allowlisted resource initiator types for the slow-resource audit.
 *
 * Mirrors `RUM::ALLOWED_SLOW_RESOURCE_TYPES` in includes/class-rum.php.
 *
 * @since NEXT
 * @type {string[]}
 */
export const RUM_ALLOWED_RESOURCE_TYPES = [
	'img',
	'script',
	'css',
	'link',
	'font',
	'fetch',
	'xmlhttprequest',
	'iframe',
];

/**
 * Derive a compact CSS selector for the LCP element (fail-open).
 *
 * Privacy/size guard: only tag + #id or first class is used, never xpath
 * or outerHTML. Returns null when the element is unavailable so callers
 * omit the field and the numeric beacon path is unchanged.
 *
 * @since NEXT
 * @param {*} element LCP entry element (`last.element`).
 * @return {string|null} Compact selector (<=256 chars) or null.
 */
export const deriveLcpSelector = ( element ) => {
	try {
		if ( ! element || typeof element.tagName !== 'string' ) {
			return null;
		}
		const tag = element.tagName.toLowerCase().slice( 0, 32 );
		if ( ! /^[a-z][a-z0-9-]*$/.test( tag ) ) {
			return null;
		}
		let selector = tag;
		const id = typeof element.id === 'string' ? element.id.trim() : '';
		if ( id && /^[a-z0-9_-]{1,64}$/i.test( id ) ) {
			selector += '#' + id.slice( 0, 64 );
			return selector.slice( 0, RUM_MAX_LCP_SELECTOR_LENGTH );
		}
		let firstClass = '';
		try {
			if (
				element.classList &&
				typeof element.classList.length === 'number' &&
				element.classList.length > 0
			) {
				firstClass = String( element.classList[ 0 ] || '' ).trim();
			} else if ( typeof element.className === 'string' ) {
				firstClass = element.className.split( /\s+/ )[ 0 ] || '';
			}
		} catch {
			firstClass = '';
		}
		if ( firstClass && /^[a-z0-9_-]{1,64}$/i.test( firstClass ) ) {
			selector += '.' + firstClass.slice( 0, 64 );
		}
		return selector.slice( 0, RUM_MAX_LCP_SELECTOR_LENGTH );
	} catch {
		return null;
	}
};

/**
 * Normalize one slow-resource entry to the beacon shape.
 *
 * Fail-open: returns null for malformed entries so callers drop them.
 *
 * @since NEXT
 * @param {*} entry Raw resource-timing entry.
 * @return {Object|null} Shaped `{name, type, duration}` entry or null.
 */
export const sanitizeSlowResourceEntry = ( entry ) => {
	try {
		if ( ! entry || typeof entry !== 'object' ) {
			return null;
		}
		const name = entry.name;
		if (
			typeof name !== 'string' ||
			! name ||
			name.length > 2048 ||
			! (
				name.indexOf( 'http://' ) === 0 ||
				name.indexOf( 'https://' ) === 0 ||
				name.charAt( 0 ) === '/'
			)
		) {
			return null;
		}
		const rawType = entry.initiatorType ?? entry.type;
		const type =
			typeof rawType === 'string'
				? rawType.toLowerCase().trim().slice( 0, 16 )
				: '';
		if ( RUM_ALLOWED_RESOURCE_TYPES.indexOf( type ) === -1 ) {
			return null;
		}
		const duration = Number( entry.duration );
		if (
			! Number.isFinite( duration ) ||
			duration < 0 ||
			duration > RUM_MAX_METRIC_MS
		) {
			return null;
		}
		return {
			name: name.slice( 0, 2048 ),
			type,
			duration: Math.round( duration ),
		};
	} catch {
		return null;
	}
};

/**
 * Collect the slowest sub-resources via Resource Timing (fail-open).
 *
 * Guarded on `performance.getEntriesByType`; entries slower than
 * `RUM_SLOW_RESOURCE_THRESHOLD_MS` are kept (or the top-3 slowest when
 * none cross the threshold), capped at `RUM_MAX_SLOW_RESOURCES`, with a
 * ~1.5KB JSON budget check that drops the fastest-first (keeping the
 * slowest) on overflow.
 * Returns an empty array when the API is absent so callers omit the field.
 *
 * @since NEXT
 * @return {Object[]} Shaped slow-resource entries (possibly empty).
 */
export const collectSlowResources = () => {
	try {
		if (
			typeof performance === 'undefined' ||
			typeof performance.getEntriesByType !== 'function'
		) {
			return [];
		}
		const entries = performance.getEntriesByType( 'resource' );
		if ( ! entries || typeof entries.length !== 'number' ) {
			return [];
		}
		const shaped = [];
		for ( const entry of entries ) {
			const clean = sanitizeSlowResourceEntry( entry );
			if ( clean ) {
				shaped.push( clean );
			}
		}
		if ( ! shaped.length ) {
			return [];
		}
		shaped.sort( ( a, b ) => b.duration - a.duration );
		let candidates = shaped.filter(
			( item ) => item.duration > RUM_SLOW_RESOURCE_THRESHOLD_MS
		);
		if ( ! candidates.length ) {
			candidates = shaped.slice( 0, 3 );
		}
		candidates = candidates.slice( 0, RUM_MAX_SLOW_RESOURCES );
		// Payload budget: keep JSON under ~1.5KB, drop fastest-first (keep slowest) on overflow.
		let encoded = '';
		try {
			encoded = JSON.stringify( candidates );
		} catch {
			return [];
		}
		while ( encoded.length > 1536 && candidates.length > 1 ) {
			candidates = candidates.slice( 0, candidates.length - 1 );
			try {
				encoded = JSON.stringify( candidates );
			} catch {
				return [];
			}
		}
		return candidates;
	} catch {
		return [];
	}
};

/**
 * Drop out-of-range metric values before the beacon is sent.
 *
 * Time metrics (ttfb/fcp/lcp/inp) must be finite numbers in 0–60000ms;
 * cls must be a finite number in 0–1. lcpUrl must use the same policy as
 * the PerformanceObserver collector (http(s):// or leading '/' and
 * ≤2048 chars). Only known metric keys are kept; unknown keys are dropped.
 *
 * @since 2.0.0
 * @param {Object} raw Collected metric values.
 * @return {Object} Sanitized copy containing only in-range metrics.
 */
export const sanitizeRumValues = ( raw ) => {
	const clean = {};
	if ( ! raw || typeof raw !== 'object' ) {
		return clean;
	}
	for ( const key of [ 'ttfb', 'fcp', 'lcp', 'inp' ] ) {
		const value = raw[ key ];
		if (
			typeof value === 'number' &&
			Number.isFinite( value ) &&
			value >= 0 &&
			value <= RUM_MAX_METRIC_MS
		) {
			clean[ key ] = value;
		}
	}
	const cls = raw.cls;
	if (
		typeof cls === 'number' &&
		Number.isFinite( cls ) &&
		cls >= 0 &&
		cls <= 1
	) {
		clean.cls = cls;
	}
	if (
		typeof raw.lcpUrl === 'string' &&
		raw.lcpUrl &&
		raw.lcpUrl.length <= 2048 &&
		( raw.lcpUrl.indexOf( 'http://' ) === 0 ||
			raw.lcpUrl.indexOf( 'https://' ) === 0 ||
			raw.lcpUrl.charAt( 0 ) === '/' )
	) {
		clean.lcpUrl = raw.lcpUrl;
	}
	// LCP element selector attribution (issue #1311): compact
	// `tag#id`/`.class` selector, <=256 chars, strict charset. Omitted
	// when absent so the p75-only path is unchanged.
	if (
		typeof raw.lcpSelector === 'string' &&
		raw.lcpSelector &&
		raw.lcpSelector.length <= RUM_MAX_LCP_SELECTOR_LENGTH &&
		/^[a-z0-9#._\-\s:~+[\]=']{1,256}$/i.test( raw.lcpSelector ) &&
		raw.lcpSelector.indexOf( '<' ) === -1 &&
		raw.lcpSelector.indexOf( '>' ) === -1 &&
		raw.lcpSelector.indexOf( '"' ) === -1 &&
		raw.lcpSelector.indexOf( '`' ) === -1 &&
		raw.lcpSelector.toLowerCase().indexOf( 'javascript:' ) === -1
	) {
		clean.lcpSelector = raw.lcpSelector.slice(
			0,
			RUM_MAX_LCP_SELECTOR_LENGTH
		);
	}
	// Slow-resource audit (issue #1311): array of <=5 shaped entries.
	// Malformed entries are dropped; the key is omitted when empty.
	if ( Array.isArray( raw.slowResources ) ) {
		const shaped = [];
		for ( const entry of raw.slowResources.slice(
			0,
			RUM_MAX_SLOW_RESOURCES
		) ) {
			const cleanEntry = sanitizeSlowResourceEntry( entry );
			if ( cleanEntry ) {
				shaped.push( cleanEntry );
			}
			if ( shaped.length >= RUM_MAX_SLOW_RESOURCES ) {
				break;
			}
		}
		if ( shaped.length ) {
			clean.slowResources = shaped;
		}
	}
	return clean;
};

( function () {
	if (
		! window.wppoRum ||
		! window.wppoRum.apiUrl ||
		typeof performance === 'undefined'
	) {
		return;
	}
	const config = window.wppoRum;
	const values = {};
	let sent = false;
	let lcpObserver = null;
	let clsObserver = null;
	let inpObserver = null;
	let scheduleTimerId = null;

	const disconnectObservers = () => {
		if ( lcpObserver ) {
			try {
				lcpObserver.disconnect();
			} catch {
				// Ignore disconnect errors.
			}
			lcpObserver = null;
		}
		if ( clsObserver ) {
			try {
				clsObserver.disconnect();
			} catch {
				// Ignore disconnect errors.
			}
			clsObserver = null;
		}
		if ( inpObserver ) {
			try {
				inpObserver.disconnect();
			} catch {
				// Ignore disconnect errors.
			}
			inpObserver = null;
		}
	};

	const send = () => {
		if ( sent ) {
			return;
		}
		// Sampling gate (issue #1214): send only about `sampleRate`
		// percent of page views. Fail-open: a missing/invalid rate sends,
		// and a dropped view degrades to unmeasured, never fatal. A drop
		// still marks the beacon sent and disconnects observers so later
		// triggers do not retry.
		if ( ! shouldSendSample( config.sampleRate ) ) {
			sent = true;
			if ( scheduleTimerId ) {
				clearTimeout( scheduleTimerId );
				scheduleTimerId = null;
			}
			disconnectObservers();
			return;
		}
		// Cap metric magnitudes client-side (mirrors the server-side
		// 0–60000ms / 0–1 CLS ranges): extreme observer values never leave
		// the page. Out-of-range values are dropped, not clamped, so forged
		// magnitudes cannot skew aggregates upward.
		const metrics = sanitizeRumValues( values );
		const hasMetric =
			metrics.ttfb !== undefined ||
			metrics.fcp !== undefined ||
			metrics.lcp !== undefined ||
			metrics.cls !== undefined ||
			metrics.inp !== undefined;
		if ( ! hasMetric ) {
			return;
		}
		sent = true;
		if ( scheduleTimerId ) {
			clearTimeout( scheduleTimerId );
			scheduleTimerId = null;
		}
		disconnectObservers();

		// Field LCP device × template segmentation (issue #986): attach
		// optional device/template dimensions fail-open. Detection failures
		// omit the fields; the numeric path is unchanged.
		const extra = {};
		try {
			let isMobile = null;
			if (
				typeof navigator !== 'undefined' &&
				navigator.userAgentData &&
				typeof navigator.userAgentData.mobile === 'boolean'
			) {
				isMobile = navigator.userAgentData.mobile;
			} else if (
				typeof window.screen !== 'undefined' &&
				window.screen
			) {
				// Physical screen width is resize-stable; fall back to the
				// viewport only when screen width is unavailable. Tablets in
				// the ~768–1024px band bucket as mobile.
				isMobile = classifyDeviceWidth(
					window.screen.width || 0,
					window.innerWidth || 0
				);
			}
			if ( isMobile === true ) {
				extra.device = 'mobile';
			} else if ( isMobile === false ) {
				extra.device = 'desktop';
			}
		} catch {
			// Device detection unavailable; field omitted.
		}
		try {
			if (
				config.template &&
				typeof config.template === 'string' &&
				config.template.length > 0
			) {
				extra.template = config.template.slice( 0, 64 );
			}
		} catch {
			// Template passthrough unavailable; field omitted.
		}
		try {
			if (
				typeof navigator !== 'undefined' &&
				navigator.connection &&
				typeof navigator.connection.effectiveType === 'string'
			) {
				const connection = classifyConnectionType(
					navigator.connection.effectiveType
				);
				if ( connection ) {
					extra.connection = connection;
				}
			}
		} catch {
			// Connection detection unavailable; field omitted.
		}
		// LCP element attribution + slow-resource audit (issue #1311):
		// attach exact hero selector and slowest sub-resources fail-open.
		// Detection failures omit the fields; the numeric path is unchanged.
		try {
			const slow = collectSlowResources();
			if ( Array.isArray( slow ) && slow.length ) {
				const budgeted = sanitizeRumValues( {
					lcp: 1,
					slowResources: slow,
				} ).slowResources;
				if ( Array.isArray( budgeted ) && budgeted.length ) {
					extra.slowResources = budgeted;
				}
			}
		} catch {
			// Slow-resource audit unavailable; field omitted.
		}

		const payload = JSON.stringify( {
			// Page-visible by design: the public rum_collect endpoint
			// validates this per-path token server-side (with per-IP rate
			// limiting); see the module docblock above.
			token: config.token,
			path: config.path,
			...metrics,
			...extra,
		} );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon(
				config.apiUrl,
				new Blob( [ payload ], { type: 'application/json' } )
			);
			return;
		}

		fetch( config.apiUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: payload,
			credentials: 'omit',
			keepalive: true,
		} ).catch( () => {
			// Intentionally silent: beacon delivery is best-effort on
			// pagehide/unload. Warn only, never a user-facing error.
			if ( typeof console !== 'undefined' && console.warn ) {
				console.warn( 'wppo: beacon failed' );
			}
		} );
	};

	// TTFB + FCP from navigation and paint timing.
	try {
		const nav = performance.getEntriesByType( 'navigation' )[ 0 ];
		if ( nav ) {
			values.ttfb = Math.round( nav.responseStart );
		}
		const paint = performance.getEntriesByType( 'paint' );
		for ( const entry of paint ) {
			if ( entry.name === 'first-contentful-paint' ) {
				values.fcp = Math.round( entry.startTime );
			}
		}
	} catch {
		// Navigation timing unavailable; metrics are optional.
	}

	if ( 'PerformanceObserver' in window ) {
		try {
			lcpObserver = new PerformanceObserver( ( list ) => {
				const entries = list.getEntries();
				if ( ! entries.length ) {
					return;
				}
				const last = entries[ entries.length - 1 ];
				values.lcp = Math.round( last.startTime );
				// Field-measured LCP element URL (issue #935): overrides the
				// PageSpeed lab heuristic only after enough samples. Fail-open:
				// text LCP entries have no URL, so the field is omitted and the
				// numeric path is unchanged.
				const lcpUrl =
					last && typeof last.url === 'string' ? last.url : '';
				if (
					lcpUrl &&
					lcpUrl.length <= 2048 &&
					( lcpUrl.indexOf( 'http://' ) === 0 ||
						lcpUrl.indexOf( 'https://' ) === 0 ||
						lcpUrl.charAt( 0 ) === '/' )
				) {
					values.lcpUrl = lcpUrl.slice( 0, 2048 );
				} else {
					delete values.lcpUrl;
				}
				// LCP element attribution (issue #1311): compact selector
				// derived from `last.element` (tag + #id / first class only,
				// never xpath or outerHTML). Guarded + fail-open: absent
				// element or derivation failure omits the field.
				try {
					if (
						typeof PerformanceObserver !== 'undefined' &&
						last &&
						last.element
					) {
						const selector = deriveLcpSelector( last.element );
						if ( selector ) {
							const checked = sanitizeRumValues( {
								lcp: 1,
								lcpSelector: selector,
							} ).lcpSelector;
							if ( checked ) {
								values.lcpSelector = checked;
							} else {
								delete values.lcpSelector;
							}
						} else {
							delete values.lcpSelector;
						}
					} else {
						delete values.lcpSelector;
					}
				} catch {
					try {
						delete values.lcpSelector;
					} catch {
						// Ignore cleanup errors.
					}
				}
			} );
			lcpObserver.observe( {
				type: 'largest-contentful-paint',
				buffered: true,
			} );
		} catch {
			// LCP unsupported; ignored.
		}

		try {
			let cls = 0;
			clsObserver = new PerformanceObserver( ( list ) => {
				for ( const entry of list.getEntries() ) {
					if ( ! entry.hadRecentInput ) {
						cls += entry.value;
					}
				}
				values.cls = parseFloat( cls.toFixed( 4 ) );
			} );
			clsObserver.observe( { type: 'layout-shift', buffered: true } );
		} catch {
			// CLS unsupported; ignored.
		}

		try {
			// Audit #1366: running max across batches per the web.dev INP
			// definition (max EventTiming duration) — last-of-batch
			// under-reports multi-batch views.
			let maxInp = 0;
			inpObserver = new PerformanceObserver( ( list ) => {
				const entries = list.getEntries();
				if ( ! entries.length ) {
					return;
				}
				for ( const entry of entries ) {
					if ( entry.duration > maxInp ) {
						maxInp = entry.duration;
					}
				}
				values.inp = Math.round( maxInp );
			} );
			inpObserver.observe( {
				type: 'event',
				durationThreshold: 16,
				buffered: true,
			} );
		} catch {
			// INP unsupported; ignored.
		}
	}

	const scheduleSend = () => {
		if ( scheduleTimerId ) {
			clearTimeout( scheduleTimerId );
		}
		scheduleTimerId = window.setTimeout( () => {
			scheduleTimerId = null;
			send();
		}, 5000 );
	};

	if ( document.readyState === 'complete' ) {
		scheduleSend();
	} else {
		window.addEventListener( 'load', scheduleSend, { once: true } );
	}

	// Audit #1354: named handler removed once the beacon is away so
	// every hidden transition does not re-invoke send().
	const onVisibilityHidden = () => {
		if ( document.visibilityState === 'hidden' ) {
			send();
			if ( sent ) {
				document.removeEventListener(
					'visibilitychange',
					onVisibilityHidden
				);
			}
		}
	};
	document.addEventListener( 'visibilitychange', onVisibilityHidden );
	window.addEventListener(
		'pagehide',
		() => {
			if ( scheduleTimerId ) {
				clearTimeout( scheduleTimerId );
				scheduleTimerId = null;
			}
			send();
			disconnectObservers();
		},
		{ once: true }
	);
} )();
