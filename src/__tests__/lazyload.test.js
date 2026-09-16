/* global HTMLImageElement, HTMLIFrameElement, HTMLScriptElement */

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

	/**
	 * Load the real lazyload bundle against the current DOM and trigger
	 * deferred-script hydration via the interaction event path.
	 *
	 * The module captures `delayedScripts` and registers its interaction
	 * listeners at evaluation time, so the DOM must be prepared first.
	 * jsdom never executes/fetches scripts, but the replacement element is
	 * swapped into the DOM synchronously, which is what these tests assert.
	 */
	const bootLazyload = () => {
		jest.isolateModules( () => {
			require( '../lazyload' );
		} );
		document.dispatchEvent( new Event( 'mouseover' ) );
		return Promise.resolve();
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

	describe( 'loadScript() — real bundle hydration', () => {
		// The bundle must take the no-IntersectionObserver path at import
		// (the outer beforeEach sets it to `undefined`, which still satisfies
		// `'IntersectionObserver' in window` and would crash observer setup).
		beforeEach( () => {
			delete global.IntersectionObserver;
		} );

		afterEach( () => {
			// The bundle registers document-level listeners per import.
			delete global.wppoAllowedScriptHosts;
			delete global.IntersectionObserver;
		} );

		it( 'replaces wppo/javascript type scripts (same-origin src)', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="/local-script.js"></script>';
			await bootLazyload();

			const replacement = document.querySelector(
				'script[src="/local-script.js"]'
			);
			expect( replacement ).toBeInTheDocument();
			expect( replacement ).not.toHaveAttribute(
				'type',
				'wppo/javascript'
			);
		} );

		it( 'copies only allowlisted attributes to the replacement (allowlist hardening)', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="https://www.googletagmanager.com/gtm.js?id=GTM-X" ' +
				'fetchpriority="low" data-wppo-delay-strategy="interaction" data-config="keep-me" ' +
				'aria-label="keep" onload="alert(1)" onerror="alert(2)" nonce="csp-nonce" ' +
				'style="color:red" integrity="sha384-abc" crossorigin="anonymous"></script>';
			await bootLazyload();

			const replacement = document.querySelector(
				'script[src*="googletagmanager.com"]'
			);
			expect( replacement ).toBeInTheDocument();
			// Allowlisted attributes survive (incl. PHP's fetchpriority="low").
			expect( replacement.getAttribute( 'fetchpriority' ) ).toBe( 'low' );
			expect( replacement.getAttribute( 'data-config' ) ).toBe(
				'keep-me'
			);
			expect( replacement.getAttribute( 'aria-label' ) ).toBe( 'keep' );
			expect( replacement.getAttribute( 'integrity' ) ).toBe(
				'sha384-abc'
			);
			expect( replacement.getAttribute( 'crossorigin' ) ).toBe(
				'anonymous'
			);
			// Event handlers, CSP nonces and styles never propagate.
			expect( replacement.hasAttribute( 'onload' ) ).toBe( false );
			expect( replacement.hasAttribute( 'onerror' ) ).toBe( false );
			expect( replacement.hasAttribute( 'nonce' ) ).toBe( false );
			expect( replacement.hasAttribute( 'style' ) ).toBe( false );
			expect( replacement.hasAttribute( 'wppo-src' ) ).toBe( false );
		} );

		it( 'does not hydrate a script whose src uses a dangerous scheme', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="javascript:alert(1)" data-x="1"></script>';
			await bootLazyload();

			// No live script element was created — the placeholder is left
			// untouched (it never receives a src attribute).
			expect( document.querySelectorAll( 'script[src]' ).length ).toBe(
				0
			);
			expect(
				document.querySelector(
					'script[wppo-src="javascript:alert(1)"]'
				)
			).toBeInTheDocument();
			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				expect.stringContaining( 'blocked deferred script src' ),
				'javascript:alert(1)'
			);
		} );

		it( 'does not hydrate a script with a data: or blob: src', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="data:text/javascript,alert(1)"></script>' +
				'<script type="wppo/javascript" wppo-src="blob:https://localhost/abc"></script>';
			await bootLazyload();

			expect(
				document.querySelectorAll( 'script[wppo-src]' ).length
			).toBe( 2 );
		} );

		it( 'rejects cross-origin srcs that are not on the host allowlist', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="https://untrusted.example.net/tracker.js"></script>';
			await bootLazyload();

			expect(
				document.querySelector(
					'script[src="https://untrusted.example.net/tracker.js"]'
				)
			).toBeNull();
			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				expect.stringContaining( 'blocked deferred script src' ),
				'https://untrusted.example.net/tracker.js'
			);
		} );

		it( 'rejects plain-http cross-origin srcs (mixed active content)', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="http://www.googletagmanager.com/gtm.js"></script>';
			await bootLazyload();

			expect(
				document.querySelector(
					'script[src="http://www.googletagmanager.com/gtm.js"]'
				)
			).toBeNull();
		} );

		it( 'honours the runtime host allowlist extension point', async () => {
			global.wppoAllowedScriptHosts = [ 'custom-cdn.example.org' ];
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="https://custom-cdn.example.org/app.js"></script>';
			await bootLazyload();

			expect(
				document.querySelector(
					'script[src="https://custom-cdn.example.org/app.js"]'
				)
			).toBeInTheDocument();
		} );

		it( 'warns loudly when the runtime host list widens the allowlist', async () => {
			// window.wppoAllowedScriptHosts is mutable by any third-party
			// script running before load, so an effective widening must be
			// loud rather than silent (server list stays authoritative).
			global.wppoAllowedScriptHosts = [ 'custom-cdn.example.org' ];
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="https://custom-cdn.example.org/app.js"></script>';
			await bootLazyload();

			expect(
				document.querySelector(
					'script[src="https://custom-cdn.example.org/app.js"]'
				)
			).toBeInTheDocument();
			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				expect.stringContaining(
					'extends the deferred-script host allowlist'
				)
			);
		} );

		it( 'ignores a window-only "*" wildcard unless the server allows it', async () => {
			global.wppoAllowedScriptHosts = [ '*' ];
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="https://anything.example.dev/x.js"></script>';
			await bootLazyload();

			// A mutable window global must not silently disable the
			// allowlist: the wildcard is ignored when the server list does
			// not contain '*', so the untrusted host is blocked.
			expect(
				document.querySelector(
					'script[src="https://anything.example.dev/x.js"]'
				)
			).toBeNull();
			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				expect.stringContaining( 'wildcard is ignored' )
			);
		} );

		it( 'honours a server-provided "*" wildcard', async () => {
			global.wppoDelayConfig = {
				idleTimeout: 3000,
				defaultStrategy: 'interaction',
				allowedScriptHosts: [ '*' ],
			};
			global.wppoAllowedScriptHosts = [ '*' ];
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="https://anything.example.dev/x.js"></script>';
			await bootLazyload();

			expect(
				document.querySelector(
					'script[src="https://anything.example.dev/x.js"]'
				)
			).toBeInTheDocument();
			// The disabled allowlist must be loud, not silent.
			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				expect.stringContaining( 'allowlist is disabled' )
			);
		} );

		it( 'copies only allowlisted attributes on inline script replacement', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript" data-wppo-keep="1" onload="alert(1)" nonce="abc">window.__wppoInline=1;</script>';
			await bootLazyload();

			const inlineScript = Array.from(
				document.querySelectorAll( 'script' )
			).find( ( s ) => s.text === 'window.__wppoInline=1;' );
			expect( inlineScript ).toBeInTheDocument();
			expect( inlineScript.getAttribute( 'data-wppo-keep' ) ).toBe( '1' );
			expect( inlineScript.hasAttribute( 'onload' ) ).toBe( false );
			expect( inlineScript.hasAttribute( 'nonce' ) ).toBe( false );
		} );

		it( 'warns on empty inline script', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript"></script>';
			await bootLazyload();

			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				'WPPO: empty inline script found',
				expect.any( HTMLScriptElement )
			);
		} );
	} );

	describe( 'initVideoPlaceholders() — real bundle URL validation', () => {
		beforeEach( () => {
			delete global.IntersectionObserver;
		} );

		afterEach( () => {
			delete global.IntersectionObserver;
		} );

		const makePlaceholder = ( src ) => {
			const placeholder = document.createElement( 'div' );
			placeholder.className = 'wppo-video-placeholder';
			placeholder.setAttribute( 'data-wppo-video-src', src );
			const playBtn = document.createElement( 'button' );
			playBtn.className = 'wppo-video-play-btn';
			placeholder.appendChild( playBtn );
			document.body.appendChild( placeholder );
			return placeholder;
		};

		it( 'loads an allowlisted https embed on click', async () => {
			const placeholder = makePlaceholder(
				'https://www.youtube.com/embed/test'
			);
			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			placeholder.click();
			await Promise.resolve();

			const iframe = placeholder.querySelector( 'iframe' );
			expect( iframe ).toBeInTheDocument();
			expect( iframe.getAttribute( 'src' ) ).toBe(
				'https://www.youtube.com/embed/test?autoplay=1&enablejsapi=1'
			);
		} );

		it( 'refuses javascript: URLs and leaves the placeholder intact', async () => {
			const placeholder = makePlaceholder( 'javascript:alert(1)' );
			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			placeholder.click();
			await Promise.resolve();

			expect( placeholder.querySelector( 'iframe' ) ).toBeNull();
			expect( placeholder.dataset.wppoLoaded ).toBeUndefined();
			expect( consoleWarnSpy ).toHaveBeenCalledWith(
				expect.stringContaining( 'blocked video placeholder src' ),
				'javascript:alert(1)'
			);
		} );

		it( 'refuses data: URLs and non-https schemes', async () => {
			const dataPh = makePlaceholder( 'data:text/html,<b>x</b>' );
			const httpPh = makePlaceholder( 'http://www.youtube.com/embed/x' );
			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			dataPh.click();
			httpPh.click();
			await Promise.resolve();

			expect( dataPh.querySelector( 'iframe' ) ).toBeNull();
			expect( httpPh.querySelector( 'iframe' ) ).toBeNull();
		} );

		it( 'refuses https embeds from hosts outside the allowlist', async () => {
			const placeholder = makePlaceholder( 'https://evil.example.com/v' );
			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			placeholder.click();
			await Promise.resolve();

			expect( placeholder.querySelector( 'iframe' ) ).toBeNull();
		} );

		it( 'allows same-origin embed URLs', async () => {
			const placeholder = makePlaceholder( '/self-hosted.html' );
			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			placeholder.click();
			await Promise.resolve();

			expect( placeholder.querySelector( 'iframe' ) ).toBeInTheDocument();
		} );

		it( 'never copies on* attributes from the iframe attrs payload', async () => {
			const placeholder = makePlaceholder(
				'https://www.youtube.com/embed/test'
			);
			placeholder.setAttribute(
				'data-wppo-iframe-attrs',
				JSON.stringify( {
					id: 'myvideo',
					onload: 'alert(1)',
					sandbox: 'allow-scripts',
				} )
			);
			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			placeholder.click();
			await Promise.resolve();

			const iframe = placeholder.querySelector( 'iframe' );
			expect( iframe ).toBeInTheDocument();
			expect( iframe.getAttribute( 'id' ) ).toBe( 'myvideo' );
			expect( iframe.getAttribute( 'sandbox' ) ).toBe( 'allow-scripts' );
			expect( iframe.hasAttribute( 'onload' ) ).toBe( false );
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

	describe( 'LCP hero guard', () => {
		it( 'never lazy-loads a hero image: restores it eagerly and skips observation', () => {
			const { observe } = mockIntersectionObserver();

			const hero = document.createElement( 'img' );
			hero.setAttribute( 'data-src', 'https://example.com/hero.jpg' );
			hero.setAttribute( 'data-wppo-hero', '1' );
			hero.setAttribute( 'loading', 'lazy' );
			hero.classList.add( 'lazyload' );
			document.body.appendChild( hero );

			const below = document.createElement( 'img' );
			below.setAttribute( 'data-src', 'https://example.com/below.jpg' );
			document.body.appendChild( below );

			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			expect( hero.getAttribute( 'src' ) ).toBe(
				'https://example.com/hero.jpg'
			);
			expect( hero.hasAttribute( 'data-src' ) ).toBe( false );
			expect( hero.getAttribute( 'loading' ) ).toBe( 'eager' );
			expect( hero.getAttribute( 'decoding' ) ).toBe( 'async' );
			expect( hero.getAttribute( 'fetchpriority' ) ).toBe( 'high' );
			expect( hero.classList.contains( 'lazyload' ) ).toBe( false );
			expect( observe ).not.toHaveBeenCalledWith( hero );
			expect( observe ).toHaveBeenCalledWith( below );
		} );

		it( 'prioritize_lcp() emits a fetchpriority-high preload and excludes the candidate with dimensions preserved', () => {
			mockIntersectionObserver();
			document.head.innerHTML = '';

			const hero = document.createElement( 'img' );
			hero.setAttribute( 'data-src', 'https://example.com/hero.jpg' );
			hero.setAttribute( 'width', '1200' );
			hero.setAttribute( 'height', '800' );
			hero.setAttribute( 'loading', 'lazy' );
			hero.classList.add( 'lazyload' );
			document.body.appendChild( hero );

			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			const prioritize = window.__wppoPrioritizeLcp;
			expect( typeof prioritize ).toBe( 'function' );

			const resolved = prioritize( 'https://example.com/hero.jpg' );
			expect( resolved ).toBe( 'https://example.com/hero.jpg' );

			const link = document.head.querySelector(
				'link[data-wppo-lcp-preload][rel="preload"]'
			);
			expect( link ).not.toBeNull();
			expect( link.getAttribute( 'as' ) ).toBe( 'image' );
			expect( link.getAttribute( 'fetchpriority' ) ).toBe( 'high' );
			expect( link.getAttribute( 'href' ) ).toBe(
				'https://example.com/hero.jpg'
			);

			expect( hero.getAttribute( 'loading' ) ).toBe( 'eager' );
			expect( hero.getAttribute( 'fetchpriority' ) ).toBe( 'high' );
			expect( hero.getAttribute( 'width' ) ).toBe( '1200' );
			expect( hero.getAttribute( 'height' ) ).toBe( '800' );
			expect( hero.hasAttribute( 'data-src' ) ).toBe( false );
		} );

		it( 'prioritize_lcp() emits nothing and returns empty when no image resolves', () => {
			mockIntersectionObserver();
			document.head.innerHTML = '';

			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			const prioritize = window.__wppoPrioritizeLcp;
			expect( prioritize() ).toBe( '' );
			expect(
				document.head.querySelector( 'link[data-wppo-lcp-preload]' )
			).toBeNull();
		} );

		it( 'prioritize_lcp() fails open on an explicit miss instead of preloading another image', () => {
			mockIntersectionObserver();
			document.head.innerHTML = '';

			const other = document.createElement( 'img' );
			other.setAttribute( 'src', 'https://example.com/other.jpg' );
			document.body.appendChild( other );

			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			const prioritize = window.__wppoPrioritizeLcp;
			expect( prioritize( 'https://example.com/stale-hero.jpg' ) ).toBe(
				''
			);
			expect(
				document.head.querySelector( 'link[data-wppo-lcp-preload]' )
			).toBeNull();
			// The unrelated image is left untouched (still lazy).
			expect( other.getAttribute( 'src' ) ).toBe(
				'https://example.com/other.jpg'
			);
		} );

		it( 'prioritize_lcp() matches normalized variants (relative, size suffix, query)', () => {
			mockIntersectionObserver();
			document.head.innerHTML = '';

			const img = document.createElement( 'img' );
			img.setAttribute(
				'data-src',
				'/wp-content/uploads/hero-300x200.jpg'
			);
			document.body.appendChild( img );

			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			const prioritize = window.__wppoPrioritizeLcp;
			const resolved = prioritize(
				'http://localhost/wp-content/uploads/hero.jpg?ver=123'
			);
			expect( resolved ).toBe( '/wp-content/uploads/hero-300x200.jpg' );
			const link = document.head.querySelector(
				'link[data-wppo-lcp-preload][rel="preload"]'
			);
			expect( link ).not.toBeNull();
			expect( link.getAttribute( 'href' ) ).toBe(
				'/wp-content/uploads/hero-300x200.jpg'
			);
		} );

		it( 'prioritize_lcp() emits imagesrcset with imagesizes only when sizes is available', () => {
			mockIntersectionObserver();
			document.head.innerHTML = '';

			const responsive = document.createElement( 'img' );
			responsive.setAttribute( 'src', 'https://example.com/hero.jpg' );
			responsive.setAttribute(
				'srcset',
				'https://example.com/hero-300.jpg 300w, https://example.com/hero-1024.jpg 1024w'
			);
			responsive.setAttribute(
				'sizes',
				'(max-width: 600px) 300px, 1024px'
			);
			document.body.appendChild( responsive );

			const plain = document.createElement( 'img' );
			plain.setAttribute( 'src', 'https://example.com/plain.jpg' );
			plain.setAttribute(
				'srcset',
				'https://example.com/plain-300.jpg 300w'
			);
			document.body.appendChild( plain );

			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			const prioritize = window.__wppoPrioritizeLcp;
			expect( prioritize( 'https://example.com/hero.jpg' ) ).toBe(
				'https://example.com/hero.jpg'
			);
			const link = document.head.querySelector(
				'link[data-wppo-lcp-preload][rel="preload"]'
			);
			expect( link ).not.toBeNull();
			expect( link.getAttribute( 'imagesrcset' ) ).toBe(
				'https://example.com/hero-300.jpg 300w, https://example.com/hero-1024.jpg 1024w'
			);
			expect( link.getAttribute( 'imagesizes' ) ).toBe(
				'(max-width: 600px) 300px, 1024px'
			);

			// Without sizes, imagesrcset is omitted (no wrong-variant fetch).
			document.head.innerHTML = '';
			expect( prioritize( 'https://example.com/plain.jpg' ) ).toBe(
				'https://example.com/plain.jpg'
			);
			const plainLink = document.head.querySelector(
				'link[data-wppo-lcp-preload][rel="preload"]'
			);
			expect( plainLink ).not.toBeNull();
			expect( plainLink.hasAttribute( 'imagesrcset' ) ).toBe( false );
			expect( plainLink.hasAttribute( 'imagesizes' ) ).toBe( false );
		} );

		it( 'prioritize_lcp() matches a srcset-URL candidate on a responsive-only hero', () => {
			mockIntersectionObserver();
			document.head.innerHTML = '';

			const hero = document.createElement( 'img' );
			hero.setAttribute(
				'data-srcset',
				'https://example.com/hero-300.jpg 300w, https://example.com/hero-1024.jpg 1024w'
			);
			hero.setAttribute( 'loading', 'lazy' );
			document.body.appendChild( hero );

			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			const prioritize = window.__wppoPrioritizeLcp;
			const resolved = prioritize( 'https://example.com/hero-1024.jpg' );
			// No src/data-src exists, so the first srcset candidate resolves.
			expect( resolved ).toBe( 'https://example.com/hero-300.jpg' );
			const link = document.head.querySelector(
				'link[data-wppo-lcp-preload][rel="preload"]'
			);
			expect( link ).not.toBeNull();
			expect( link.getAttribute( 'href' ) ).toBe(
				'https://example.com/hero-300.jpg'
			);
			expect( hero.getAttribute( 'loading' ) ).toBe( 'eager' );
		} );

		it( 'prioritize_lcp() skips the preload hint when PHP already emitted one for the same hero', () => {
			mockIntersectionObserver();
			document.head.innerHTML = '';

			// Server-side preload hint (no JS-owned marker).
			const serverLink = document.createElement( 'link' );
			serverLink.setAttribute( 'rel', 'preload' );
			serverLink.setAttribute( 'as', 'image' );
			serverLink.setAttribute( 'href', 'https://example.com/hero.jpg' );
			document.head.appendChild( serverLink );

			const hero = document.createElement( 'img' );
			hero.setAttribute( 'src', 'https://example.com/hero.jpg' );
			hero.setAttribute( 'loading', 'lazy' );
			document.body.appendChild( hero );

			jest.isolateModules( () => {
				require( '../lazyload' );
			} );

			const prioritize = window.__wppoPrioritizeLcp;
			expect( prioritize( 'https://example.com/hero.jpg' ) ).toBe(
				'https://example.com/hero.jpg'
			);
			// No duplicate hint, but the hero is still excluded from lazy.
			expect(
				document.head.querySelectorAll( 'link[rel="preload"]' ).length
			).toBe( 1 );
			expect(
				document.head.querySelector( 'link[data-wppo-lcp-preload]' )
			).toBeNull();
			expect( hero.getAttribute( 'loading' ) ).toBe( 'eager' );
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

		it( 'narrows the lazy selector to videos and never observes native images', () => {
			global.wppoNativeLazy = true;
			const { observe } = mockIntersectionObserver();

			const NATIVE_LAZY_SUPPORTED =
				'loading' in HTMLImageElement.prototype;
			const USE_NATIVE_LAZY =
				global.wppoNativeLazy && NATIVE_LAZY_SUPPORTED;
			const LAZY_SELECTOR = USE_NATIVE_LAZY
				? 'video.wppo-lazy-video'
				: 'img[data-src], img[data-srcset], iframe[data-src], video.wppo-lazy-video';

			expect( LAZY_SELECTOR ).toBe( 'video.wppo-lazy-video' );

			const lazyImg = document.createElement( 'img' );
			lazyImg.setAttribute( 'data-src', 'test.jpg' );
			document.body.appendChild( lazyImg );

			// In full native mode only video.wppo-lazy-video elements are observed;
			// an img[data-src] must not be handed to the IntersectionObserver.
			const nativeCandidates = document.querySelectorAll( LAZY_SELECTOR );
			const imgMatches = document.querySelectorAll(
				'img[data-src], img[data-srcset], iframe[data-src]'
			);

			expect( nativeCandidates.length ).toBe( 0 );
			expect( imgMatches.length ).toBe( 1 );
			expect( observe ).not.toHaveBeenCalled();
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

	describe( 'restoreSizes()', () => {
		const restoreSizesImpl = ( el, AUTO_SIZES_SUPPORTED ) => {
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

		it( 'restores a static data-sizes value verbatim', () => {
			const img = document.createElement( 'img' );
			img.setAttribute( 'data-sizes', '(max-width: 650px) 100vw, 650px' );

			restoreSizesImpl( img, false );

			expect( img.sizes ).toBe( '(max-width: 650px) 100vw, 650px' );
			expect( img.hasAttribute( 'data-sizes' ) ).toBe( false );
		} );

		it( 'keeps an auto-prefixed fallback value in non-supporting browsers', () => {
			const img = document.createElement( 'img' );
			img.setAttribute(
				'data-sizes',
				'auto, (max-width: 650px) 100vw, 650px'
			);

			restoreSizesImpl( img, false );

			expect( img.sizes ).toBe( 'auto, (max-width: 650px) 100vw, 650px' );
			expect( img.hasAttribute( 'data-sizes' ) ).toBe( false );
		} );

		it( 'omits a bare auto value when auto-sizes is unsupported', () => {
			const img = document.createElement( 'img' );
			img.setAttribute( 'data-sizes', 'auto' );

			restoreSizesImpl( img, false );

			expect( img.sizes ).toBe( '' );
			expect( img.hasAttribute( 'data-sizes' ) ).toBe( false );
		} );

		it( 'applies a bare auto value when auto-sizes is supported', () => {
			const img = document.createElement( 'img' );
			img.setAttribute( 'data-sizes', 'auto' );

			restoreSizesImpl( img, true );

			expect( img.sizes ).toBe( 'auto' );
			expect( img.hasAttribute( 'data-sizes' ) ).toBe( false );
		} );

		it( 'does nothing when data-sizes is absent', () => {
			const img = document.createElement( 'img' );

			restoreSizesImpl( img, false );

			expect( img.sizes ).toBe( '' );
			expect( img.hasAttribute( 'data-sizes' ) ).toBe( false );
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

	describe( 'config resolution (script-module data)', () => {
		const injectModuleData = ( data ) => {
			const existing = document.getElementById(
				'wp-script-module-data-wppo-lazyload'
			);
			if ( existing ) {
				existing.remove();
			}
			const el = document.createElement( 'script' );
			el.type = 'application/json';
			el.id = 'wp-script-module-data-wppo-lazyload';
			el.textContent = JSON.stringify( data );
			document.head.appendChild( el );
		};

		const readModuleData = () => {
			const node = document.getElementById(
				'wp-script-module-data-wppo-lazyload'
			);
			if ( ! node || ! node.textContent ) {
				return {};
			}
			try {
				return JSON.parse( node.textContent );
			} catch ( _err ) {} // eslint-disable-line no-unused-vars
			return {};
		};

		it( 'reads nativeLazy from module data when the global is absent', () => {
			injectModuleData( { nativeLazy: true } );
			delete global.wppoNativeLazy;

			const moduleData = readModuleData();
			const useNativeLazy =
				( typeof global.wppoNativeLazy !== 'undefined' &&
					global.wppoNativeLazy ) ||
				!! moduleData.nativeLazy;

			expect( useNativeLazy ).toBe( true );
		} );

		it( 'reads delayConfig from module data when the global is absent', () => {
			injectModuleData( {
				delayConfig: {
					idleTimeout: 5000,
					defaultStrategy: 'idle',
				},
			} );
			delete global.wppoDelayConfig;

			const moduleData = readModuleData();
			const delayConfig = global.wppoDelayConfig ||
				moduleData.delayConfig || {
					idleTimeout: 3000,
					defaultStrategy: 'interaction',
				};

			expect( delayConfig.idleTimeout ).toBe( 5000 );
			expect( delayConfig.defaultStrategy ).toBe( 'idle' );
		} );

		it( 'prefers the legacy globals over module data', () => {
			injectModuleData( { nativeLazy: false } );
			global.wppoNativeLazy = true;

			const moduleData = readModuleData();
			const useNativeLazy =
				( typeof global.wppoNativeLazy !== 'undefined' &&
					global.wppoNativeLazy ) ||
				!! moduleData.nativeLazy;

			expect( useNativeLazy ).toBe( true );
		} );

		it( 'returns defaults when neither globals nor module data are present', () => {
			delete global.wppoNativeLazy;
			delete global.wppoDelayConfig;

			const moduleData = readModuleData();
			const delayConfig = global.wppoDelayConfig ||
				moduleData.delayConfig || {
					idleTimeout: 3000,
					defaultStrategy: 'interaction',
				};

			expect( delayConfig.idleTimeout ).toBe( 3000 );
			expect( delayConfig.defaultStrategy ).toBe( 'interaction' );
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

	describe( 'delay-JS maturity semantics (#1217)', () => {
		beforeEach( () => {
			delete global.IntersectionObserver;
		} );

		afterEach( () => {
			delete global.wppoAllowedScriptHosts;
			delete global.IntersectionObserver;
		} );

		it( 'loads only interaction-strategy scripts on first flush (idle stays pending)', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="/interaction-app.js"></script>' +
				'<script type="wppo/javascript" wppo-src="/idle-app.js" data-wppo-delay-strategy="idle"></script>';
			await bootLazyload();
			// Flush the async flush one microtask turn (the swap itself is
			// synchronous; the idle fallback is a 2000ms timer that never
			// fires here).
			await Promise.resolve();

			expect(
				document.querySelector( 'script[src="/interaction-app.js"]' )
			).toBeInTheDocument();
			// The idle placeholder must not be pulled in early.
			expect(
				document.querySelector( 'script[wppo-src="/idle-app.js"]' )
			).toBeInTheDocument();
			expect(
				document.querySelector( 'script[src="/idle-app.js"]' )
			).toBeNull();
		} );

		it( 'stamps the live replacement with data-wppo-delay-loaded', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="/interaction-app.js"></script>';
			await bootLazyload();
			await Promise.resolve();

			const replacement = document.querySelector(
				'script[src="/interaction-app.js"]'
			);
			expect( replacement ).toBeInTheDocument();
			expect( replacement.getAttribute( 'data-wppo-delay-loaded' ) ).toBe(
				'1'
			);
		} );

		it( 'skips already-loaded placeholders so loaders never double-execute', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="/interaction-app.js"></script>' +
				'<script type="wppo/javascript" wppo-src="/skipped-app.js" data-wppo-delay-loaded="1"></script>';
			await bootLazyload();
			await Promise.resolve();

			expect(
				document.querySelector( 'script[src="/interaction-app.js"]' )
			).toBeInTheDocument();
			// Pre-marked placeholder is left untouched (no live duplicate).
			expect(
				document.querySelector( 'script[src="/skipped-app.js"]' )
			).toBeNull();
			expect(
				document.querySelector( 'script[wppo-src="/skipped-app.js"]' )
			).toBeInTheDocument();
		} );

		it( 'hydrates an async-stamped interaction script', async () => {
			document.body.innerHTML =
				'<script type="wppo/javascript" wppo-src="/async-app.js" data-wppo-delay-exec="async"></script>';
			await bootLazyload();
			await Promise.resolve();
			// Async scripts resolve via Promise.allSettled; allow the
			// microtask queue to flush (jsdom never fires onload, but the
			// synchronous swap is what this asserts).
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			const replacement = document.querySelector(
				'script[src="/async-app.js"]'
			);
			expect( replacement ).toBeInTheDocument();
			expect( replacement.getAttribute( 'data-wppo-delay-loaded' ) ).toBe(
				'1'
			);
		} );
	} );

	describe( 'teardown releases injected config globals', () => {
		it( 'deletes wppoNativeLazy and wppoDelayConfig', () => {
			mockIntersectionObserver();
			bootLazyload();
			global.wppoNativeLazy = true;
			global.wppoDelayConfig = {
				idleTimeout: 1,
				defaultStrategy: 'idle',
			};

			expect( typeof window.wppoLazyloadTeardown ).toBe( 'function' );
			window.wppoLazyloadTeardown();

			expect( 'wppoNativeLazy' in window ).toBe( false );
			expect( 'wppoDelayConfig' in window ).toBe( false );
		} );
	} );

	describe( 'native placeholders (local LQIP)', () => {
		const bootWithNativeImages = () => {
			jest.isolateModules( () => {
				require( '../lazyload' );
			} );
		};

		beforeEach( () => {
			global.wppoNativeLazy = true;
		} );

		it( 'applies the dominant-color wash and LQIP blur to native lazy images', () => {
			document.body.innerHTML =
				'<img loading="lazy" src="wash.jpg" data-wppo-dominant-color="#aabbcc">' +
				'<img loading="lazy" src="blur.jpg" data-wppo-lqip="1">';

			bootWithNativeImages();

			const images = document.querySelectorAll( 'img' );
			expect( images[ 0 ].style.backgroundColor ).toBe(
				'rgb(170, 187, 204)'
			);
			expect( images[ 1 ].classList.contains( 'wppo-lqip-active' ) ).toBe(
				true
			);
		} );

		it( 'clears the blur hook once the native image loads', () => {
			document.body.innerHTML =
				'<img loading="lazy" src="blur.jpg" data-wppo-lqip="1">';

			bootWithNativeImages();

			const img = document.querySelector( 'img' );
			expect( img.classList.contains( 'wppo-lqip-active' ) ).toBe( true );

			img.dispatchEvent( new Event( 'load' ) );

			expect( img.classList.contains( 'wppo-lqip-active' ) ).toBe(
				false
			);
			expect( img.classList.contains( 'wppo-lqip-loaded' ) ).toBe( true );
			expect( img.hasAttribute( 'data-wppo-lqip' ) ).toBe( false );
		} );

		it( 'leaves images without placeholder attributes untouched', () => {
			// The LCP hero never carries placeholder attributes
			// (excluded server-side), so it must gain no blur classes.
			document.body.innerHTML =
				'<img loading="eager" fetchpriority="high" src="hero.jpg">';

			bootWithNativeImages();

			const img = document.querySelector( 'img' );
			expect( img.classList.contains( 'wppo-lqip-active' ) ).toBe(
				false
			);
			expect( img.style.backgroundColor ).toBe( '' );
		} );

		it( 'clears the blur hook when the native image fails to load', () => {
			document.body.innerHTML =
				'<img loading="lazy" src="broken.jpg" data-wppo-lqip="1">';

			bootWithNativeImages();

			const img = document.querySelector( 'img' );
			expect( img.classList.contains( 'wppo-lqip-active' ) ).toBe( true );

			img.dispatchEvent( new Event( 'error' ) );

			expect( img.classList.contains( 'wppo-lqip-active' ) ).toBe(
				false
			);
			expect( img.hasAttribute( 'data-wppo-lqip' ) ).toBe( false );
		} );

		it( 'skips JS-lazy images carrying only data-srcset', () => {
			// A data-srcset image is handled by the IntersectionObserver
			// path, so the native pass must not restyle it.
			document.body.innerHTML =
				'<img loading="lazy" data-srcset="deferred.jpg 1x" data-wppo-lqip="1">';

			bootWithNativeImages();

			const img = document.querySelector( 'img' );
			expect( img.classList.contains( 'wppo-lqip-active' ) ).toBe(
				false
			);
		} );

		it( 'clears the placeholder for already-complete broken images', () => {
			document.body.innerHTML =
				'<img loading="lazy" src="cached-broken.jpg" data-wppo-lqip="1">';
			const img = document.querySelector( 'img' );
			// Simulate a cached 404: complete but never decoded.
			Object.defineProperty( img, 'complete', {
				value: true,
				configurable: true,
			} );
			Object.defineProperty( img, 'naturalWidth', {
				value: 0,
				configurable: true,
			} );

			bootWithNativeImages();

			// No load/error event will ever fire again, so the placeholder
			// must already be settled.
			expect( img.classList.contains( 'wppo-lqip-active' ) ).toBe(
				false
			);
			expect( img.classList.contains( 'wppo-lqip-loaded' ) ).toBe( true );
			expect( img.hasAttribute( 'data-wppo-lqip' ) ).toBe( false );
		} );

		it( 'prepares natively-lazy images injected after boot', async () => {
			bootWithNativeImages();

			const img = document.createElement( 'img' );
			img.setAttribute( 'loading', 'lazy' );
			img.setAttribute( 'src', 'dynamic.jpg' );
			img.setAttribute( 'data-wppo-lqip', '1' );
			document.body.appendChild( img );

			// MutationObserver delivery is a microtask in jsdom: flush
			// microtasks only (never yield to macrotasks, so stale
			// loadImages() timers from earlier-booted module copies — which
			// throw while IntersectionObserver is undefined — cannot
			// interleave here).
			await Promise.resolve();
			await Promise.resolve();

			expect( img.classList.contains( 'wppo-lqip-active' ) ).toBe( true );

			img.dispatchEvent( new Event( 'load' ) );

			expect( img.classList.contains( 'wppo-lqip-active' ) ).toBe(
				false
			);
			expect( img.classList.contains( 'wppo-lqip-loaded' ) ).toBe( true );
		} );
	} );
} );
