/**
 * Whether a deferred script load is currently in progress.
 * @type {boolean}
 */
let scriptLoading = false;

/**
 * Cached promise for the in-progress or completed deferred script load.
 * @type {Promise<void>|null}
 */
let scriptLoadPromise = null;

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
		scriptLoading = true;

		const inlineScripts = Array.from(
			document.querySelectorAll(
				'script[type="wppo/javascript"], script[wppo-src]'
			)
		);

		try {
			for ( const script of inlineScripts ) {
				await loadScript( script );
			}
		} catch ( err ) {
			console.error( 'Error loading script:', err );
		} finally {
			scriptLoading = false;
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

if ( ! scriptLoading ) {
	const triggerEvents = [
		'mouseenter',
		'mousedown',
		'mouseover',
		'touchstart',
		'scroll',
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
 * Check if all lazy-loadable elements have been processed, and clean up observers if so.
 */
const checkCleanup = () => {
	const remaining = document.querySelectorAll(
		'img[data-src], img[data-srcset], iframe[data-src], video.wppo-lazy-video'
	);
	if ( remaining.length === 0 ) {
		if ( window.wppoSafetyScanId ) {
			clearInterval( window.wppoSafetyScanId );
			window.wppoSafetyScanId = null;
		}
		if ( mutationObserver ) {
			mutationObserver.disconnect();
			mutationObserver = null;
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
 * Uses IntersectionObserver with a 600px root margin. Falls back to
 * scroll-based detection when the Observer API is unavailable. Also
 * sets up a MutationObserver and a periodic safety scan for dynamically
 * added elements.
 *
 * @since 1.0.0
 */
const loadImages = () => {
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
					rootMargin: '600px', // More aggressive margin for marquees
				}
			);

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
							node.querySelectorAll(
								'img[data-src], img[data-srcset], iframe[data-src], video.wppo-lazy-video'
							).forEach( ( child ) => {
								observeElement( child );
							} );
						}
					} );
				} );
			} );

			mutationObserver.observe( document.body, {
				childList: true,
				subtree: true,
			} );

			// Periodic Safety Scan for unobserved dynamic content
			if ( ! window.wppoSafetyScanId ) {
				window.wppoSafetyScanId = setInterval( () => {
					const elements = document.querySelectorAll(
						'img[data-src], img[data-srcset], iframe[data-src], video.wppo-lazy-video'
					);
					if ( elements.length === 0 ) {
						clearInterval( window.wppoSafetyScanId );
						window.wppoSafetyScanId = null;
						if ( mutationObserver ) {
							mutationObserver.disconnect();
							mutationObserver = null;
						}
						return;
					}
					elements.forEach( ( el ) => {
						if ( ! observedElements.has( el ) ) {
							observeElement( el );
						}
					} );
				}, 10000 );
			}
		}

		document
			.querySelectorAll(
				'img[data-src], img[data-srcset], iframe[data-src], video.wppo-lazy-video'
			)
			.forEach( ( el ) => {
				observeElement( el );
			} );

		checkCleanup();
	} else {
		let active = false;
		const lazyLoadFallback = () => {
			if ( active ) {
				return;
			}
			active = true;
			setTimeout( () => {
				const lazyElements = document.querySelectorAll(
					'img[data-src], img[data-srcset], iframe[data-src], video.wppo-lazy-video'
				);
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

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', loadImages );
} else {
	loadImages();
}
