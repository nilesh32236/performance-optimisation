/**
 * Cached promise for the in-progress or completed deferred script load.
 * @type {Promise<void>|null}
 */
let scriptLoadPromise = null;

/**
 * Read the runtime config exported by PHP through the WordPress
 * `script_module_data_wppo-lazyload` filter (WP 6.5+). The data is printed as a
 * `<script type="application/json" id="wp-script-module-data-wppo-lazyload">`
 * tag before the module itself, so it is always available on load.
 *
 * @return {Object} Parsed module data, or an empty object when absent/invalid.
 */
const readModuleData = () => {
	const el = document.getElementById( 'wp-script-module-data-wppo-lazyload' );
	if ( ! el || ! el.textContent ) {
		return {};
	}
	try {
		return JSON.parse( el.textContent );
	} catch ( _err ) {
		console.warn( 'WPPO: invalid lazyload module data', _err );
	}
	return {};
};

const moduleData = readModuleData();

/**
 * Whether native lazy loading is active (loading="lazy" on img/iframe instead of IntersectionObserver).
 * Provided by PHP via the script-module data filter (WP 6.5+) or via
 * wp_add_inline_script on the classic-script fallback path (WP < 6.5).
 * @type {boolean}
 */
const useNativeLazy =
	( typeof window.wppoNativeLazy !== 'undefined' && window.wppoNativeLazy ) ||
	!! moduleData.nativeLazy;

/**
 * Whether the browser supports native loading="lazy" for images and iframes.
 * @type {boolean}
 */
const NATIVE_LAZY_SUPPORTED = 'loading' in HTMLImageElement.prototype;

/**
 * Whether native lazy loading is both enabled AND supported by the browser.
 * When true, images and iframes use native loading="lazy" instead of IntersectionObserver.
 * @type {boolean}
 */
const USE_NATIVE_LAZY = useNativeLazy && NATIVE_LAZY_SUPPORTED;

/**
 * Whether the browser supports sizes="auto" (Enhanced Responsive Images).
 *
 * Browsers that support the feature apply size containment to [sizes="auto"]
 * images via their UA stylesheet, so a probe of the computed `contain` value
 * distinguishes supporting engines (Chromium 126+/Firefox 150+) from those
 * that ignore the `auto` keyword (e.g. Safari). Non-supporting browsers report
 * `contain: none`, so a bare `sizes="auto"` is never emitted for them.
 * @type {boolean}
 */
const AUTO_SIZES_SUPPORTED = ( () => {
	try {
		const probe = document.createElement( 'img' );
		probe.setAttribute( 'sizes', 'auto' );
		probe.style.display = 'none';
		document.documentElement.appendChild( probe );
		const supported = 'size' === window.getComputedStyle( probe ).contain;
		probe.remove();
		return supported;
	} catch ( _e ) {
		console.warn( 'WPPO: auto-sizes probe failed', _e );
	}
	return false;
} )();

/**
 * Selector for all lazy-loadable elements.
 * In full native mode, only videos need JS-based lazy loading.
 * As a function so call sites recompute after setting changes (requires reload).
 * @return {string} The selector string.
 */
const getLazySelector = () => {
	// Live path is module data (`window.wppoNativeLazy` / `moduleData.nativeLazy`
	// via USE_NATIVE_LAZY above). No `general` settings tab exists, so there is
	// no legacy `wppoSettings.settings.general.native_lazy` branch to honour.
	return USE_NATIVE_LAZY
		? 'video.wppo-lazy-video'
		: 'img[data-src], img[data-srcset], iframe[data-src], video.wppo-lazy-video';
};

/**
 * Allowlist of attributes copied from a deferred placeholder script to its
 * live replacement. Everything not on this list — notably `on*` event
 * handlers and the CSP `nonce` attribute — is dropped, so an attacker-
 * controlled placeholder cannot propagate executable attributes.
 *
 * `fetchpriority` is included because PHP stamps `fetchpriority="low"` on
 * every delayed script (Main::add_defer_attribute()) and dropping it would
 * change loading behaviour; `wppo-type` is the delayed original `type`.
 *
 * @since NEXT
 * @type {Set<string>}
 */
const SCRIPT_ATTR_ALLOWLIST = new Set( [
	'src',
	'type',
	'wppo-src',
	'wppo-type',
	'async',
	'defer',
	'crossorigin',
	'integrity',
	'id',
	'class',
	'fetchpriority',
	'nomodule',
	'referrerpolicy',
] );

/**
 * Attribute name prefixes that are safe to copy (data-* metadata and aria-*
 * accessibility attributes carry no execution semantics).
 *
 * @since NEXT
 * @type {string[]}
 */
const SCRIPT_ATTR_PREFIX_ALLOWLIST = [ 'data-', 'aria-' ];

/**
 * Compiled-once pattern for url(...) targets in lazy background values.
 * Module scope so it is not reallocated per isSafeBackgroundValue call.
 * Global flag is stateful — reset lastIndex before each exec loop.
 *
 * @since NEXT
 * @type {RegExp}
 */
