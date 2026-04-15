let scriptLoading = false;
let scriptLoadPromise = null;

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
			script.removeAttribute( 'wppo-src' );
			script.setAttribute( 'src', src );

			script.onload = resolve;
			script.onerror = reject;
		} else {
			if ( 'wppo/javascript' === script.getAttribute( 'type' ) ) {
				script.removeAttribute( 'type' );
			}

			const typeAttr = script.getAttribute( 'wppo-type' );
			if ( typeAttr ) {
				script.removeAttribute( 'wppo-type' );
				script.setAttribute( 'type', typeAttr );
			}

			try {
				if ( script.text ) {
					if ( ! script.src ) {
						const base64Script = btoa(
							unescape( encodeURIComponent( script.text ) )
						);
						script.setAttribute(
							'src',
							`data:text/javascript;base64,${ base64Script }`
						);
					}
				}
				resolve();
			} catch ( err ) {
				reject( `Error encoding inline script: ${ err.message }` );
			}
		}
	} );
};

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

		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		window.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		window.dispatchEvent( new Event( 'load' ) );
		window.dispatchEvent( new Event( 'pageshow' ) );

		if ( typeof jQuery !== 'undefined' ) {
			jQuery( document ).triggerHandler( 'ready' );
		}

		// Refresh GSAP ScrollTrigger if active
		if ( typeof ScrollTrigger !== 'undefined' ) {
			ScrollTrigger.refresh();
		} else if ( window.gsap && window.gsap.utils ) {
			const st = window.gsap.plugins ? window.gsap.plugins.scrollTrigger : null;
			if ( st && st.refresh ) st.refresh();
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

let globalObserver = null;
const observedElements = new WeakSet();

const observeElement = ( el ) => {
	if ( ! globalObserver || ! el ) return;

	if ( observedElements.has( el ) ) return;

	if (
		( el.tagName === 'IMG' && ( el.hasAttribute( 'data-src' ) || el.hasAttribute( 'data-srcset' ) ) ) ||
		( el.tagName === 'IFRAME' && el.hasAttribute( 'data-src' ) ) ||
		( el.tagName === 'VIDEO' && el.classList.contains( 'wppo-lazy-video' ) )
	) {
		console.log( '[WPPO Lazyload] Observed:', el.tagName, el.dataset.src || el.src );
		observedElements.add( el );
		globalObserver.observe( el );
	}
};

const loadImages = () => {
	if ( 'IntersectionObserver' in window ) {
		if ( ! globalObserver ) {
			globalObserver = new IntersectionObserver(
				( entries ) => {
					entries.forEach( ( entry ) => {
						if ( entry.isIntersecting ) {
							const el = entry.target;
							console.log( '[WPPO Lazyload] Intersection Triggered for:', el.tagName, el.src || el.dataset.src );

							let isPicture = false;

							if ( el.tagName === 'IMG' ) {
								const parent = el.parentNode;
								if ( parent && parent.tagName === 'PICTURE' ) {
									isPicture = true;
									console.log( '[WPPO Lazyload] Processing Picture Sources...' );
									const sources = parent.querySelectorAll( 'source' );
									sources.forEach( ( s ) => {
										if ( s.hasAttribute( 'data-sizes' ) ) {
											console.log( ' - Source sizes swap:', s.dataset.sizes );
											s.sizes = s.getAttribute( 'data-sizes' );
											s.removeAttribute( 'data-sizes' );
										}
										if ( s.hasAttribute( 'data-srcset' ) ) {
											console.log( ' - Source srcset swap:', s.dataset.srcset );
											s.srcset = s.getAttribute( 'data-srcset' );
											s.removeAttribute( 'data-srcset' );
										}
									} );
								}

								if ( el.hasAttribute( 'data-sizes' ) ) {
									console.log( ' - Img sizes swap:', el.dataset.sizes );
									el.sizes = el.getAttribute( 'data-sizes' );
									el.removeAttribute( 'data-sizes' );
								}

								if ( el.hasAttribute( 'data-src' ) ) {
									console.log( ' - Img src swap:', el.dataset.src );
									el.src = el.getAttribute( 'data-src' );
									el.removeAttribute( 'data-src' );
								}

								if ( el.hasAttribute( 'data-srcset' ) ) {
									console.log( ' - Img srcset swap:', el.dataset.srcset );
									el.srcset = el.getAttribute( 'data-srcset' );
									el.removeAttribute( 'data-srcset' );
								}

								if ( isPicture ) {
									// More aggressive picture re-evaluation
									const currentSrc = el.src;
									el.removeAttribute( 'src' );
									el.src = currentSrc;
								}
							} else if ( el.tagName === 'IFRAME' ) {
								if ( el.hasAttribute( 'data-src' ) ) {
									el.src = el.getAttribute( 'data-src' );
									el.removeAttribute( 'data-src' );
								}
							} else if ( el.tagName === 'VIDEO' ) {
								if ( el.hasAttribute( 'data-src' ) ) {
									el.src = el.getAttribute( 'data-src' );
									el.removeAttribute( 'data-src' );
								}
								el.querySelectorAll( 'source[data-src]' ).forEach(
									( s ) => {
										s.src = s.getAttribute( 'data-src' );
										s.removeAttribute( 'data-src' );
									}
								);
								el.load();
								el.play().catch( () => {} );
							}

							globalObserver.unobserve( el );
						}
					} );
				},
				{
					rootMargin: '600px', // More aggressive margin for marquees
				}
			);

			const mutationObserver = new MutationObserver( ( mutations ) => {
				mutations.forEach( ( mutation ) => {
					mutation.addedNodes.forEach( ( node ) => {
						if ( node.nodeType === 1 ) {
							if ( node.tagName === 'IMG' || node.tagName === 'IFRAME' || node.tagName === 'VIDEO' ) {
								observeElement( node );
							}
							node.querySelectorAll( 'img[data-src], img[data-srcset], iframe[data-src], video.wppo-lazy-video' ).forEach( ( child ) => {
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
			setInterval( () => {
				document.querySelectorAll( 'img[data-src], img[data-srcset], iframe[data-src], video.wppo-lazy-video' ).forEach( ( el ) => {
					observeElement( el );
				} );
			}, 2000 );
		}

		document.querySelectorAll( 'img[data-src], img[data-srcset], iframe[data-src], video.wppo-lazy-video' ).forEach( ( el ) => {
			observeElement( el );
		} );

	} else {
		const lazyLoadFallback = () => {
			const lazyElements = document.querySelectorAll( 'img[data-src], img[data-srcset], iframe[data-src]' );
			lazyElements.forEach( ( el ) => {
				if ( isElementInViewport( el ) ) {
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
			} );
		};

		const isElementInViewport = ( el ) => {
			const rect = el.getBoundingClientRect();
			return (
				rect.top >= 0 &&
				rect.left >= 0 &&
				rect.bottom <= ( window.innerHeight || document.documentElement.clientHeight ) &&
				rect.right <= ( window.innerWidth || document.documentElement.clientWidth )
			);
		};

		window.addEventListener( 'scroll', lazyLoadFallback );
		lazyLoadFallback();
	}
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', loadImages );
} else {
	loadImages();
}
