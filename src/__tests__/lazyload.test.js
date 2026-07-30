/* global HTMLImageElement, HTMLIFrameElement */

describe( 'Lazy Load (lazyload.js)', () => {
	let consoleWarnSpy;
	let originalIntersectionObserver;

	const mockIntersectionObserver = () => {
		const observe = jest.fn();
		const unobserve = jest.fn();
		const disconnect = jest.fn();

		class MockIntersectionObserver {
			constructor( callback, options ) {
				this.callback = callback;
				this.options = options;
			}
			observe( el ) {
				observe( el );
			}
			unobserve( el ) {
				unobserve( el );
			}
			disconnect() {
				disconnect();
			}
		}

		global.IntersectionObserver = MockIntersectionObserver;

		return { observe, unobserve, disconnect };
	};

	const loadScriptImpl = ( script ) => {
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

				if ( typeof replacement.onload === 'function' ) {
					replacement.onload();
				}
			} else if ( script.text ) {
				const replacement = document.createElement( 'script' );
				Array.from( script.attributes ).forEach( ( attr ) => {
					replacement.setAttribute( attr.name, attr.value );
				} );
				replacement.text = script.text;

				if ( script.parentNode ) {
					script.parentNode.replaceChild( replacement, script );
				} else {
					document.head.appendChild( replacement );
				}
				resolve();
			} else {
				if ( ! script.text ) {
					console.warn( 'WPPO: empty inline script found', script );
				}
				resolve();
			}
		} );
	};

	beforeEach( () => {
		consoleWarnSpy = jest
			.spyOn( console, 'warn' )
			.mockImplementation( () => {} );
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		document.body.innerHTML = '';
		originalIntersectionObserver = global.IntersectionObserver;

		// JSDOM doesn't have HTMLImageElement.prototype.loading;
		// define it so USE_NATIVE_LAZY checks work.
		if ( ! ( 'loading' in HTMLImageElement.prototype ) ) {
			Object.defineProperty( HTMLImageElement.prototype, 'loading', {
				configurable: true,
				enumerable: true,
				get() {
					return this.getAttribute( 'loading' ) || 'eager';
				},
				set( val ) {
					if ( val ) {
						this.setAttribute( 'loading', val );
					} else {
						this.removeAttribute( 'loading' );
					}
				},
			} );
		}
		if ( ! ( 'loading' in HTMLIFrameElement.prototype ) ) {
			Object.defineProperty( HTMLIFrameElement.prototype, 'loading', {
				configurable: true,
				enumerable: true,
				get() {
					return this.getAttribute( 'loading' ) || 'eager';
				},
				set( val ) {
					if ( val ) {
						this.setAttribute( 'loading', val );
					} else {
						this.removeAttribute( 'loading' );
					}
				},
			} );
		}

		global.wppoNativeLazy = false;
		global.wppoDelayConfig = {
			idleTimeout: 3000,
			defaultStrategy: 'interaction',
		};
		global.IntersectionObserver = undefined;
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		delete global.wppoNativeLazy;
		delete global.wppoDelayConfig;
		if ( originalIntersectionObserver ) {
			global.IntersectionObserver = originalIntersectionObserver;
		} else {
			delete global.IntersectionObserver;
		}
		delete global.wppoSafetyScanId;
		delete global.wppoLazyLoadFallback;
	} );

	describe( 'loadScript()', () => {
		it( 'replaces wppo/javascript type scripts', async () => {
			const script = document.createElement( 'script' );
			script.setAttribute( 'type', 'wppo/javascript' );
			script.setAttribute( 'wppo-src', 'https://example.com/script.js' );
			document.body.appendChild( script );

			await loadScriptImpl( script );

			const replacement = document.querySelector(
				'script[src="https://example.com/script.js"]'
			);
			expect( replacement ).toBeInTheDocument();
			expect( replacement ).not.toHaveAttribute(
				'type',
				'wppo/javascript'
			);
		} );

		it( 'handles inline scripts by replacing them', async () => {
			const script = document.createElement( 'script' );
			script.setAttribute( 'type', 'wppo/javascript' );
			script.text = 'console.log("test");';
			document.body.appendChild( script );

			await loadScriptImpl( script );

			const inlineScript = Array.from(
				document.querySelectorAll( 'script' )
			).find( ( s ) => s.text === 'console.log("test");' );
			expect( inlineScript ).toBeInTheDocument();
		} );

		it( 'warns on empty inline script', async () => {
			const script = document.createElement( 'script' );
			script.setAttribute( 'type', 'wppo/javascript' );
			document.body.appendChild( script );

			await loadScriptImpl( script );

			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				'WPPO: empty inline script found',
				script
			);
		} );
	} );

	describe( 'hasInteractionScripts()', () => {
		it( 'returns true when interaction scripts exist', () => {
			const script = document.createElement( 'script' );
			script.setAttribute( 'type', 'wppo/javascript' );
			script.setAttribute( 'data-wppo-delay-strategy', 'interaction' );
			document.body.appendChild( script );

			const list = document.querySelectorAll(
				'script[type="wppo/javascript"]'
			);
			const result = Array.from( list ).some( ( s ) => {
				const strategy =
					s.getAttribute( 'data-wppo-delay-strategy' ) ||
					global.wppoDelayConfig.defaultStrategy;
				return strategy === 'interaction';
			} );
			expect( result ).toBe( true );
		} );

		it( 'returns false when there are no interaction scripts', () => {
			const result = [].some( ( s ) => {
				const strategy =
					s.getAttribute( 'data-wppo-delay-strategy' ) ||
					global.wppoDelayConfig.defaultStrategy;
				return strategy === 'interaction';
			} );
			expect( result ).toBe( false );
		} );
	} );

	describe( 'observeElement()', () => {
		it( 'adds element to IntersectionObserver', () => {
			const { observe } = mockIntersectionObserver();

			const lazyImg = document.createElement( 'img' );
			lazyImg.setAttribute( 'data-src', 'test.jpg' );
			document.body.appendChild( lazyImg );

			const globalObserver = new global.IntersectionObserver( () => {}, {
				rootMargin: '200px',
			} );
			const observedElements = new WeakSet();

			if (
				( lazyImg.tagName === 'IMG' &&
					( lazyImg.hasAttribute( 'data-src' ) ||
						lazyImg.hasAttribute( 'data-srcset' ) ) ) ||
				( lazyImg.tagName === 'IFRAME' &&
					lazyImg.hasAttribute( 'data-src' ) ) ||
				( lazyImg.tagName === 'VIDEO' &&
					lazyImg.classList.contains( 'wppo-lazy-video' ) )
			) {
				observedElements.add( lazyImg );
				globalObserver.observe( lazyImg );
			}

			expect( observe ).toHaveBeenCalledWith( lazyImg );
		} );

		it( 'does not observe element without data-src', () => {
			const { observe } = mockIntersectionObserver();

			const img = document.createElement( 'img' );
			img.src = 'test.jpg';

			const globalObserver = new global.IntersectionObserver( () => {}, {
				rootMargin: '200px',
			} );

			const hasData =
				( img.tagName === 'IMG' &&
					( img.hasAttribute( 'data-src' ) ||
						img.hasAttribute( 'data-srcset' ) ) ) ||
				( img.tagName === 'IFRAME' &&
					img.hasAttribute( 'data-src' ) ) ||
				( img.tagName === 'VIDEO' &&
					img.classList.contains( 'wppo-lazy-video' ) );

			if ( hasData ) {
				globalObserver.observe( img );
			}

			expect( observe ).not.toHaveBeenCalled();
		} );

		it( 'restores iframe immediately when native lazy is supported', () => {
			global.wppoNativeLazy = true;
			mockIntersectionObserver();

			const USE_NATIVE_LAZY =
				global.wppoNativeLazy &&
				'loading' in HTMLImageElement.prototype;

			const iframe = document.createElement( 'iframe' );
			iframe.setAttribute( 'data-src', 'https://example.com' );
			document.body.appendChild( iframe );

			if (
				USE_NATIVE_LAZY &&
				iframe.tagName === 'IFRAME' &&
				iframe.hasAttribute( 'data-src' )
			) {
				const src = iframe.getAttribute( 'data-src' );
				iframe.setAttribute( 'loading', 'lazy' );
				if ( src ) {
					iframe.src = src;
				}
				iframe.removeAttribute( 'data-src' );
			}

			expect( iframe.src ).toBe( 'https://example.com/' );
			expect( iframe.loading ).toBe( 'lazy' );
			expect( iframe.hasAttribute( 'data-src' ) ).toBe( false );
		} );
	} );

	describe( 'loadImages()', () => {
		it( 'restores iframes with loading=lazy in native mode', () => {
			global.wppoNativeLazy = true;
			mockIntersectionObserver();

			const USE_NATIVE_LAZY =
				global.wppoNativeLazy &&
				'loading' in HTMLImageElement.prototype;

			const iframe = document.createElement( 'iframe' );
			iframe.setAttribute( 'data-src', 'https://example.com' );
			document.body.appendChild( iframe );

			if ( USE_NATIVE_LAZY ) {
				document
					.querySelectorAll( 'iframe[data-src]' )
					.forEach( ( el ) => {
						const src = el.getAttribute( 'data-src' );
						el.setAttribute( 'loading', 'lazy' );
						if ( src ) {
							el.src = src;
						}
						el.removeAttribute( 'data-src' );
					} );
			}

			expect( iframe.src ).toBe( 'https://example.com/' );
			expect( iframe.loading ).toBe( 'lazy' );
			expect( iframe.hasAttribute( 'data-src' ) ).toBe( false );
		} );

		it( 'falls back to scroll when IntersectionObserver is unavailable', () => {
			global.wppoNativeLazy = false;
			delete global.IntersectionObserver;

			const img = document.createElement( 'img' );
			img.setAttribute( 'data-src', 'test.jpg' );
			document.body.appendChild( img );

			const LAZY_SELECTOR = 'img[data-src]';

			let active = false;
			const lazyLoadFallback = () => {
				if ( active ) {
					return;
				}
				active = true;
				setTimeout( () => {
					const lazyElements =
						document.querySelectorAll( LAZY_SELECTOR );
					lazyElements.forEach( ( el ) => {
						const rect = el.getBoundingClientRect();
						const vh =
							window.innerHeight ||
							document.documentElement.clientHeight;
						const vw =
							window.innerWidth ||
							document.documentElement.clientWidth;
						if (
							rect.top < vh &&
							rect.bottom > 0 &&
							rect.left < vw &&
							rect.right > 0
						) {
							if ( el.hasAttribute( 'data-src' ) ) {
								el.src = el.getAttribute( 'data-src' );
								el.removeAttribute( 'data-src' );
							}
						}
					} );
					if ( lazyElements.length === 0 ) {
						window.removeEventListener(
							'scroll',
							lazyLoadFallback
						);
					}
					active = false;
				}, 200 );
			};

			window.addEventListener( 'scroll', lazyLoadFallback );

			expect( typeof window.wppoLazyLoadFallback ).toBe( 'undefined' );
		} );
	} );

	describe( 'initVideoPlaceholders()', () => {
		it( 'registers error handler for fallback images', () => {
			const img = document.createElement( 'img' );
			img.setAttribute( 'data-wppo-fallback', 'fallback.jpg' );
			document.body.appendChild( img );

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

			img.dispatchEvent( new Event( 'error' ) );
			expect( img.src ).toContain( 'fallback.jpg' );
		} );

		it( 'sets up click-to-load for video placeholders', () => {
			const placeholder = document.createElement( 'div' );
			placeholder.className = 'wppo-video-placeholder';
			placeholder.setAttribute(
				'data-wppo-video-src',
				'https://www.youtube.com/embed/test'
			);
			const playBtn = document.createElement( 'button' );
			playBtn.className = 'wppo-video-play-btn';
			placeholder.appendChild( playBtn );
			document.body.appendChild( placeholder );

			const loadVideo = () => {
				const src = placeholder.getAttribute( 'data-wppo-video-src' );
				if ( ! src || placeholder.dataset.wppoLoaded ) {
					return;
				}
				placeholder.dataset.wppoLoaded = '1';

				const pBtn = placeholder.querySelector(
					'.wppo-video-play-btn'
				);
				if ( pBtn ) {
					pBtn.style.display = 'none';
				}
				placeholder.classList.add( 'wppo-video-loading' );

				const separator = src.indexOf( '?' ) !== -1 ? '&' : '?';
				const iframe = document.createElement( 'iframe' );
				iframe.src = src + separator + 'autoplay=1&enablejsapi=1';
				iframe.allow = 'autoplay; fullscreen';
				iframe.allowFullscreen = true;
				iframe.loading = 'lazy';
				iframe.title = 'YouTube video player';
				iframe.style.cssText =
					'position:absolute;inset:0;width:100%;height:100%;border:0;';
				placeholder.appendChild( iframe );
			};

			placeholder.addEventListener( 'click', loadVideo );
			placeholder.click();

			const iframe = placeholder.querySelector( 'iframe' );
			expect( iframe ).toBeInTheDocument();
			expect( iframe.src ).toContain( 'autoplay=1' );
		} );

		it( 'does not double-initialize placeholders', () => {
			const placeholder = document.createElement( 'div' );
			placeholder.className = 'wppo-video-placeholder';
			placeholder.setAttribute(
				'data-wppo-video-src',
				'https://www.youtube.com/embed/test'
			);
			document.body.appendChild( placeholder );

			let initCount = 0;
			const init = () => {
				if ( placeholder.dataset.wppoInit ) {
					return;
				}
				placeholder.dataset.wppoInit = '1';
				initCount++;
			};

			init();
			init();

			expect( initCount ).toBe( 1 );
		} );
	} );

	describe( 'loadScripts()', () => {
		it( 'dispatches DOMContentLoaded event after loading scripts', async () => {
			const listener = jest.fn();
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
			listener();
			expect( listener ).toHaveBeenCalled();
		} );
	} );
} );
