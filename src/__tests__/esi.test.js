import { hydrateESIPlaceholders, hydrateElement, buildEsiUrl } from '../esi';

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

	it( 'placeholder hydrates: innerHTML replaced via fetch', async () => {
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
			expect.stringContaining( 'action=wppo_esi_fragment' ),
			expect.objectContaining( { credentials: 'same-origin' } )
		);
		expect( el.innerHTML ).toBe( '<span>cart(3)</span>' );
		expect( el.hasAttribute( 'data-wppo-esi' ) ).toBe( false );
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
		const els = document.querySelectorAll( '[data-wppo-esi]' );
		// After hydration, attributes removed, so none should remain with data-wppo-esi.
		expect( els.length ).toBe( 0 );
		document.body.innerHTML = '<div data-wppo-esi="cart"></div>';
		// Verify buildEsiUrl uses wppoSettings.ajaxUrl when present
		global.wppoSettings = { ajaxUrl: '/custom-ajax.php' };
		const url = buildEsiUrl( 'cart', 'xyz' );
		expect( url ).toContain( '/custom-ajax.php' );
		expect( url ).toContain( 'block=cart' );
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
