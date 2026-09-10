/**
 * Real-user Web Vitals beacon.
 *
 * Loaded via the `rum_enabled` setting. Reads the inline `window.wppoRum`
 * config (apiUrl, token, path) baked into the page output, collects Core Web
 * Vitals with PerformanceObserver and sends a single aggregated beacon after
 * the page settles or on pagehide.
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
 * @since NEXT
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
 * Upper bound (ms) accepted for time-based Web Vitals metrics before the
 * beacon is sent. Mirrors the server-side 0–60000 range enforced by
 * `RUM::sanitize_sample()` — extreme observer values (or forged beacons
 * replaying the page-visible token) are dropped client-side so they cannot
 * pollute the stored aggregates. Server-side per-IP rate limiting and
 * per-path token validation remain authoritative.
 *
 * @since NEXT
 * @type {number}
 */
export const RUM_MAX_METRIC_MS = 60000;

/**
 * Drop out-of-range metric values before the beacon is sent.
 *
 * Time metrics (ttfb/fcp/lcp/inp) must be finite numbers in 0–60000ms;
 * cls must be a finite number in 0–1. Anything else is omitted from the
 * payload. Unknown keys pass through untouched.
 *
 * @since NEXT
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
	if ( typeof raw.lcpUrl === 'string' && raw.lcpUrl ) {
		clean.lcpUrl = raw.lcpUrl;
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

		const payload = JSON.stringify( {
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
		} ).catch( () => {} );
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
			inpObserver = new PerformanceObserver( ( list ) => {
				const entries = list.getEntries();
				values.inp = Math.round(
					entries[ entries.length - 1 ].duration
				);
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

	document.addEventListener( 'visibilitychange', () => {
		if ( document.visibilityState === 'hidden' ) {
			send();
		}
	} );
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
