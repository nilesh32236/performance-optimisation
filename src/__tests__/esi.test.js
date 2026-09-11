import {
	hydrateESIPlaceholders,
	hydrateElement,
	buildEsiUrl,
	buildEsiBody,
	sanitizeEsiFragment,
} from '../esi';

describe( 'ESI placeholder hydration (esi.js)', () => {
	let originalFetch;
	let originalRAF;

	beforeEach( () => {
		originalFetch = global.fetch;
		global.fetch = jest.fn();
		originalRAF = global.requestAnimationFrame;
		// Make rAF synchronous for tests.
		global.requestAnimationFrame = ( cb ) => cb();
		jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		document.body.innerHTML = '';
		delete global.wppoSettings;
		delete global.ajaxurl;
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		global.fetch = originalFetch;
		if ( originalRAF ) {
			global.requestAnimationFrame = originalRAF;
		} else {
			delete global.requestAnimationFrame;
		}
		document.body.innerHTML = '';
	} );

	it( 'placeholder hydrates via POST without nonce in URL', async () => {
		document.body.innerHTML =
			'<div data-wppo-esi="cart" data-nonce="abc123"></div>';
		const el = document.querySelector( '[data-wppo-esi="cart"]' );
		expect( el ).toBeInTheDocument();

		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( {
				success: true,
				data: { html: '<span>cart(3)</span>' },
			} ),
		} );

		await hydrateElement( el );

		expect( global.fetch ).toHaveBeenCalledWith(
			// No query params at all — nonce and action travel in the body.
			'/wp-admin/admin-ajax.php',
			expect.objectContaining( {
				method: 'POST',
				credentials: 'same-origin',
			} )
		);
		const request = global.fetch.mock.calls[ 0 ][ 1 ];
		const body = request.body;
		expect( body.get( 'action' ) ).toBe( 'wppo_esi_fragment' );
		expect( body.get( 'block' ) ).toBe( 'cart' );
		// Nonce is sent exactly once, as _wpnonce — never as a query param
		// or a duplicate "nonce" field (URLs leak via logs/Referer).
		expect( body.get( '_wpnonce' ) ).toBe( 'abc123' );
		expect( body.get( 'nonce' ) ).toBe( null );
		expect( request.url ).toBeUndefined();
		expect( el.innerHTML ).toBe( '<span>cart(3)</span>' );
		expect( el.hasAttribute( 'data-wppo-esi' ) ).toBe( false );
		// The spent nonce does not linger in the markup.
		expect( el.hasAttribute( 'data-nonce' ) ).toBe( false );
		expect( el.hasAttribute( 'data-wppo-nonce' ) ).toBe( false );
	} );

	it( 'clears loading-state ARIA attributes after hydration', async () => {
		// The PHP placeholder renders role=status + aria-live/aria-busy while
		// the fragment loads (audit #888 finding 7); hydration must drop them
		// so the hydrated widget provides its own semantics.
		document.body.innerHTML =
			'<div data-wppo-esi="cart" data-nonce="abc" role="status" aria-live="polite" aria-busy="true" aria-label="Loading shopping cart…"></div>';
		const el = document.querySelector( '[data-wppo-esi="cart"]' );

		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( {
				success: true,
				data: { html: '<span>cart(1)</span>' },
			} ),
		} );

		await hydrateElement( el );

		expect( el.hasAttribute( 'role' ) ).toBe( false );
		expect( el.hasAttribute( 'aria-live' ) ).toBe( false );
		expect( el.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( el.hasAttribute( 'aria-label' ) ).toBe( false );
		expect( el.innerHTML ).toBe( '<span>cart(1)</span>' );
	} );

	it( 'buildEsiUrl returns the bare ajax URL with no nonce in the query', () => {
		global.wppoSettings = { ajaxUrl: '/custom-ajax.php' };
		expect( buildEsiUrl() ).toBe( '/custom-ajax.php' );
		delete global.wppoSettings;
		global.ajaxurl = '/fallback-ajax.php';
		expect( buildEsiUrl() ).toBe( '/fallback-ajax.php' );
		delete global.ajaxurl;
		expect( buildEsiUrl() ).toBe( '/wp-admin/admin-ajax.php' );
	} );

	it( 'buildEsiBody carries action/block and a single _wpnonce', () => {
		const body = buildEsiBody( 'adminbar', 'tok123' );
		expect( body.get( 'action' ) ).toBe( 'wppo_esi_fragment' );
		expect( body.get( 'block' ) ).toBe( 'adminbar' );
		expect( body.get( '_wpnonce' ) ).toBe( 'tok123' );
		expect( body.get( 'nonce' ) ).toBe( null );

		const anon = buildEsiBody( 'cart', '' );
		expect( anon.get( '_wpnonce' ) ).toBe( null );
	} );

	it( 'hydrates all placeholders via hydrateESIPlaceholders batch', async () => {
		document.body.innerHTML =
			'<div data-wppo-esi="cart" data-nonce="n1"></div><div data-wppo-esi="adminbar" data-nonce="n2"></div>';
		global.fetch.mockResolvedValue( {
			ok: true,
			json: async () => ( {
				success: true,
				data: { html: '<b>ok</b>' },
			} ),
		} );

		hydrateESIPlaceholders();

		// Flush pending fetches (hydration is async via rAF batch).
		await new Promise( ( r ) => setTimeout( r, 0 ) );
		// Give extra tick for all fetches to resolve.
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		expect( global.fetch ).toHaveBeenCalledTimes( 2 );
		// Every request must be a POST with the params in the body, not the URL.
		global.fetch.mock.calls.forEach( ( [ url, init ] ) => {
			expect( url ).toBe( '/wp-admin/admin-ajax.php' );
			expect( init.method ).toBe( 'POST' );
			expect( url.includes( '_wpnonce' ) ).toBe( false );
			expect( url.includes( 'nonce' ) ).toBe( false );
		} );
		const els = document.querySelectorAll( '[data-wppo-esi]' );
		// After hydration, attributes removed, so none should remain with data-wppo-esi.
		expect( els.length ).toBe( 0 );
	} );

	it( 'fans one grouped fetch out to every duplicate placeholder', async () => {
		document.body.innerHTML =
			'<div data-wppo-esi="cart" data-nonce="same"></div><div data-wppo-esi="cart" data-nonce="same"></div>';
		global.fetch.mockResolvedValue( {
			ok: true,
			json: async () => ( {
				success: true,
				data: { html: '<span>cart(3)</span>' },
			} ),
		} );

		hydrateESIPlaceholders();

		await new Promise( ( r ) => setTimeout( r, 0 ) );
		await new Promise( ( r ) => setTimeout( r, 10 ) );

		// Deduplicated: one request for the shared block + nonce group.
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		const divs = document.querySelectorAll( 'div' );
		expect( divs.length ).toBe( 2 );
		// Both placeholders receive content — clones precede consumption
		// of the original fragment.
		divs.forEach( ( div ) => {
			expect( div.innerHTML ).toBe( '<span>cart(3)</span>' );
		} );
	} );

	it( 'does nothing when no placeholders', () => {
		document.body.innerHTML = '<div>no esi here</div>';
		global.fetch.mockResolvedValue( {
			ok: true,
			json: async () => ( {} ),
		} );
		hydrateESIPlaceholders();
		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	it( 'clears loading ARIA and sets a translated error label on !res.ok', async () => {
		document.body.innerHTML =
			'<div data-wppo-esi="cart" data-nonce="abc" role="status" aria-live="polite" aria-busy="true" aria-label="Loading shopping cart…"></div>';
		const el = document.querySelector( '[data-wppo-esi="cart"]' );

		global.fetch.mockResolvedValueOnce( {
			ok: false,
			status: 500,
			json: async () => ( { success: false } ),
		} );

		await hydrateElement( el );

		// AT must not be stuck announcing the loading state forever.
		expect( el.hasAttribute( 'role' ) ).toBe( false );
		expect( el.hasAttribute( 'aria-live' ) ).toBe( false );
		expect( el.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( el.getAttribute( 'aria-label' ) ).toBe(
			'Embedded content failed to load.'
		);
		expect( console.warn ).toHaveBeenCalled();
	} );

	it( 'refreshes the nonce via the nonce block and retries once on 403', async () => {
		document.body.innerHTML =
			'<div data-wppo-esi="cart" data-nonce="stale"></div>';
		const el = document.querySelector( '[data-wppo-esi="cart"]' );

		// 1) Original request: 403 auth failure.
		global.fetch.mockResolvedValueOnce( {
			ok: false,
			status: 403,
			json: async () => ( { code: 'rest_forbidden' } ),
		} );
		// 2) Nonce refresh via the public `nonce` block.
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			status: 200,
			json: async () => ( {
				success: true,
				data: { html: 'freshnonce' },
			} ),
		} );
		// 3) Retry with the fresh nonce.
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			status: 200,
			json: async () => ( {
				success: true,
				data: { html: '<span>cart(9)</span>' },
			} ),
		} );

		await hydrateElement( el );

		expect( global.fetch ).toHaveBeenCalledTimes( 3 );
		const refreshBody = global.fetch.mock.calls[ 1 ][ 1 ].body;
		expect( refreshBody.get( 'block' ) ).toBe( 'nonce' );
		expect( refreshBody.get( '_wpnonce' ) ).toBe( null );
		const retryBody = global.fetch.mock.calls[ 2 ][ 1 ].body;
		expect( retryBody.get( 'block' ) ).toBe( 'cart' );
		expect( retryBody.get( '_wpnonce' ) ).toBe( 'freshnonce' );
		expect( el.innerHTML ).toBe( '<span>cart(9)</span>' );
	} );

	it( 'warns and clears loading ARIA when the retry also fails', async () => {
		document.body.innerHTML =
			'<div data-wppo-esi="adminbar" data-nonce="stale" role="status" aria-live="polite" aria-busy="true"></div>';
		const el = document.querySelector( '[data-wppo-esi="adminbar"]' );

		// Original + retried request both 401; refresh returns a nonce.
		global.fetch.mockResolvedValueOnce( {
			ok: false,
			status: 401,
			json: async () => ( { code: 'rest_forbidden' } ),
		} );
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			status: 200,
			json: async () => ( {
				success: true,
				data: { html: 'freshnonce' },
			} ),
		} );
		global.fetch.mockResolvedValueOnce( {
			ok: false,
			status: 401,
			json: async () => ( { code: 'rest_forbidden' } ),
		} );

		await hydrateElement( el );

		expect( console.warn ).toHaveBeenCalled();
		expect( el.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( el.hasAttribute( 'role' ) ).toBe( false );
		expect( el.getAttribute( 'aria-label' ) ).toBe(
			'Embedded content failed to load.'
		);
	} );

	it( 'threads an AbortSignal through fetches and aborts it on pagehide', async () => {
		document.body.innerHTML =
			'<div data-wppo-esi="cart" data-nonce="n"></div>';
		global.fetch.mockResolvedValue( {
			ok: true,
			status: 200,
			json: async () => ( {
				success: true,
				data: { html: '<b>x</b>' },
			} ),
		} );

		hydrateESIPlaceholders();
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		const signal = global.fetch.mock.calls[ 0 ][ 1 ].signal;
		expect( signal ).toBeDefined();
		expect( signal.aborted ).toBe( false );

		window.dispatchEvent( new Event( 'pagehide' ) );
		expect( signal.aborted ).toBe( true );
	} );

	it( 'honours an explicit AbortSignal passed to hydrateElement', async () => {
		document.body.innerHTML =
			'<div data-wppo-esi="cart" data-nonce="n"></div>';
		const el = document.querySelector( '[data-wppo-esi="cart"]' );
		const controller = new AbortController();

		global.fetch.mockResolvedValueOnce( {
			ok: true,
			status: 200,
			json: async () => ( {
				success: true,
				data: { html: '<span>cart(2)</span>' },
			} ),
		} );

		await hydrateElement( el, controller.signal );

		expect( global.fetch.mock.calls[ 0 ][ 1 ].signal ).toBe(
			controller.signal
		);
		expect( el.innerHTML ).toBe( '<span>cart(2)</span>' );
	} );
} );

