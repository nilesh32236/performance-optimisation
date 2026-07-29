/**
 * Cached promise for the in-progress or completed deferred script load.
 * @type {Promise<void>|null}
 */
let scriptLoadPromise = null;

/**
 * Whether native lazy loading is active (loading="lazy" on img/iframe instead of IntersectionObserver).
 * Set by PHP via wp_add_inline_script before the lazyload script.
 * @type {boolean}
 */
const useNativeLazy =
	typeof window.wppoNativeLazy !== 'undefined' && window.wppoNativeLazy;

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
 * @type {{ idleTimeout: number, defaultStrategy: string }}
 */
const delayConfig = window.wppoDelayConfig || {
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
		for ( const script of groups[ level ] ) {
			await loadScript( script );
		}
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
const loadIdleScripts = () => {
	const idleScripts = document.querySelectorAll(
		'script[data-wppo-delay-strategy="idle"]'
	);
	if ( idleScripts.length > 0 ) {
		loadScriptsByPriority( idleScripts );
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
			entries.forEach( ( entry ) => {
				if ( entry.isIntersecting ) {
					const script = entry.target;
					observer.unobserve( script );
					loadScript( script );
				}
			} );
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
 * @since 3.8.0
 * @return {boolean} Whether any delayed scripts use the 'interaction' strategy.
 */
const hasInteractionScripts = () => {
	const allDelayed = document.querySelectorAll(
		'script[type="wppo/javascript"], script[wppo-src]'
	);
	return Array.from( allDelayed ).some( ( script ) => {
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
		setTimeout( loadIdleScripts, delayConfig.idleTimeout );
	}
}

// Observe viewport scripts.
observeViewportScripts();

// Only register interaction event listeners if there are scripts using interaction strategy.
if ( delayedScripts.length > 0 && hasInteractionScripts() ) {
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
} else if ( delayedScripts.length > 0 ) {
	// All scripts are idle or viewport — no interaction listener needed.
	// But still load any interaction-default scripts immediately if no idle/viewport strategies matched.
	if (
		! hasInteractionScripts() &&
		! document.querySelector( 'script[data-wppo-delay-strategy]' )
	) {
		// Scripts with no strategy attribute default to interaction if none have strategies.
		loadScripts();
	}
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
										if ( s.hasAttribute( 'data-sizes' ) ) {
											s.sizes =
												s.getAttribute( 'data-sizes' );
											s.removeAttribute( 'data-sizes' );
										}
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

								if ( el.hasAttribute( 'data-sizes' ) ) {
									el.sizes = el.getAttribute( 'data-sizes' );
									el.removeAttribute( 'data-sizes' );
								}

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

							if ( el.hasAttribute( 'data-sizes' ) ) {
								el.sizes = el.getAttribute( 'data-sizes' );
								el.removeAttribute( 'data-sizes' );
							}
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
				} catch ( _err ) {} // eslint-disable-line no-unused-vars
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

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => {
		loadImages();
		initVideoPlaceholders();
	} );
} else {
	loadImages();
	initVideoPlaceholders();
}
