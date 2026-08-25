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
 * @type {string}
 */
const LAZY_SELECTOR = USE_NATIVE_LAZY
	? 'video.wppo-lazy-video'
	: 'img[data-src], img[data-srcset], iframe[data-src], video.wppo-lazy-video';

/**
 * Load a single deferred script element.
 *
 * Restores the original `src` and `type` attributes, then resolves
 * once the script has loaded or errors.
 *
 * @since 1.0.0
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
			// External deferred script: create a replacement script node, copy original attributes,
			// assign the deferred src, and swap it into the DOM.
			const replacement = document.createElement( 'script' );

			Array.from( script.attributes ).forEach( ( attr ) => {
				replacement.setAttribute( attr.name, attr.value );
			} );

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
			// a fresh element. Copy all attributes and content to the new node, swap it
			// into the DOM, and resolve once it has been processed.
			const replacement = document.createElement( 'script' );

			// Copy attributes from the original node to the replacement.
			Array.from( script.attributes ).forEach( ( attr ) => {
				replacement.setAttribute( attr.name, attr.value );
			} );

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
				loadScriptsByPriority( toLoad ).catch( ( err ) =>
					console.error( 'Error loading viewport scripts:', err )
				);
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
 * Check if all lazy-loadable elements have been processed, and clean up observers if so.
 */
const checkCleanup = () => {
	const remaining = document.querySelectorAll( LAZY_SELECTOR );
	if ( remaining.length === 0 ) {
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

	// When native lazy is supported, restore iframes immediately instead of observing.
	if (
		USE_NATIVE_LAZY &&
		el.tagName === 'IFRAME' &&
		el.hasAttribute( 'data-src' )
	) {
		const src = el.getAttribute( 'data-src' );
		el.setAttribute( 'loading', 'lazy' );
		if ( src ) {
			el.src = src;
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
				iframe.src = src;
			}
			iframe.removeAttribute( 'data-src' );
		} );
	}

	// When native lazy is active and no video lazy elements exist, skip observer setup entirely.
	if ( USE_NATIVE_LAZY && ! document.querySelector( LAZY_SELECTOR ) ) {
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
										el.src = iframeSrc;
									}
									el.removeAttribute( 'data-src' );
								}
							} else if ( el.tagName === 'VIDEO' ) {
								if ( el.hasAttribute( 'data-src' ) ) {
									el.src = el.getAttribute( 'data-src' );
									el.removeAttribute( 'data-src' );
								}
								if ( el.hasAttribute( 'data-poster' ) ) {
									el.poster =
										el.getAttribute( 'data-poster' );
									el.removeAttribute( 'data-poster' );
								}
								el.querySelectorAll(
									'source[data-src]'
								).forEach( ( s ) => {
									s.src = s.getAttribute( 'data-src' );
									s.removeAttribute( 'data-src' );
								} );
								el.load();
								if ( el.hasAttribute( 'data-wppo-autoplay' ) ) {
									el.play().catch( () => {} );
								}
							}

							globalObserver.unobserve( el );
							checkCleanup();
						}
					} );
				},
				{
					rootMargin: '200px',
				}
			);

			const startSafetyScan = () => {
				if ( window.wppoSafetyScanId ) {
					return;
				}
				window.wppoSafetyScanId = setInterval( () => {
					const elements = document.querySelectorAll( LAZY_SELECTOR );
					if ( elements.length === 0 ) {
						clearInterval( window.wppoSafetyScanId );
						window.wppoSafetyScanId = null;
						return;
					}
					elements.forEach( ( el ) => {
						if ( ! observedElements.has( el ) ) {
							observeElement( el );
						}
					} );
				}, 10000 );
			};

			// Guard against re-entry: a re-executed module must not create a
			// second MutationObserver on document.body.
			if ( mutationObserver ) {
				return;
			}

			mutationObserver = new MutationObserver( ( mutations ) => {
				mutations.forEach( ( mutation ) => {
					mutation.addedNodes.forEach( ( node ) => {
						if ( node.nodeType === 1 ) {
							if (
								node.tagName === 'IMG' ||
								node.tagName === 'IFRAME' ||
								node.tagName === 'VIDEO'
							) {
								observeElement( node );
							}
							node.querySelectorAll( LAZY_SELECTOR ).forEach(
								( child ) => {
									observeElement( child );
								}
							);
							if (
								node.matches( LAZY_SELECTOR ) ||
								node.querySelector( LAZY_SELECTOR )
							) {
								startSafetyScan();
							}
							if (
								node.matches( '.wppo-video-placeholder' ) ||
								node.querySelector( '.wppo-video-placeholder' )
							) {
								initVideoPlaceholders();
							}
						}
					} );
				} );
			} );

			mutationObserver.observe( document.body, {
				childList: true,
				subtree: true,
			} );

			if ( document.querySelectorAll( LAZY_SELECTOR ).length > 0 ) {
				startSafetyScan();
			}

			document.querySelectorAll( LAZY_SELECTOR ).forEach( ( el ) => {
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
				const lazyElements = document.querySelectorAll( LAZY_SELECTOR );
				lazyElements.forEach( ( el ) => {
					if ( isElementInViewport( el ) ) {
						if ( el.tagName === 'VIDEO' ) {
							if ( el.hasAttribute( 'data-poster' ) ) {
								el.poster = el.getAttribute( 'data-poster' );
								el.removeAttribute( 'data-poster' );
							}
							if ( el.hasAttribute( 'data-src' ) ) {
								el.src = el.getAttribute( 'data-src' );
								el.removeAttribute( 'data-src' );
							}
							el.querySelectorAll(
								'source[data-src], source[data-srcset]'
							).forEach( ( s ) => {
								if ( s.hasAttribute( 'data-src' ) ) {
									s.src = s.getAttribute( 'data-src' );
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
					e.target.src =
						e.target.getAttribute( 'data-wppo-fallback' );
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

			// Restore original iframe attributes (sandbox, referrerpolicy, id, etc.)
			const attrsJson = el.getAttribute( 'data-wppo-iframe-attrs' );
			if ( attrsJson ) {
				try {
					const attrs = JSON.parse( attrsJson );
					Object.entries( attrs ).forEach( ( [ k, v ] ) => {
						if (
							! [ 'src', 'width', 'height', 'style' ].includes(
								k
							)
						) {
							iframe.setAttribute( k, v );
						}
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
			el.style.backgroundImage = background;
		}
		el.classList.remove( 'wppo-lazy-bg' );
		el.removeAttribute( 'data-wppo-bg' );
	};

	if ( ! ( 'IntersectionObserver' in window ) ) {
		elements.forEach( restoreBackground );
		return;
	}

	const backgroundObserver = new IntersectionObserver(
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
