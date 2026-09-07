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

	it( 'does nothing when no placeholders', () => {
		document.body.innerHTML = '<div>no esi here</div>';
		global.fetch.mockResolvedValue( {
			ok: true,
			json: async () => ( {} ),
		} );
		hydrateESIPlaceholders();
		expect( global.fetch ).not.toHaveBeenCalled();
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
			'<a href="javascript:alert(1)">x</a><a href="JaVa\tscript:alert(2)">y</a><a href="vbscript:msgbox(1)">z</a><img src="data:text/html,<script>alert(3)</script>"><img src="blob:https://example.com/abc"><a href="https://example.com/ok">safe</a>'
		);
		const wrap = document.createElement( 'div' );
		wrap.appendChild( frag );
		const links = wrap.querySelectorAll( 'a' );
		expect( links[ 0 ].hasAttribute( 'href' ) ).toBe( false );
		// Control characters used to obfuscate the scheme are normalised first.
		expect( links[ 1 ].hasAttribute( 'href' ) ).toBe( false );
		expect( links[ 2 ].hasAttribute( 'href' ) ).toBe( false );
		const imgs = wrap.querySelectorAll( 'img' );
		expect( imgs[ 0 ].hasAttribute( 'src' ) ).toBe( false );
		expect( imgs[ 1 ].hasAttribute( 'src' ) ).toBe( false );
		expect( links[ 3 ].getAttribute( 'href' ) ).toBe(
			'https://example.com/ok'
		);
	} );

	it( 'handles empty and non-string input', () => {
		expect( sanitizeEsiFragment( '' ).childNodes.length ).toBe( 0 );
		expect( sanitizeEsiFragment( null ).childNodes.length ).toBe( 0 );
	} );
} );
