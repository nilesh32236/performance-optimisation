describe( 'Admin Bar (main.js)', () => {
	let originalFetch;

	const setupDOM = () => {
		document.body.innerHTML = `
			<div id="wp-admin-bar-wppo_clear_all">
				<a class="ab-item" href="#">Clear All Cache</a>
			</div>
			<div id="wp-admin-bar-wppo_clear_this_page">
				<a class="ab-item" href="#">Clear This Page</a>
			</div>
			<div id="wpbody-content"></div>
		`;
	};

	const setupWppoObject = ( translations = {} ) => {
		global.wppoObject = {
			apiUrl: 'http://test.com/wp-json/wppo/v1',
			ajaxUrl: 'http://test.com/wp-admin/admin-ajax.php',
			nonce: 'testnonce',
			nonce_refresh: 'testnonce_refresh',
			translations,
		};
	};

	beforeAll( () => {
		// Load the module once — registers a single DOMContentLoaded listener.
		require( '../main' );
	} );

	beforeEach( () => {
		originalFetch = global.fetch;
		global.fetch = jest.fn();
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		setupDOM();
		setupWppoObject();
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		global.fetch = originalFetch;
		delete global.wppoObject;
		delete window.wp;
	} );

	it( 'sends POST to clear_cache when Clear All Cache is clicked', async () => {
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: jest.fn().mockResolvedValueOnce( { success: true } ),
		} );

		const clearAllCacheBtn = document.querySelector(
			'#wp-admin-bar-wppo_clear_all .ab-item'
		);
		clearAllCacheBtn.click();

		expect( global.fetch ).toHaveBeenCalledWith(
			'http://test.com/wp-json/wppo/v1/clear_cache',
			expect.objectContaining( {
				method: 'POST',
				headers: expect.objectContaining( {
					'Content-Type': 'application/json',
					'X-WP-Nonce': 'testnonce',
				} ),
				body: JSON.stringify( { action: 'clear_cache' } ),
			} )
		);
	} );

	it( 'sends path when Clear This Page Cache is clicked', async () => {
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: jest.fn().mockResolvedValueOnce( { success: true } ),
		} );

		const clearCacheBtn = document.querySelector(
			'#wp-admin-bar-wppo_clear_this_page .ab-item'
		);
		clearCacheBtn.click();

		expect( global.fetch ).toHaveBeenCalledWith(
			'http://test.com/wp-json/wppo/v1/clear_cache',
			expect.objectContaining( {
				body: JSON.stringify( {
					action: 'clear_single_page_cache',
					path: '/',
				} ),
			} )
		);
	} );

	it( 'creates notice DOM element on successful cache clear', async () => {
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: jest.fn().mockResolvedValueOnce( { success: true } ),
		} );

		const clearAllCacheBtn = document.querySelector(
			'#wp-admin-bar-wppo_clear_all .ab-item'
		);
		clearAllCacheBtn.click();

		await new Promise( ( r ) => setTimeout( r, 50 ) );

		const notice = document.querySelector( '.wppo-admin-notice' );
		expect( notice ).toBeInTheDocument();
		expect( notice ).toHaveTextContent( 'Cache cleared successfully.' );
	} );

	it( 'shows error notice when cache clear fails', async () => {
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: jest.fn().mockResolvedValueOnce( {
				success: false,
				message: 'Failed to clear cache.',
			} ),
		} );

		const clearAllCacheBtn = document.querySelector(
			'#wp-admin-bar-wppo_clear_all .ab-item'
		);
		clearAllCacheBtn.click();

		await new Promise( ( r ) => setTimeout( r, 50 ) );

		const notice = document.querySelector( '.wppo-admin-notice' );
		expect( notice ).toHaveClass( 'notice-error' );
		expect( notice ).toHaveTextContent( 'Failed to clear cache.' );
	} );

	it( 'prefers window.wp.i18n translations when available', async () => {
		window.wp = {
			i18n: {
				__: ( str ) => `TR:${ str }`,
			},
		};
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: jest.fn().mockResolvedValueOnce( { success: true } ),
		} );

		const clearAllCacheBtn = document.querySelector(
			'#wp-admin-bar-wppo_clear_all .ab-item'
		);
		clearAllCacheBtn.click();

		await new Promise( ( r ) => setTimeout( r, 50 ) );

		const notice = document.querySelector( '.wppo-admin-notice' );
		expect( notice ).toHaveTextContent( 'TR:Cache cleared successfully.' );
	} );

	it( 'prefers wppoObject.translations when wp.i18n has no JSON entry', async () => {
		// Production case: window.wp.i18n exists but returns the input
		// unchanged (no translation JSON loaded), while the PHP-localized
		// map carries the server-translated string.
		window.wp = {
			i18n: {
				__: ( str ) => str,
			},
		};
		global.wppoObject.translations = {
			cacheCleared: 'Zwischenspeicher geleert.',
		};
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: jest.fn().mockResolvedValueOnce( { success: true } ),
		} );

		const clearAllCacheBtn = document.querySelector(
			'#wp-admin-bar-wppo_clear_all .ab-item'
		);
		clearAllCacheBtn.click();

		await new Promise( ( r ) => setTimeout( r, 50 ) );

		const notice = document.querySelector( '.wppo-admin-notice' );
		expect( notice ).toHaveTextContent( 'Zwischenspeicher geleert.' );
	} );

	it( 'falls back to wppoObject.translations when wp.i18n is absent', async () => {
		global.wppoObject.translations = {
			cacheCleared: 'Zwischenspeicher geleert.',
			dismiss: 'Schliessen',
		};
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: jest.fn().mockResolvedValueOnce( { success: true } ),
		} );

		const clearAllCacheBtn = document.querySelector(
			'#wp-admin-bar-wppo_clear_all .ab-item'
		);
		clearAllCacheBtn.click();

		await new Promise( ( r ) => setTimeout( r, 50 ) );

		const notice = document.querySelector( '.wppo-admin-notice' );
		expect( notice ).toHaveTextContent( 'Zwischenspeicher geleert.' );
		expect(
			notice
				.querySelector( '.notice-dismiss' )
				.getAttribute( 'aria-label' )
		).toBe( 'Schliessen' );
	} );

	it( 'falls back to English strings when no translations exist', async () => {
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: jest.fn().mockResolvedValueOnce( { success: true } ),
		} );

		const clearAllCacheBtn = document.querySelector(
			'#wp-admin-bar-wppo_clear_all .ab-item'
		);
		clearAllCacheBtn.click();

		await new Promise( ( r ) => setTimeout( r, 50 ) );

		const notice = document.querySelector( '.wppo-admin-notice' );
		expect( notice ).toHaveTextContent( 'Cache cleared successfully.' );
		expect(
			notice
				.querySelector( '.notice-dismiss' )
				.getAttribute( 'aria-label' )
		).toBe( 'Dismiss' );
	} );

	it( 'retries after a rest_forbidden payload code and shows success', async () => {
		global.fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: jest
					.fn()
					.mockResolvedValueOnce( { code: 'rest_forbidden' } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: jest.fn().mockResolvedValueOnce( {
					success: true,
					data: { nonce: 'refreshed' },
				} ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: jest.fn().mockResolvedValueOnce( { success: true } ),
			} );

		const clearAllCacheBtn = document.querySelector(
			'#wp-admin-bar-wppo_clear_all .ab-item'
		);
		clearAllCacheBtn.click();

		await new Promise( ( r ) => setTimeout( r, 100 ) );

		expect( global.fetch ).toHaveBeenCalledWith(
			'http://test.com/wp-admin/admin-ajax.php',
			expect.objectContaining( { method: 'POST' } )
		);
		const notice = document.querySelector( '.wppo-admin-notice' );
		expect( notice ).toHaveTextContent( 'Cache cleared successfully.' );
	} );

	it( 'throws on payload-path refresh failure instead of resolving auth error', async () => {
		global.fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: jest
					.fn()
					.mockResolvedValueOnce( { code: 'rest_forbidden' } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: jest.fn().mockResolvedValueOnce( { success: false } ),
			} );

		const clearAllCacheBtn = document.querySelector(
			'#wp-admin-bar-wppo_clear_all .ab-item'
		);
		clearAllCacheBtn.click();

		await new Promise( ( r ) => setTimeout( r, 100 ) );

		const notice = document.querySelector( '.wppo-admin-notice' );
		expect( notice ).toHaveTextContent(
			'Failed to clear cache. Please try again.'
		);
	} );

	it( 'prevents default on admin bar link click', async () => {
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: jest.fn().mockResolvedValueOnce( { success: true } ),
		} );

		const clearAllCacheBtn = document.querySelector(
			'#wp-admin-bar-wppo_clear_all .ab-item'
		);

		const clickEvent = new window.MouseEvent( 'click', {
			cancelable: true,
		} );
		const preventDefaultSpy = jest.spyOn( clickEvent, 'preventDefault' );
		clearAllCacheBtn.dispatchEvent( clickEvent );

		expect( preventDefaultSpy ).toHaveBeenCalled();
	} );
} );