describe( 'sanitizeEsiFragment (defense-in-depth DOM sanitizer)', () => {
	it( 'keeps benign markup intact', () => {
		const frag = sanitizeEsiFragment(
			'<div class="cart"><span>cart(3)</span><a href="https://example.com/cart">view</a></div>'
		);
		const wrap = document.createElement( 'div' );
		wrap.appendChild( frag );
		expect( wrap.querySelector( 'span' ).textContent ).toBe( 'cart(3)' );
		expect( wrap.querySelector( 'a' ).getAttribute( 'href' ) ).toBe(
			'https://example.com/cart'
		);
	} );

	it( 'removes script-capable elements', () => {
		const frag = sanitizeEsiFragment(
			'<span>ok</span><script>alert(1)</script><iframe src="https://evil.test"></iframe><object></object><embed><meta http-equiv="refresh" content="0"><link rel="stylesheet" href="https://evil.test">'
		);
		const wrap = document.createElement( 'div' );
		wrap.appendChild( frag );
		expect( wrap.querySelector( 'script' ) ).toBeNull();
		expect( wrap.querySelector( 'iframe' ) ).toBeNull();
		expect( wrap.querySelector( 'object' ) ).toBeNull();
		expect( wrap.querySelector( 'embed' ) ).toBeNull();
		expect( wrap.querySelector( 'meta' ) ).toBeNull();
		expect( wrap.querySelector( 'link' ) ).toBeNull();
		expect( wrap.querySelector( 'span' ).textContent ).toBe( 'ok' );
	} );

	it( 'strips on* event-handler attributes', () => {
		const frag = sanitizeEsiFragment(
			'<span onclick="alert(1)" onerror="alert(2)" onanimationstart="alert(3)" data-keep="1">ok</span><img src="https://example.com/i.png" onmouseover="alert(4)">'
		);
		const wrap = document.createElement( 'div' );
		wrap.appendChild( frag );
		const span = wrap.querySelector( 'span' );
		expect( span.hasAttribute( 'onclick' ) ).toBe( false );
		expect( span.hasAttribute( 'onerror' ) ).toBe( false );
		expect( span.hasAttribute( 'onanimationstart' ) ).toBe( false );
		expect( span.getAttribute( 'data-keep' ) ).toBe( '1' );
		expect(
			wrap.querySelector( 'img' ).hasAttribute( 'onmouseover' )
		).toBe( false );
	} );

	it( 'strips javascript:/vbscript:/data:/blob: URLs from URL attributes', () => {
		const frag = sanitizeEsiFragment(
			'<a href="javascript:alert(1)">x</a><a href="JaVa\tscript:alert(2)">y</a><a href="vbscript:msgbox(1)">z</a><a href=" javascript:alert(4)">w</a><img src="data:text/html,<script>alert(3)</script>"><img src="blob:https://example.com/abc"><a href="https://example.com/ok">safe</a>'
		);
		const wrap = document.createElement( 'div' );
		wrap.appendChild( frag );
		const links = wrap.querySelectorAll( 'a' );
		expect( links[ 0 ].hasAttribute( 'href' ) ).toBe( false );
		// Control characters used to obfuscate the scheme are normalised first.
		expect( links[ 1 ].hasAttribute( 'href' ) ).toBe( false );
		expect( links[ 2 ].hasAttribute( 'href' ) ).toBe( false );
		// Leading whitespace is stripped too (WHATWG scheme parsing ignores it).
		expect( links[ 3 ].hasAttribute( 'href' ) ).toBe( false );
		const imgs = wrap.querySelectorAll( 'img' );
		expect( imgs[ 0 ].hasAttribute( 'src' ) ).toBe( false );
		expect( imgs[ 1 ].hasAttribute( 'src' ) ).toBe( false );
		expect( links[ 4 ].getAttribute( 'href' ) ).toBe(
			'https://example.com/ok'
		);
	} );

	it( 'handles empty and non-string input', () => {
		expect( sanitizeEsiFragment( '' ).childNodes.length ).toBe( 0 );
		expect( sanitizeEsiFragment( null ).childNodes.length ).toBe( 0 );
	} );

	it( 'drops oversized fragments fail-closed instead of inserting them unscrubbed', () => {
		const warnSpy = jest
			.spyOn( console, 'warn' )
			.mockImplementation( () => {} );
		try {
			// 501 nodes exceeds MAX_ESI_NODES; the on* handler must not
			// survive via a fail-open fast path.
			const html =
				'<img src="https://example.com/i.png" onerror="alert(1)">'.repeat(
					501
				);
			const frag = sanitizeEsiFragment( html );
			expect( frag.childNodes.length ).toBe( 0 );
			expect( warnSpy ).toHaveBeenCalled();
		} finally {
			warnSpy.mockRestore();
		}
	} );
} );
