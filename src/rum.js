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
 * width is unavailable. Tablets in the ~768–1024px band bucket as mobile.
 *
 * @since NEXT
 * @param {number} screenWidth   `window.screen.width` (0 when unavailable).
 * @param {number} viewportWidth `window.innerWidth` (0 when unavailable).
 * @return {boolean|null} True when mobile, false when desktop, null when unknown.
 */
export const classifyDeviceWidth = ( screenWidth, viewportWidth ) => {
	const width = screenWidth > 0 ? screenWidth : viewportWidth || 0;
	if ( width <= 0 ) {
		return null;
	}
	return width <= 1024;
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
		const hasMetric =
			values.ttfb !== undefined ||
			values.fcp !== undefined ||
			values.lcp !== undefined ||
			values.cls !== undefined ||
			values.inp !== undefined;
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
			...values,
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
