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

		const payload = JSON.stringify( {
			token: config.token,
			path: config.path,
			...values,
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
				values.lcp = Math.round(
					entries[ entries.length - 1 ].startTime
				);
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