const BACKGROUND_URL_PATTERN = /url\(\s*(['"]?)(.*?)\1\s*\)/g;

/**
 * Number of elements currently under IntersectionObserver observation.
 * Gates checkCleanup so completion does not cost a full DOM scan per
 * intersection on image-heavy pages.
 *
 * @since NEXT
 * @type {number}
 */
let pendingLazyCount = 0;

/**
 * Base allowlist of remote hosts permitted for deferred external scripts, in
 * addition to same-origin. Covers common analytics/marketing/utility CDNs so
 * default delay-JS behaviour is preserved; anything else must be allowlisted
 * explicitly. Extend without touching this bundle via the PHP
 * `wppo_delay_js_allowed_hosts` filter (mirrored into
 * `wppoDelayConfig.allowedScriptHosts`) or at runtime via
 * `window.wppoAllowedScriptHosts`. Set `window.wppoAllowedScriptHosts` to
 * `['*']` to allow any host (not recommended).
 *
 * @since NEXT
 * @type {string[]}
 */
const SCRIPT_SRC_HOST_ALLOWLIST = [
	'googletagmanager.com',
	'google-analytics.com',
	'googlesyndication.com',
	'googletagservices.com',
	'googleadservices.com',
	'doubleclick.net',
	'google.com',
	'gstatic.com',
	'youtube.com',
	'ytimg.com',
	'player.vimeo.com',
	'fast.wistia.com',
	'fast.wistia.net',
	'js.stripe.com',
	'js.braintreegateway.com',
	'paypalobjects.com',
	'connect.facebook.net',
	'analytics.tiktok.com',
	'static.ads-twitter.com',
	'platform.twitter.com',
	'platform.linkedin.com',
	'snap.licdn.com',
	'assets.pinterest.com',
	'static.hotjar.com',
	'script.hotjar.com',
	'clarity.ms',
	'bat.bing.com',
	'cdn.onesignal.com',
	'challenges.cloudflare.com',
	'static.cloudflareinsights.com',
	'cdnjs.cloudflare.com',
	'code.jquery.com',
	'cdn.jsdelivr.net',
	'unpkg.com',
	'ajax.googleapis.com',
	'use.fontawesome.com',
	'kit.fontawesome.com',
	'widget.intercom.io',
	'js.intercomcdn.com',
	'embed.tawk.to',
	'client.crisp.chat',
	'js.hs-scripts.com',
	'js.usemessages.com',
	'js.hsadspixel.net',
	'static.klaviyo.com',
	'a.klaviyo.com',
	'chimpstatic.com',
	'static.zdassets.com',
	'assets.zendesk.com',
	'cdn.livechatinc.com',
	'cdn.segment.com',
	'cdn.mxpnl.com',
	'browser.sentry-cdn.com',
	'js-agent.newrelic.com',
	'nr-data.net',
	'sb.scorecardresearch.com',
	'secure.quantserve.com',
	'staticw2.yotpo.com',
	'cdn.yotpo.com',
	'cdn.judge.me',
];

/**
 * Host allowlist for `<iframe>` video embeds restored from
 * `.wppo-video-placeholder[data-wppo-video-src]` placeholders. Mirrors the
 * PHP-side validation in Image_Optimisation::generate_video_placeholder(),
 * which only replaces matching embeds (YouTube today; the extra hosts below
 * keep the client strict-but-extensible for embeds filtered in via
 * `wppo_video_placeholder_html`). Same-origin embeds are also permitted.
 *
 * @since NEXT
 * @type {string[]}
 */
const VIDEO_EMBED_HOST_ALLOWLIST = [
	'youtube.com',
	'youtube-nocookie.com',
	'youtu.be',
	'vimeo.com',
	'dailymotion.com',
];

/**
 * Allowlist of iframe attributes restored from the PHP-provided
 * data-wppo-iframe-attrs payload. Mirrors the attributes PHP stores (id,
 * class, sandbox, referrerpolicy, title, name, frameborder, allow,
 * allowfullscreen); src/width/height/style are owned by this code and on*
 * handlers are never restorable.
 *
 * @since NEXT
 * @type {Set<string>}
 */
const IFRAME_ATTR_ALLOWLIST = new Set( [
	'id',
	'class',
	'sandbox',
	'referrerpolicy',
	'title',
	'name',
	'frameborder',
	'allow',
	'allowfullscreen',
] );

/**
 * Safe `allow` (Permissions-Policy) tokens for restored iframes. A tampered
 * data-wppo-iframe-attrs payload must not escalate capabilities (e.g.
 * `allow="camera; microphone"`), so unknown tokens are stripped and the
 * attribute is dropped when nothing safe remains.
 *
 * @since NEXT
 * @type {Set<string>}
 */
const IFRAME_ALLOW_TOKENS = new Set( [
	'accelerometer',
	'autoplay',
	'clipboard-write',
	'encrypted-media',
	'fullscreen',
	'gyroscope',
	'picture-in-picture',
	'web-share',
] );

/**
 * Safe `sandbox` tokens for restored iframes. Top-navigation tokens are
 * deliberately excluded so a tampered payload cannot let the iframe break
 * out of its frame.
 *
 * @since NEXT
 * @type {Set<string>}
 */
const IFRAME_SANDBOX_TOKENS = new Set( [
	'allow-downloads',
	'allow-forms',
	'allow-modals',
	'allow-orientation-lock',
	'allow-pointer-lock',
	'allow-popups',
	'allow-popups-to-escape-sandbox',
	'allow-presentation',
	'allow-same-origin',
	'allow-scripts',
] );

/**
 * Valid `referrerpolicy` values for restored iframes.
 *
 * @since NEXT
 * @type {Set<string>}
 */
const IFRAME_REFERRERPOLICY_TOKENS = new Set( [
	'no-referrer',
	'no-referrer-when-downgrade',
	'origin',
	'origin-when-cross-origin',
	'same-origin',
	'strict-origin',
	'strict-origin-when-cross-origin',
	'unsafe-url',
] );

/**
 * Sanitize a stored `allow` value: keep only known-safe Permissions-Policy
 * tokens, return '' when nothing safe remains.
 *
 * @since NEXT
 * @param {string} value Raw allow attribute value.
 * @return {string} Sanitized value ('' when unsafe/empty).
 */
const sanitizeIframeAllow = ( value ) => {
	const tokens = String( value )
		.split( ';' )
		.map( ( token ) => token.trim().toLowerCase() )
		.filter(
			( token ) =>
				token &&
				/^[a-z-]+$/.test( token ) &&
				IFRAME_ALLOW_TOKENS.has( token )
		);
	return tokens.join( '; ' );
};

/**
 * Sanitize a stored `sandbox` value: keep only known-safe tokens (never
 * top-navigation). Returns null when a non-empty value has no safe token
 * left; an empty input stays empty (fully sandboxed, strictest).
 *
 * @since NEXT
 * @param {string} value Raw sandbox attribute value.
 * @return {string|null} Sanitized value, or null to skip the attribute.
 */
const sanitizeIframeSandbox = ( value ) => {
	const raw = String( value ).trim().toLowerCase();
	if ( ! raw ) {
		return '';
	}
	const tokens = raw
		.split( /\s+/ )
		.filter(
			( token ) =>
				token &&
				/^[a-z-]+$/.test( token ) &&
				IFRAME_SANDBOX_TOKENS.has( token )
		);
	return tokens.length ? tokens.join( ' ' ) : null;
};

/**
 * Whether a host matches the allowlist (exact or subdomain, case-insensitive).
 *
 * @since NEXT
 * @param {string}   hostname URL hostname (lower-cased by the caller).
 * @param {string[]} hosts    Allowlisted base hosts.
 * @return {boolean} True when the host is allowlisted.
 */
const hostInAllowlist = ( hostname, hosts ) =>
	hosts.some(
		( host ) => hostname === host || hostname.endsWith( `.${ host }` )
	);

/**
 * Whether the '*' wildcard warning has already been emitted. getScriptSrcHosts
 * runs per deferred script, so the warning must fire once, not per script.
 *
 * @since NEXT
 * @type {boolean}
 */
let wildcardWarned = false;

/**
 * Collect the effective script-src host allowlist: the bundle constant plus
 * any runtime extension provided via window.wppoAllowedScriptHosts or the
 * PHP-provided wppoDelayConfig.allowedScriptHosts (see
 * `wppo_delay_js_allowed_hosts` filter). A `'*'` entry allows all hosts.
 *
 * @since NEXT
 * @return {string[]|'*'} Allowlist entries, or '*' to allow everything.
 */
const getScriptSrcHosts = () => {
	const runtimeHosts =
		( typeof window !== 'undefined' && window.wppoAllowedScriptHosts ) ||
		( delayConfig && delayConfig.allowedScriptHosts ) ||
		[];
	const hosts = Array.from(
		new Set( [ ...SCRIPT_SRC_HOST_ALLOWLIST, ...runtimeHosts ] )
	);
	if ( hosts.includes( '*' ) ) {
		// The '*' wildcard disables the deferred-script host allowlist
		// entirely: any host may execute delayed scripts. Loud on purpose —
		// a single inline-script injection or misconfiguration must not
		// silently turn the allowlist off.
		if ( ! wildcardWarned ) {
			wildcardWarned = true;
			console.warn(
				'WPPO: window.wppoAllowedScriptHosts / allowedScriptHosts contains "*": the deferred-script host allowlist is disabled and any host may load delayed scripts. Remove the wildcard in production.'
			);
		}
		return '*';
	}
	return hosts;
};

/**
 * Validate a deferred script's src before it is assigned to a live script
 * element: the URL must parse, use http(s), and point at the same origin or
 * an allowlisted host. Rejects javascript:/data:/blob: and arbitrary origins
 * so attacker-controlled placeholder attributes cannot execute.
 *
 * @since NEXT
 * @param {string} src Raw src attribute value.
 * @return {boolean} True when the src is safe to load.
 */
const isSafeScriptSrc = ( src ) => {
	if ( ! src || typeof src !== 'string' ) {
		return false;
	}
	let url;
	try {
		url = new URL( src, window.location.origin );
	} catch {
		return false;
	}
	// Cross-origin scripts must be https (no mixed active content); http is
	// tolerated only for same-origin URLs (http dev/staging origins).
	if ( url.origin !== window.location.origin && 'https:' !== url.protocol ) {
		return false;
	}
	if ( url.origin === window.location.origin ) {
		return true;
	}
	const hosts = getScriptSrcHosts();
	if ( '*' === hosts ) {
		return true;
	}
	return hostInAllowlist( url.hostname.toLowerCase(), hosts );
};

/**
 * Validate an iframe video embed URL from a data-wppo-video-src attribute.
 * Cross-origin embeds must be absolute https: URLs on a known embed host;
 * same-origin URLs are trusted (http dev origins included). Rejects
 * javascript:/data:/blob: and protocol-relative shenanigans outright.
 *
 * @since NEXT
 * @param {string} src Raw data-wppo-video-src attribute value.
 * @return {boolean} True when the embed URL is safe to load in an iframe.
 */
const isSafeVideoEmbedUrl = ( src ) => {
	if ( ! src || typeof src !== 'string' ) {
		return false;
	}
	let url;
	try {
		url = new URL( src, window.location.origin );
	} catch {
		return false;
	}
	if ( url.origin === window.location.origin ) {
		return true;
	}
	if ( 'https:' !== url.protocol ) {
		return false;
	}
	return hostInAllowlist(
		url.hostname.toLowerCase(),
		VIDEO_EMBED_HOST_ALLOWLIST
	);
};

/**
 * Validate a generic lazy subresource URL (iframe data-src, video data-src /
 * data-poster, source data-src, img data-wppo-fallback) before it is assigned
 * to a live sink. The URL must parse, use http(s), and be same-origin or
 * cross-origin https. Rejects javascript:/vbscript:/data:/blob: outright so
 * attacker-controlled data-* attributes promoted by the MutationObserver
 * cannot become stored XSS (iframe javascript: executes script).
 *
 * Unlike isSafeVideoEmbedUrl (host-allowlisted embeds), this is intentionally
 * generic so legitimate non-video iframes (maps, forms, widgets) keep working.
 *
 * @since NEXT
 * @param {string} src Raw data-* attribute value.
 * @return {boolean} True when the URL is safe to assign to src/poster.
 */
const isSafeSubresourceUrl = ( src ) => {
	if ( ! src || typeof src !== 'string' ) {
		return false;
	}
	// Normalise whitespace/C0 controls first (browsers ignore them when
	// parsing schemes: "java\tscript:", "  javascript:").
	const normalised = String( src ).replace( /[\u0000-\u0020]/g, '' );
	if ( ! normalised ) {
		return false;
	}
	let url;
	try {
		url = new URL( normalised, window.location.origin );
	} catch {
		return false;
	}
	if ( 'http:' !== url.protocol && 'https:' !== url.protocol ) {
		return false;
	}
	if ( url.origin === window.location.origin ) {
		return true;
	}
	// Cross-origin subresources must be https (no mixed active content);
	// http is tolerated only for same-origin dev/staging origins.
	return 'https:' === url.protocol;
};

/**
 * Validate a lazy CSS background value from data-wppo-bg before it is
 * assigned to el.style.backgroundImage. Property assignment cannot run
 * script, but an attacker-controlled attribute could force arbitrary
 * cross-origin loads (tracking beacon) or malformed CSS.
 *
 * Accepts only url(...) with http(s)/same-origin or data:image/* payloads;
 * rejects javascript:/vbscript:/expression()/behavior/-moz-binding and
 * control characters.
 *
 * @since NEXT
 * @param {string} value Raw data-wppo-bg attribute value.
 * @return {boolean} True when the value is safe to assign.
 */
const isSafeBackgroundValue = ( value ) => {
	if ( ! value || typeof value !== 'string' ) {
		return false;
	}
	const normalised = String( value )
		.toLowerCase()
		.replace( /[\u0000-\u001f\u007f]/g, '' );
	if (
		normalised.includes( 'javascript:' ) ||
		normalised.includes( 'vbscript:' ) ||
		normalised.includes( 'expression(' ) ||
		normalised.includes( 'behavior' ) ||
		normalised.includes( '-moz-binding' )
	) {
		return false;
	}
	// Only url(...) payloads are expected; plain colour/gradient values from
	// PHP are safe to pass through when they contain no url( at all.
	if ( ! normalised.includes( 'url(' ) ) {
		return true;
	}
	// Extract url(...) targets and validate each one.
	BACKGROUND_URL_PATTERN.lastIndex = 0;
	let match;
	let found = false;
	while ( ( match = BACKGROUND_URL_PATTERN.exec( normalised ) ) !== null ) {
		found = true;
		const target = match[ 2 ].trim();
		if ( ! target ) {
			return false;
		}
		if ( target.startsWith( 'data:image/' ) ) {
			continue;
		}
		let url;
		try {
			url = new URL( target, window.location.origin );
		} catch {
			return false;
		}
		if ( 'http:' !== url.protocol && 'https:' !== url.protocol ) {
			return false;
		}
		if (
			url.origin !== window.location.origin &&
			'https:' !== url.protocol
		) {
			return false;
		}
	}
	return found;
};

/**
 * Copy allowlisted attributes from the placeholder script to the replacement.
 * Event-handler (`on*`) attributes are never copied — the allowlist is the
 * only path from placeholder to live element.
 *
 * @since NEXT
 * @param {HTMLScriptElement} from        Placeholder script element.
 * @param {HTMLScriptElement} replacement Fresh script element.
 * @return {void}
 */
const copyAllowedScriptAttrs = ( from, replacement ) => {
	Array.from( from.attributes ).forEach( ( attr ) => {
		const name = ( attr.name || '' ).toLowerCase();
		if ( SCRIPT_ATTR_ALLOWLIST.has( name ) ) {
			replacement.setAttribute( attr.name, attr.value );
			return;
		}
		if (
			SCRIPT_ATTR_PREFIX_ALLOWLIST.some( ( prefix ) =>
				name.startsWith( prefix )
			)
		) {
			replacement.setAttribute( attr.name, attr.value );
		}
		// Everything else (on* handlers, nonce, style, …) is dropped.
	} );
};

/**
 * Load a single deferred script element.
 *
 * Restores the original `src` and `type` attributes, then resolves
 * once the script has loaded or errors.
 *
 * @since 1.0.0
 * @since NEXT Placeholder attributes are allowlisted and wppo-src is validated (scheme + same-origin/host allowlist) before a replacement script is created.
 * @param {HTMLScriptElement} script The script element to load.
 * @return {Promise<void>}
 */
const loadScript = ( script ) => {
	return new Promise( ( resolve, reject ) => {
		if ( 'wppo/javascript' === script.getAttribute( 'type' ) ) {
			script.removeAttribute( 'type' );
		}

		const wppoType = script.getAttribute( 'wppo-type' );
		if ( wppoType ) {
			script.removeAttribute( 'wppo-type' );
			script.setAttribute( 'type', wppoType );
		}

		const src = script.getAttribute( 'wppo-src' );

		if ( src ) {
			if ( ! isSafeScriptSrc( src ) ) {
				// Leave the placeholder untouched and keep it out of the
				// replacement set — the script is never executed.
				console.warn(
					'WPPO: blocked deferred script src (scheme/origin not allowed):',
					src
				);
				resolve();
				return;
			}

			// External deferred script: create a replacement script node, copy
			// allowlisted original attributes, assign the deferred src, and
			// swap it into the DOM.
			const replacement = document.createElement( 'script' );

			copyAllowedScriptAttrs( script, replacement );

			replacement.removeAttribute( 'wppo-src' );
			replacement.setAttribute( 'src', src );

			replacement.onload = () => {
				if ( typeof script.onload === 'function' ) {
					script.onload();
				}
				resolve();
			};
			replacement.onerror = ( err ) => {
				if ( typeof script.onerror === 'function' ) {
					script.onerror( err );
				}
				reject( err );
			};

			if ( script.parentNode ) {
				script.parentNode.replaceChild( replacement, script );
			} else {
				document.head.appendChild( replacement );
			}
		} else if ( script.text ) {
			// Inline script: browsers execute a script element only once after insertion.
			// Mutating the already-inserted node does nothing, so we must replace it with
			// a fresh element. Copy allowlisted attributes and content to the new node,
			// swap it into the DOM, and resolve once it has been processed.
			const replacement = document.createElement( 'script' );

			// Copy allowlisted attributes from the original node to the replacement.
			copyAllowedScriptAttrs( script, replacement );

			replacement.text = script.text;

			if ( script.parentNode ) {
				script.parentNode.replaceChild( replacement, script );
			} else {
				document.head.appendChild( replacement );
			}

			// Inline scripts execute synchronously during DOM insertion, so resolve here.
			resolve();
		} else {
			// Empty inline script: resolve benignly.
			if ( ! script.text ) {
				console.warn( 'WPPO: empty inline script found', script );
			}
			resolve();
		}
	} );
};

/**
 * Delay JS configuration from PHP.
 * Read from the script-module data filter (WP 6.5+) or the classic
 * `window.wppoDelayConfig` global (WP < 6.5), with sensible defaults.
 * @type {{ idleTimeout: number, defaultStrategy: string }}
 */
const delayConfig = window.wppoDelayConfig ||
	moduleData.delayConfig || {
		idleTimeout: 3000,
		defaultStrategy: 'interaction',
	};

/**
 * Load scripts grouped by priority (high → normal → low).
 *
 * @since 3.8.0
 * @param {NodeList|HTMLScriptElement[]} scripts The scripts to load.
 * @return {Promise<void>} Resolves when all scripts have been loaded.
 */
async function loadScriptsByPriority( scripts ) {
	const groups = { high: [], normal: [], low: [] };
	Array.from( scripts ).forEach( ( script ) => {
		const priority =
			script.getAttribute( 'data-wppo-delay-priority' ) || 'normal';
		if ( groups[ priority ] ) {
			groups[ priority ].push( script );
		} else {
			groups.normal.push( script );
		}
	} );

	for ( const level of [ 'high', 'normal', 'low' ] ) {
		const results = await Promise.allSettled(
			groups[ level ].map( ( script ) => loadScript( script ) )
		);
		results
			.filter( ( r ) => r.status === 'rejected' )
			.forEach( ( r ) =>
				console.error( 'Error loading script:', r.reason )
			);
	}
}

/**
 * Load all deferred scripts queued in the DOM.
 *
 * Once all scripts are loaded, dispatches DOMContentLoaded,
 * load, and pageshow events, and triggers lazy image loading.
 *
 * @since 1.0.0
 * @return {Promise<void>}
 */
async function loadScripts() {
	if ( scriptLoadPromise ) {
		return scriptLoadPromise;
	}

	scriptLoadPromise = ( async () => {
		const inlineScripts = Array.from(
			document.querySelectorAll(
				'script[type="wppo/javascript"], script[wppo-src]'
			)
		);

		try {
			await loadScriptsByPriority( inlineScripts );
		} catch ( err ) {
			console.error( 'Error loading script:', err );
		}

		if ( document.readyState === 'loading' ) {
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		}

		if ( typeof jQuery !== 'undefined' ) {
			jQuery( document ).triggerHandler( 'ready' );
		}

		// Refresh GSAP ScrollTrigger if active
		if ( typeof ScrollTrigger !== 'undefined' ) {
			ScrollTrigger.refresh();
		} else if ( window.gsap && window.gsap.utils ) {
			const st = window.gsap.plugins
				? window.gsap.plugins.scrollTrigger
				: null;
			if ( st && st.refresh ) {
				st.refresh();
			}
		}

		setTimeout( () => {
			loadImages();
		}, 200 );
	} )();

	return scriptLoadPromise;
}

/**
 * Load scripts with 'idle' strategy using requestIdleCallback.
 *
 * @since 3.8.0
 */
const loadIdleScripts = async () => {
	const idleScripts = document.querySelectorAll(
		'script[data-wppo-delay-strategy="idle"]'
	);
	if ( idleScripts.length > 0 ) {
		try {
			await loadScriptsByPriority( idleScripts );
		} catch ( err ) {
			console.error( 'Error loading idle script:', err );
		}
	}
};

/**
 * Observe viewport-strategy scripts with IntersectionObserver.
 *
 * Falls back to immediate loading when the Observer API is unavailable.
 *
 * @since 3.8.0
 */
const observeViewportScripts = () => {
	const viewportScripts = document.querySelectorAll(
		'script[data-wppo-delay-strategy="viewport"]'
	);
	if ( viewportScripts.length === 0 ) {
		return;
	}

	if ( ! ( 'IntersectionObserver' in window ) ) {
		loadScriptsByPriority( viewportScripts );
		return;
	}

	let pendingViewportCount = viewportScripts.length;

	const observer = new IntersectionObserver(
		( entries ) => {
			const toLoad = [];
			entries.forEach( ( entry ) => {
				if ( entry.isIntersecting ) {
					const script = entry.target;
					observer.unobserve( script );
					toLoad.push( script );
				}
			} );
			if ( toLoad.length > 0 ) {
				pendingViewportCount -= toLoad.length;
				loadScriptsByPriority( toLoad )
					.catch( ( err ) =>
						console.error( 'Error loading viewport scripts:', err )
					)
					.finally( () => {
						if ( pendingViewportCount <= 0 ) {
							observer.disconnect();
						}
					} );
			}
		},
		{ rootMargin: '200px' }
	);

	viewportScripts.forEach( ( script ) => observer.observe( script ) );
};

/**
 * Check if there are any scripts that use 'interaction' as their strategy.
 *
 * Scripts with no explicit strategy attribute default to 'interaction'.
 *
 * @param {NodeList|HTMLScriptElement[]} [scripts] Optional script list to check. Defaults to querying the DOM.
 * @since 3.8.0
 * @return {boolean} Whether any delayed scripts use the 'interaction' strategy.
 */
const hasInteractionScripts = ( scripts ) => {
	const list =
		scripts ||
		document.querySelectorAll(
			'script[type="wppo/javascript"], script[wppo-src]'
		);
	return Array.from( list ).some( ( script ) => {
		const strategy =
			script.getAttribute( 'data-wppo-delay-strategy' ) ||
			delayConfig.defaultStrategy;
		return strategy === 'interaction';
	} );
};

// Initialize delay JS strategies.
const delayedScripts = document.querySelectorAll(
	'script[type="wppo/javascript"], script[wppo-src]'
);

// Schedule idle scripts via requestIdleCallback.
const idleScripts = document.querySelectorAll(
	'script[data-wppo-delay-strategy="idle"]'
);
if ( idleScripts.length > 0 ) {
	if ( 'requestIdleCallback' in window ) {
		window.requestIdleCallback( loadIdleScripts, {
			timeout: delayConfig.idleTimeout,
		} );
	} else {
		// Fallback: load after a short delay.
		// requestIdleCallback's timeout is a deadline (max wait), while setTimeout is a minimum delay.
		// Use a shorter explicit delay to avoid excessive waiting when rIC is unavailable.
		setTimeout(
			loadIdleScripts,
			Math.min( 2000, delayConfig.idleTimeout )
		);
	}
}

// Observe viewport scripts.
observeViewportScripts();

// Only register interaction event listeners if there are scripts using interaction strategy.
if ( delayedScripts.length > 0 && hasInteractionScripts( delayedScripts ) ) {
	const triggerEvents = [
		'mouseenter',
		'mousedown',
		'mouseover',
		'touchstart',
		'scroll',
		'keydown',
	];
	const loadHandler = () => {
		triggerEvents.forEach( ( event ) =>
			document.removeEventListener( event, loadHandler )
		);
		loadScripts();
	};

	triggerEvents.forEach( ( event ) =>
		document.addEventListener( event, loadHandler, { once: true } )
	);
} else if ( document.querySelector( 'script[data-wppo-delay-strategy]' ) ) {
	// No interaction scripts — but some scripts have explicit non-interaction strategies.
	// The page will rely on idle/viewport loading. Nothing to do here.
}

/**
 * IntersectionObserver instance for lazy-loading images/iframes/videos.
 * @type {IntersectionObserver|null}
 */
let globalObserver = null;

/**
 * MutationObserver instance for lazy-loading.
 * @type {MutationObserver|null}
 */
let mutationObserver = null;

/**
 * Set of elements already observed by globalObserver.
 * @type {WeakSet<Element>}
 */
const observedElements = new WeakSet();

/**
 * IntersectionObserver for lazy background-images.
 * @type {IntersectionObserver|null}
 */
let backgroundObserver = null;

/**
 * Module-local handle for the safety-scan interval.
 *
 * The id is mirrored to window.wppoSafetyScanId for backward compatibility
 * (tests and any inline snippets reference it). The module-local binding is
 * the source of truth so a second copy of this module executing on the same
 * page cannot collide with — or clear — this copy's interval via the shared
 * window property: each copy only clears the interval it created.
 *
 * @type {number|null}
 */
let safetyScanId = null;

/**
 * Clear the safety-scan interval owned by this module copy.
 *
 * Only this copy's own interval is cleared; the shared window mirror is
 * released solely when it points at our interval, so a second copy of this
 * module on the same page never stops another copy's scan.
 */
const clearSafetyScan = () => {
	if ( safetyScanId !== null ) {
		clearInterval( safetyScanId );
		if ( window.wppoSafetyScanId === safetyScanId ) {
			window.wppoSafetyScanId = null;
		}
		safetyScanId = null;
	}
};

/**
 * Teardown lazyload observers and safety interval.
 *
 * Idempotent — safe to call multiple times. Runs automatically on
 * pagehide/beforeunload and is exposed as window.wppoLazyloadTeardown for
 * tests and SPA-style teardown (call it before removing this module's script
 * element dynamically; nothing observes script-element removal automatically).
 *
 * @since NEXT
 */
const teardownLazyload = () => {
	pendingLazyCount = 0;
	clearSafetyScan();
	// Release any legacy mirror (e.g. set by tests or an older copy) so
	// teardown stays idempotent and leak-free.
	if ( window.wppoSafetyScanId ) {
		clearInterval( window.wppoSafetyScanId );
		window.wppoSafetyScanId = null;
	}
	if ( mutationObserver ) {
		mutationObserver.disconnect();
		mutationObserver = null;
	}
	if ( globalObserver ) {
		globalObserver.disconnect();
		globalObserver = null;
	}
	if ( backgroundObserver ) {
		backgroundObserver.disconnect();
		backgroundObserver = null;
	}
};

window.wppoLazyloadTeardown = teardownLazyload;
window.addEventListener( 'pagehide', teardownLazyload );
window.addEventListener( 'beforeunload', teardownLazyload );

/**
 * Apply placeholder styling (dominant color background / LQIP blur) before
 * the full image source is assigned. Called from both the IntersectionObserver
 * and scroll-fallback paths.
 *
 * @since 3.0.0
 * @param {Element} el The IMG element to prepare.
 */
const applyPlaceholderBeforeLoad = ( el ) => {
	if ( el.hasAttribute( 'data-wppo-dominant-color' ) ) {
		el.style.backgroundColor = el.getAttribute(
			'data-wppo-dominant-color'
		);
	}
	if ( el.hasAttribute( 'data-wppo-lqip' ) ) {
		el.classList.add( 'wppo-lqip-active' );
	}
};

/**
 * Create a self-removing load event handler that cleans up placeholder
 * styling after the real image has loaded.
 *
 * @since 3.0.0
 * @param {Element} el The IMG element.
 * @return {Function} The load event handler.
 */
const makePlaceholderLoadHandler = ( el ) => {
	const handler = () => {
		el.removeEventListener( 'load', handler );
		if ( el.hasAttribute( 'data-wppo-dominant-color' ) ) {
			el.style.transition = 'background-color 0.4s ease-out';
			el.style.backgroundColor = 'transparent';
			el.removeAttribute( 'data-wppo-dominant-color' );
		}
		if ( el.classList.contains( 'wppo-lqip-active' ) ) {
			el.classList.remove( 'wppo-lqip-active' );
			el.classList.add( 'wppo-lqip-loaded' );
			el.removeAttribute( 'data-wppo-lqip' );
		}
	};
	return handler;
};

/**
 * Restore the `sizes` value stashed in `data-sizes`.
 *
 * Called before `src`/`srcset` are restored so the correct source-size hint
 * is active when the browser picks a candidate. Values like
 * `auto, (max-width: 650px) 100vw, 650px` pass through unchanged in every
 * browser (non-supporting engines simply ignore the `auto` keyword). A bare
 * `auto` value is only applied in browsers that support auto-sizes; elsewhere
 * the attribute is dropped so the default sizing behaviour applies.
 *
 * @since 1.8.0
 * @param {HTMLElement} el The element to restore.
 * @return {void}
 */
const restoreSizes = ( el ) => {
	if ( ! el.hasAttribute( 'data-sizes' ) ) {
		return;
	}
	const sizes = el.getAttribute( 'data-sizes' );
	if ( sizes === 'auto' && ! AUTO_SIZES_SUPPORTED ) {
		el.removeAttribute( 'data-sizes' );
		return;
	}
	el.sizes = sizes;
	el.removeAttribute( 'data-sizes' );
};

/**
 * Release counter slots for observed elements removed from the DOM without
 * ever intersecting.
 *
 * Elements removed before entering the viewport never fire the
 * IntersectionObserver callback, so pendingLazyCount would otherwise leak
 * and gate checkCleanup (and observer teardown) forever.
 *
 * @since NEXT
 * @param {NodeList} removedNodes Nodes removed from the DOM.
 */
const releaseRemovedLazyNodes = ( removedNodes ) => {
	if ( ! removedNodes || 0 === removedNodes.length ) {
		return;
	}
	const selector = getLazySelector();
	removedNodes.forEach( ( node ) => {
		if ( ! node || 1 !== node.nodeType ) {
			return;
		}
		const candidates = [];
		if ( 'function' === typeof node.matches && node.matches( selector ) ) {
			candidates.push( node );
		}
		if ( 'function' === typeof node.querySelectorAll ) {
			node.querySelectorAll( selector ).forEach( ( child ) => {
				candidates.push( child );
			} );
		}
		candidates.forEach( ( el ) => {
			if ( observedElements.has( el ) ) {
				observedElements.delete( el );
				if ( globalObserver ) {
					globalObserver.unobserve( el );
				}
				pendingLazyCount = Math.max( 0, pendingLazyCount - 1 );
			}
		} );
	} );
};

/**
 * Check if all lazy-loadable elements have been processed, and clean up observers if so.
 *
 * Gated on pendingLazyCount so per-intersection calls are O(1); the
 * full-DOM verification scan runs only once the counter drains to zero.
 */
const checkCleanup = () => {
	if ( pendingLazyCount > 0 ) {
		return;
	}
	const remaining = document.querySelectorAll( getLazySelector() );
	if ( remaining.length === 0 ) {
		clearSafetyScan();
		if ( mutationObserver ) {
			mutationObserver.disconnect();
			mutationObserver = null;
		}
		if ( globalObserver ) {
			globalObserver.disconnect();
			globalObserver = null;
		}
		if ( backgroundObserver ) {
			backgroundObserver.disconnect();
			backgroundObserver = null;
		}
	}
};

/**
 * Whether an element is the LCP hero and must never be lazy-loaded.
 *
 * Fail-open redundancy: PHP is authoritative; JS just refuses to lazy-load
 * a hero PHP missed (fetchpriority=high, data-wppo-hero/data-wppo-lcp, or
 * loading=eager). Such images keep src/srcset intact and are never observed.
 *
 * @since NEXT
 * @param {Element} el The DOM element.
 * @return {boolean} True when the element is a hero image.
 */
const isHeroImage = ( el ) => {
	if ( ! el || el.tagName !== 'IMG' ) {
		return false;
	}
	return (
		el.getAttribute( 'fetchpriority' ) === 'high' ||
		el.hasAttribute( 'data-wppo-hero' ) ||
		el.hasAttribute( 'data-wppo-lcp' ) ||
		el.getAttribute( 'loading' ) === 'eager'
	);
};

/**
 * Eagerly restore a hero image that PHP missed (leave src/srcset intact,
 * drop data-* placeholders) so it is never lazy-loaded.
 *
 * @since NEXT
 * @param {Element} el The hero IMG element.
 */
const restoreHeroImage = ( el ) => {
	if ( el.hasAttribute( 'data-src' ) ) {
		el.src = el.getAttribute( 'data-src' );
		el.removeAttribute( 'data-src' );
	}
	if ( el.hasAttribute( 'data-srcset' ) ) {
		el.srcset = el.getAttribute( 'data-srcset' );
		el.removeAttribute( 'data-srcset' );
	}
	if ( el.getAttribute( 'loading' ) === 'lazy' ) {
		el.removeAttribute( 'loading' );
	}
};

/**
 * Register an element for lazy-load observation if it has data-* attributes.
 *
 * @since 1.0.0
 * @param {Element} el The DOM element to observe.
 */
const observeElement = ( el ) => {
	if ( ! globalObserver || ! el ) {
		return;
	}

	if ( observedElements.has( el ) ) {
		return;
	}

	// LCP hero guard: never observe a hero image; restore it eagerly instead.
	if ( isHeroImage( el ) ) {
		restoreHeroImage( el );
		observedElements.add( el );
		return;
	}

	// When native lazy is supported, restore iframes immediately instead of observing.
	if (
		USE_NATIVE_LAZY &&
		el.tagName === 'IFRAME' &&
		el.hasAttribute( 'data-src' )
	) {
		const src = el.getAttribute( 'data-src' );
		el.setAttribute( 'loading', 'lazy' );
		if ( src ) {
			if ( ! isSafeSubresourceUrl( src ) ) {
				console.warn(
					'WPPO: blocked lazy iframe src (scheme/origin not allowed):',
					src
				);
			} else {
				el.src = src;
			}
		}
		el.removeAttribute( 'data-src' );
		observedElements.add( el );
		return;
	}

	if (
		( el.tagName === 'IMG' &&
			( el.hasAttribute( 'data-src' ) ||
				el.hasAttribute( 'data-srcset' ) ) ) ||
		( el.tagName === 'IFRAME' && el.hasAttribute( 'data-src' ) ) ||
		( el.tagName === 'VIDEO' && el.classList.contains( 'wppo-lazy-video' ) )
	) {
		observedElements.add( el );
		globalObserver.observe( el );
		pendingLazyCount++;
	}
};

/**
 * Initialise lazy-loading for images, iframes, and videos.
 *
 * Uses IntersectionObserver with a 200px root margin. Falls back to
 * scroll-based detection when the Observer API is unavailable. Also
 * sets up a MutationObserver and a periodic safety scan for dynamically
 * added elements.
 *
 * @since 1.0.0
 */
const loadImages = () => {
	// When full native lazy is supported, restore iframes immediately with loading="lazy"
	// so the browser handles lazy loading natively. No IntersectionObserver needed for them.
	if ( USE_NATIVE_LAZY ) {
		document.querySelectorAll( 'iframe[data-src]' ).forEach( ( iframe ) => {
			const src = iframe.getAttribute( 'data-src' );
			iframe.setAttribute( 'loading', 'lazy' );
			if ( src ) {
				if ( ! isSafeSubresourceUrl( src ) ) {
					console.warn(
						'WPPO: blocked lazy iframe src (scheme/origin not allowed):',
						src
					);
				} else {
					iframe.src = src;
				}
			}
			iframe.removeAttribute( 'data-src' );
		} );
	}

	// When native lazy is active and no video lazy elements exist, skip observer setup entirely.
	if ( USE_NATIVE_LAZY && ! document.querySelector( getLazySelector() ) ) {
		return;
	}

	if ( 'IntersectionObserver' in window ) {
		if ( ! globalObserver ) {
			globalObserver = new IntersectionObserver(
				( entries ) => {
					entries.forEach( ( entry ) => {
						if ( entry.isIntersecting ) {
							const el = entry.target;

							if ( el.tagName === 'IMG' ) {
								const parent = el.parentNode;
								if ( parent && parent.tagName === 'PICTURE' ) {
									const sources =
										parent.querySelectorAll( 'source' );
									sources.forEach( ( s ) => {
										restoreSizes( s );
										if ( s.hasAttribute( 'data-srcset' ) ) {
											s.srcset =
												s.getAttribute( 'data-srcset' );
											s.removeAttribute( 'data-srcset' );
										}
									} );
								}

								// Apply placeholder styling before the full image loads.
								applyPlaceholderBeforeLoad( el );

								// Register handler BEFORE setting src to avoid missing cached-image load events.
								const onImgLoad =
									makePlaceholderLoadHandler( el );
								el.addEventListener( 'load', onImgLoad );

								// Restore sizes before src/srcset so the hint is active when the browser selects a candidate.
								restoreSizes( el );

								if ( el.hasAttribute( 'data-src' ) ) {
									el.src = el.getAttribute( 'data-src' );
									el.removeAttribute( 'data-src' );
								}

								if ( el.hasAttribute( 'data-srcset' ) ) {
									el.srcset =
										el.getAttribute( 'data-srcset' );
									el.removeAttribute( 'data-srcset' );
								}
							} else if ( el.tagName === 'IFRAME' ) {
								if ( el.hasAttribute( 'data-src' ) ) {
									const iframeSrc =
										el.getAttribute( 'data-src' );
									if ( iframeSrc ) {
										if (
											! isSafeSubresourceUrl( iframeSrc )
										) {
											console.warn(
												'WPPO: blocked lazy iframe src (scheme/origin not allowed):',
												iframeSrc
											);
										} else {
											el.src = iframeSrc;
										}
									}
									el.removeAttribute( 'data-src' );
								}
							} else if ( el.tagName === 'VIDEO' ) {
								if ( el.hasAttribute( 'data-src' ) ) {
									const videoSrc =
										el.getAttribute( 'data-src' );
									if ( isSafeSubresourceUrl( videoSrc ) ) {
										el.src = videoSrc;
									} else {
										console.warn(
											'WPPO: blocked lazy video src (scheme/origin not allowed):',
											videoSrc
										);
									}
									el.removeAttribute( 'data-src' );
								}
								if ( el.hasAttribute( 'data-poster' ) ) {
									const poster =
										el.getAttribute( 'data-poster' );
									if ( isSafeSubresourceUrl( poster ) ) {
										el.poster = poster;
									} else {
										console.warn(
											'WPPO: blocked lazy video poster (scheme/origin not allowed):',
											poster
										);
									}
									el.removeAttribute( 'data-poster' );
								}
								el.querySelectorAll(
									'source[data-src]'
								).forEach( ( s ) => {
									const sourceSrc =
										s.getAttribute( 'data-src' );
									if ( isSafeSubresourceUrl( sourceSrc ) ) {
										s.src = sourceSrc;
									} else {
										console.warn(
											'WPPO: blocked lazy source src (scheme/origin not allowed):',
											sourceSrc
										);
									}
									s.removeAttribute( 'data-src' );
								} );
								el.load();
								if ( el.hasAttribute( 'data-wppo-autoplay' ) ) {
									el.play().catch( () => {} );
								}
							}

							globalObserver.unobserve( el );
							// Drop the element from the observed set so a
							// later removal does not decrement the counter
							// a second time (releaseRemovedLazyNodes only
							// reconciles elements that never intersected).
							observedElements.delete( el );
							pendingLazyCount = Math.max(
								0,
								pendingLazyCount - 1
							);
							checkCleanup();
						}
					} );
				},
				{
					rootMargin: '200px',
				}
			);

			const startSafetyScan = () => {
				if ( safetyScanId !== null || window.wppoSafetyScanId ) {
					return;
				}
				let ticks = 0;
				let emptyStreak = 0;
				const MAX_TICKS = 30;
				safetyScanId = setInterval( () => {
					if ( document.hidden ) {
						return;
					}
					ticks++;
					const elements = document.querySelectorAll(
						getLazySelector()
					);
					if ( elements.length === 0 ) {
						// Fallback reconciliation: removed-without-intersecting
						// nodes are released via the MutationObserver above, but
						// if any slot leaked (e.g. observer installed late),
						// a zero-match DOM proves nothing is pending.
						pendingLazyCount = 0;
						checkCleanup();
						clearSafetyScan();
						return;
					}
					let newlyObserved = 0;
					elements.forEach( ( el ) => {
						if ( ! observedElements.has( el ) ) {
							observeElement( el );
							newlyObserved++;
						}
					} );
					if ( 0 === newlyObserved ) {
						emptyStreak++;
					} else {
						emptyStreak = 0;
					}
					if ( ticks >= MAX_TICKS || emptyStreak >= 2 ) {
						clearSafetyScan();
					}
				}, 10000 );
				// Backward-compat mirror for tests/inline snippets; the
				// module-local binding above remains the source of truth.
				window.wppoSafetyScanId = safetyScanId;
			};

			// Guard against re-entry: a re-executed module must not create a
			// second MutationObserver on document.body.
			if ( mutationObserver ) {
				return;
			}

			mutationObserver = new MutationObserver( ( mutations ) => {
				const selector = getLazySelector();
				mutations.forEach( ( mutation ) => {
					releaseRemovedLazyNodes( mutation.removedNodes );
					mutation.addedNodes.forEach( ( node ) => {
						if ( 1 !== node.nodeType ) {
							return;
						}
						if (
							node.tagName === 'IMG' ||
							node.tagName === 'IFRAME' ||
							node.tagName === 'VIDEO'
						) {
							observeElement( node );
						}
						const kids = node.querySelectorAll( selector );
						kids.forEach( ( child ) => {
							observeElement( child );
						} );
						// Single-pass: reuse matches/query results instead of
						// re-querying the DOM for the safety-scan decision.
						const matchesSelf =
							'function' === typeof node.matches &&
							node.matches( selector );
						if ( matchesSelf || kids.length > 0 ) {
							startSafetyScan();
						}
						const videoPlaceholder =
							'function' === typeof node.matches &&
							node.matches( '.wppo-video-placeholder' )
								? node
								: node.querySelector(
										'.wppo-video-placeholder'
								  );
						if ( videoPlaceholder ) {
							initVideoPlaceholders();
						}
					} );
				} );
			} );

			mutationObserver.observe( document.body, {
				childList: true,
				subtree: true,
			} );

			if ( document.querySelectorAll( getLazySelector() ).length > 0 ) {
				startSafetyScan();
			}

			document.querySelectorAll( getLazySelector() ).forEach( ( el ) => {
				observeElement( el );
			} );

			checkCleanup();
		}
	} else {
		let active = false;
		const lazyLoadFallback = () => {
			if ( active ) {
				return;
			}
			active = true;
			setTimeout( () => {
				const lazyElements = document.querySelectorAll(
					getLazySelector()
				);
				lazyElements.forEach( ( el ) => {
					// LCP hero guard (scroll fallback): restore eagerly, never lazy-load.
					if ( isHeroImage( el ) ) {
						restoreHeroImage( el );
						return;
					}
					if ( isElementInViewport( el ) ) {
						if ( el.tagName === 'VIDEO' ) {
							if ( el.hasAttribute( 'data-poster' ) ) {
								const fallbackPoster =
									el.getAttribute( 'data-poster' );
								if ( isSafeSubresourceUrl( fallbackPoster ) ) {
									el.poster = fallbackPoster;
								} else {
									console.warn(
										'WPPO: blocked lazy video poster (scheme/origin not allowed):',
										fallbackPoster
									);
								}
								el.removeAttribute( 'data-poster' );
							}
							if ( el.hasAttribute( 'data-src' ) ) {
								const fallbackVideoSrc =
									el.getAttribute( 'data-src' );
								if (
									isSafeSubresourceUrl( fallbackVideoSrc )
								) {
									el.src = fallbackVideoSrc;
								} else {
									console.warn(
										'WPPO: blocked lazy video src (scheme/origin not allowed):',
										fallbackVideoSrc
									);
								}
								el.removeAttribute( 'data-src' );
							}
							el.querySelectorAll(
								'source[data-src], source[data-srcset]'
							).forEach( ( s ) => {
								if ( s.hasAttribute( 'data-src' ) ) {
									const fbSourceSrc =
										s.getAttribute( 'data-src' );
									if ( isSafeSubresourceUrl( fbSourceSrc ) ) {
										s.src = fbSourceSrc;
									} else {
										console.warn(
											'WPPO: blocked lazy source src (scheme/origin not allowed):',
											fbSourceSrc
										);
									}
									s.removeAttribute( 'data-src' );
								}
								if ( s.hasAttribute( 'data-srcset' ) ) {
									s.srcset = s.getAttribute( 'data-srcset' );
									s.removeAttribute( 'data-srcset' );
								}
							} );
							el.load();
							if ( el.hasAttribute( 'data-wppo-autoplay' ) ) {
								el.play().catch( () => {} );
							}
							el.classList.remove( 'wppo-lazy-video' );
						} else {
							// Apply placeholder styling before the full image loads.
							applyPlaceholderBeforeLoad( el );

							// Register handler BEFORE setting src to avoid missing cached-image load events.
							const onImgLoadFallback =
								makePlaceholderLoadHandler( el );
							el.addEventListener( 'load', onImgLoadFallback );

							// Restore sizes before src/srcset so the hint is active when the browser selects a candidate.
							restoreSizes( el );

							if ( el.hasAttribute( 'data-src' ) ) {
								el.src = el.getAttribute( 'data-src' );
								el.removeAttribute( 'data-src' );
							}
							if ( el.hasAttribute( 'data-srcset' ) ) {
								el.srcset = el.getAttribute( 'data-srcset' );
								el.removeAttribute( 'data-srcset' );
							}
						}
					}
				} );
				if ( lazyElements.length === 0 ) {
					window.removeEventListener( 'scroll', lazyLoadFallback );
				}
				active = false;
			}, 200 );
		};

		/**
		 * Check whether an element is visible in the current viewport.
		 *
		 * @since 1.0.0
		 * @param {Element} el The DOM element.
		 * @return {boolean} True if the element is fully within the viewport.
		 */
		const isElementInViewport = ( el ) => {
			const rect = el.getBoundingClientRect();
			const vh =
				window.innerHeight || document.documentElement.clientHeight;
			const vw =
				window.innerWidth || document.documentElement.clientWidth;
			return (
				rect.top < vh &&
				rect.bottom > 0 &&
				rect.left < vw &&
				rect.right > 0
			);
		};

		if ( window.wppoLazyLoadFallback ) {
			window.removeEventListener( 'scroll', window.wppoLazyLoadFallback );
		}
		window.addEventListener( 'scroll', lazyLoadFallback );
		window.wppoLazyLoadFallback = lazyLoadFallback;
		lazyLoadFallback();
	}
};

/**
 * Whether the global video-placeholder image error handler has been registered.
 * Prevents duplicate listeners when initVideoPlaceholders runs multiple times.
 * @type {boolean}
 */
let videoPlaceholderErrorHandlerAdded = false;

/**
 * Initialise video placeholder click-to-load handlers.
 *
 * Attaches click event listeners to `.wppo-video-placeholder`
 * elements. On activation, injects the actual YouTube iframe with autoplay
 * within the existing placeholder container.
 *
 * @since 2.5.0
 */
const initVideoPlaceholders = () => {
	if ( ! videoPlaceholderErrorHandlerAdded ) {
		document.addEventListener(
			'error',
			( e ) => {
				if (
					e.target.tagName === 'IMG' &&
					e.target.hasAttribute( 'data-wppo-fallback' )
				) {
					const fallback =
						e.target.getAttribute( 'data-wppo-fallback' );
					if ( isSafeSubresourceUrl( fallback ) ) {
						e.target.src = fallback;
					} else {
						console.warn(
							'WPPO: blocked image fallback src (scheme/origin not allowed):',
							fallback
						);
					}
					e.target.removeAttribute( 'data-wppo-fallback' );
				}
			},
			true
		);
		videoPlaceholderErrorHandlerAdded = true;
	}

	document.querySelectorAll( '.wppo-video-placeholder' ).forEach( ( el ) => {
		if ( el.dataset.wppoInit ) {
			return;
		}
		el.dataset.wppoInit = '1';

		const loadVideo = () => {
			const src = el.getAttribute( 'data-wppo-video-src' );
			if ( ! src || el.dataset.wppoLoaded ) {
				return;
			}

			// Validate before touching state: the src comes from a data
			// attribute that may exist in untrusted markup. PHP only emits
			// placeholders for embeds it recognises (see
			// Image_Optimisation::generate_video_placeholder()); this
			// client-side check rejects javascript:/data:/blob:, non-HTTPS
			// and non-allowlisted origins outright. Invalid URLs bail out
			// silently and leave the placeholder in place.
			if ( ! isSafeVideoEmbedUrl( src ) ) {
				console.warn(
					'WPPO: blocked video placeholder src (must be a same-origin or allowlisted https embed URL):',
					src
				);
				return;
			}

			el.dataset.wppoLoaded = '1';

			// Hide play button, show loading state
			const playBtn = el.querySelector( '.wppo-video-play-btn' );
			if ( playBtn ) {
				playBtn.style.display = 'none';
			}
			el.classList.add( 'wppo-video-loading' );

			const separator = src.indexOf( '?' ) !== -1 ? '&' : '?';
			const iframe = document.createElement( 'iframe' );
			iframe.src = src + separator + 'autoplay=1&enablejsapi=1';
			iframe.allow = 'autoplay; fullscreen';
			iframe.allowFullscreen = true;
			iframe.loading = 'lazy';
			iframe.title = 'YouTube video player';
			iframe.style.cssText =
				'position:absolute;inset:0;width:100%;height:100%;border:0;';

			// Restore original iframe attributes from the PHP-stored payload
			// (Image_Optimisation::generate_video_placeholder()). Allowlist
			// only — src/width/height/style are set by this code, and a
			// tampered payload must not be able to escalate iframe
			// capabilities (e.g. overwrite allow/sandbox/referrerpolicy) or
			// smuggle event handlers. Stored-XSS hardening (issue #967): the
			// on* denylist + attribute-name shape check are defense-in-depth
			// on top of the allowlist, and values must be strings.
			const attrsJson = el.getAttribute( 'data-wppo-iframe-attrs' );
			if ( attrsJson ) {
				try {
					const attrs = JSON.parse( attrsJson );
					Object.entries( attrs ).forEach( ( [ k, v ] ) => {
						if ( typeof v !== 'string' ) {
							return;
						}
						const name = String( k ).toLowerCase();
						if ( ! /^[a-z][a-z0-9-]*$/.test( name ) ) {
							return;
						}
						if ( name.startsWith( 'on' ) ) {
							return;
						}
						if ( ! IFRAME_ATTR_ALLOWLIST.has( name ) ) {
							return;
						}
						// Capability-bearing attributes are value-checked so a
						// tampered payload cannot escalate iframe privileges.
						if ( 'allow' === name ) {
							const safe = sanitizeIframeAllow( v );
							if ( ! safe ) {
								return;
							}
							iframe.setAttribute( name, safe );
							return;
						}
						if ( 'sandbox' === name ) {
							const safe = sanitizeIframeSandbox( v );
							if ( null === safe ) {
								return;
							}
							iframe.setAttribute( name, safe );
							return;
						}
						if ( 'allowfullscreen' === name ) {
							if (
								! /^(|true|allowfullscreen)$/i.test( v.trim() )
							) {
								return;
							}
							iframe.setAttribute( name, '' );
							return;
						}
						if ( 'referrerpolicy' === name ) {
							if (
								! IFRAME_REFERRERPOLICY_TOKENS.has(
									v.trim().toLowerCase()
								)
							) {
								return;
							}
							iframe.setAttribute( name, v.trim().toLowerCase() );
							return;
						}
						if ( 'frameborder' === name ) {
							if ( ! /^(0|1)$/.test( v.trim() ) ) {
								return;
							}
							iframe.setAttribute( name, v.trim() );
							return;
						}
						iframe.setAttribute( name, v );
					} );
				} catch ( _err ) {
					console.warn( 'WPPO: invalid iframe attrs JSON', _err );
				}
			}

			// On load, remove thumbnail and show iframe
			el.appendChild( iframe );

			const onLoad = () => {
				const picture = el.querySelector( 'picture' );
				if ( picture ) {
					picture.remove();
				}
				iframe.style.opacity = '1';
				el.classList.remove( 'wppo-video-loading' );
			};

			iframe.addEventListener( 'load', onLoad );

			// Fallback: show iframe even if load event never fires
			setTimeout( () => {
				if ( el.contains( iframe ) && iframe.style.opacity !== '1' ) {
					onLoad();
				}
			}, 30000 );
		};

		el.addEventListener( 'click', loadVideo );
	} );
};

/**
 * Restore lazy CSS background-images as elements approach the viewport.
 *
 * Elements rewritten by the buffer carry the `wppo-lazy-bg` class and a
 * `data-wppo-bg` attribute holding the original background-image value. On
 * intersection the value is applied back to the element's inline style.
 *
 * @since 2.18.0
 */
const loadBackgrounds = () => {
	const elements = document.querySelectorAll( '.wppo-lazy-bg' );
	if ( ! elements.length ) {
		return;
	}

	const restoreBackground = ( el ) => {
		const background = el.getAttribute( 'data-wppo-bg' );
		if ( background ) {
			if ( isSafeBackgroundValue( background ) ) {
				el.style.backgroundImage = background;
			} else {
				console.warn(
					'WPPO: blocked lazy background value (unsupported URL/scheme):',
					background
				);
			}
		}
		el.classList.remove( 'wppo-lazy-bg' );
		el.removeAttribute( 'data-wppo-bg' );
	};

	if ( ! ( 'IntersectionObserver' in window ) ) {
		elements.forEach( restoreBackground );
		return;
	}

	backgroundObserver = new IntersectionObserver(
		( entries ) => {
			entries.forEach( ( entry ) => {
				if ( entry.isIntersecting ) {
					restoreBackground( entry.target );
					backgroundObserver.unobserve( entry.target );
				}
			} );
			if ( ! document.querySelector( '.wppo-lazy-bg' ) ) {
				backgroundObserver.disconnect();
			}
		},
		{ rootMargin: '200px' }
	);

	elements.forEach( ( el ) => backgroundObserver.observe( el ) );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => {
		loadImages();
		loadBackgrounds();
		initVideoPlaceholders();
	} );
} else {
	loadImages();
	loadBackgrounds();
	initVideoPlaceholders();
}
