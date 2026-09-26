/**
 * Tests for the Overview's loading and failure behaviour.
 *
 * The defect these pin: `object_cache` is throttled to five calls a minute and
 * answers 429 with a *resolved* response. Treating "resolved" as success left
 * the row reading "Unavailable" with no way to retry, for the rest of the
 * throttle window — reproduced live.
 */

import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';

import Overview, { OVERVIEW_SETTLE_TIMEOUT_MS } from '../Overview';
import { invalidateObjectCacheStatus } from '../../../lib/objectCacheStatus';

jest.mock( '../../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
	fetchSystemInfo: jest.fn(),
	fetchWebVitalsTrends: jest.fn(),
} ) );

const {
	apiCall,
	fetchSystemInfo,
	fetchWebVitalsTrends,
} = require( '../../../lib/apiRequest' );

describe( 'Overview loading and failure behaviour', () => {
	beforeEach( () => {
		// The object-cache memo is module-level by design (it must survive the
		// component unmounting), so it has to be cleared between tests or the
		// first test's result answers every later one.
		invalidateObjectCacheStatus();
		apiCall.mockReset();
		fetchSystemInfo.mockReset();
		fetchWebVitalsTrends.mockReset();
		fetchSystemInfo.mockResolvedValue( {
			success: true,
			data: { php: { version: '8.3' }, wordpress: { version: '7.1.2' } },
		} );
		apiCall.mockResolvedValue( {
			success: true,
			data: { enabled: true, redis_reachable: true },
		} );
		fetchWebVitalsTrends.mockResolvedValue( {
			success: true,
			data: { trends: {} },
		} );
	} );

	it( 'issues exactly one request per source, never two', async () => {
		// The rewrite initially fetched every source twice to derive both the
		// page state and the per-source state. Against a 5-per-minute endpoint
		// that is self-inflicted throttling.
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await waitFor( () =>
			expect( fetchSystemInfo ).toHaveBeenCalledTimes( 1 )
		);
		expect( apiCall ).toHaveBeenCalledTimes( 1 );
		expect( fetchWebVitalsTrends ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows the page while loading and never a verdict before it settles', async () => {
		let release;
		fetchSystemInfo.mockReturnValue(
			new Promise( ( resolve ) => {
				release = resolve;
			} )
		);
		render( <Overview onNavigate={ jest.fn() } /> );
		// While a source is in flight the card must not be claiming "Working".
		expect( screen.getByText( 'Checking your site…' ) ).toBeInTheDocument();
		act( () =>
			release( {
				success: true,
				data: {
					php: { version: '8.3' },
					wordpress: { version: '7.1.2' },
				},
			} )
		);
		await waitFor( () =>
			expect( screen.queryByText( 'Checking your site…' ) ).toBeNull()
		);
	} );

	it( 'offers a retry when one source produced nothing', async () => {
		// The throttle case: 429 arrives resolved, with success:false.
		apiCall.mockResolvedValue( {
			success: false,
			message: 'Too many requests.',
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		await waitFor( () =>
			expect(
				screen.getByText( /Some information could not be loaded/ )
			).toBeInTheDocument()
		);
		expect(
			screen.getByRole( 'button', { name: 'Try again' } )
		).toBeInTheDocument();
	} );

	it( 'does not offer a retry when everything loaded', async () => {
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		expect(
			screen.queryByText( /Some information could not be loaded/ )
		).toBeNull();
	} );

	it( 'reads the cache size from wppoSettings, not from a payload key', async () => {
		// **T2** — the mutation the previous review called out as the strongest
		// it could not catch: changing
		// `cacheStats: wppoSettings?.cache_size` to `payload.cacheStats` left
		// every test green while the page-cache row went permanently "Unknown"
		// in production. The pure function was tested; the *wiring* into it was
		// not, and the wiring is where the original defect lived.
		global.wppoSettings = {
			...global.wppoSettings,
			settings: { cache_settings: { enableCache: true } },
			cache_size: '14 MB',
		};
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await waitFor( () =>
			expect(
				screen.getByText( /14 MB of cached pages stored/ )
			).toBeInTheDocument()
		);
	} );

	it( 'does not blame a cache it could not read', async () => {
		// The honest fallback when wppoSettings carries no size.
		global.wppoSettings = {
			...global.wppoSettings,
			settings: { cache_settings: { enableCache: true } },
			cache_size: undefined,
		};
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await waitFor( () =>
			expect(
				screen.getByText( /could not read how much it has stored/ )
			).toBeInTheDocument()
		);
	} );

	it( 'T6: a site with no stored vitals does not blank the page', async () => {
		// Counting vitals as a required source would render "This information
		// could not be loaded." for every site that has never run a scan.
		fetchWebVitalsTrends.mockResolvedValue( {
			success: true,
			data: { trends: {} },
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await waitFor( () =>
			expect(
				screen.queryByText( /This information could not be loaded/ )
			).toBeNull()
		);
		// And the rows that *did* load are still shown — the page did not blank.
		expect( screen.getAllByText( 'Compatibility' ).length ).toBeGreaterThan(
			0
		);
		expect( screen.getByText( 'Object cache' ) ).toBeInTheDocument();
	} );

	it( 'T1: a vitals response declaring failure is not read as measurements', async () => {
		// A `WP_Error`-shaped body carrying data would otherwise escalate the
		// whole page to "Needs attention" from a failed request.
		// A body that declares failure but still carries usable-looking data.
		// The previous version of this test used `data: { status: 500 }`, which
		// produced no rows *anyway*, so it passed with the guard removed — the
		// very mutation it was written for. The payload has to be one that
		// would actually render a row without the allow-list.
		fetchWebVitalsTrends.mockResolvedValue( {
			success: false,
			data: { trends: { a: [ { lcp: 9999, cls: 0.9 } ] } },
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await new Promise( ( r ) => setTimeout( r, 400 ) );
		expect( screen.queryByText( /is poor at|is good at/ ) ).toBeNull();
		expect( screen.queryByText( /Loading \(LCP\)/ ) ).toBeNull();
	} );

	it( 'treats absent vitals as normal, not as a failure', async () => {
		// A site with no stored measurements is a legitimate state and must not
		// trigger a retry prompt or a failure state.
		fetchWebVitalsTrends.mockResolvedValue( {
			success: true,
			data: { trends: {} },
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		expect(
			screen.queryByText( /Some information could not be loaded/ )
		).toBeNull();
		expect(
			screen.queryByText( /This information could not be loaded/ )
		).toBeNull();
	} );

	it( 're-requests when the retry control is used', async () => {
		apiCall.mockResolvedValue( {
			success: false,
			message: 'Too many requests.',
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		// Wait for the affordance itself, not just the request: clicking before
		// the banner exists would pass for the wrong reason.
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Try again' } )
			).toBeInTheDocument()
		);
		expect( apiCall ).toHaveBeenCalledTimes( 1 );
		await act( async () => {
			screen.getByRole( 'button', { name: 'Try again' } ).click();
		} );
		await waitFor( () => expect( apiCall ).toHaveBeenCalledTimes( 2 ) );
	} );
} );

describe( 'fetchObjectCache envelope handling', () => {
	// A `WP_Error`-shaped body has a truthy `data`, which used to reach the model
	// and render "Object cache is off" — a false claim manufactured out of an
	// error. Only reachable by testing the function directly.
	beforeEach( () => {
		apiCall.mockReset();
	} );

	it( 'returns null for an error envelope, however it is shaped', async () => {
		const { fetchObjectCache } = require( '../Overview' );
		const envelopes = [
			{
				success: false,
				message: 'Too many requests.',
				data: { status: 429 },
			},
			// A `WP_Error` body has no `success` key at all, which is why the
			// check must be an allow-list. A negative check lets this through.
			{ code: 'too_many', message: 'x', data: { status: 500 } },
			{
				success: false,
				data: { enabled: false, redis_reachable: false },
			},
		];
		// Sequential, awaited. `forEach( async … )` does not await, so these
		// assertions used to escape the test and surface as an unhandled
		// rejection after it had already passed.
		for ( const envelope of envelopes ) {
			apiCall.mockResolvedValue( envelope );

			expect( await fetchObjectCache() ).toBeNull();
		}
	} );

	it( 'returns the payload on success', async () => {
		const { fetchObjectCache } = require( '../Overview' );
		apiCall.mockResolvedValue( {
			success: true,
			data: { enabled: true, redis_reachable: true },
		} );
		expect( await fetchObjectCache() ).toEqual( {
			enabled: true,
			redis_reachable: true,
		} );
	} );

	it( 'returns null rather than throwing when there is no response at all', async () => {
		const { fetchObjectCache } = require( '../Overview' );
		apiCall.mockResolvedValue( null );
		expect( await fetchObjectCache() ).toBeNull();
	} );

	it( 'asks for the status action, which the endpoint requires', async () => {
		const { fetchObjectCache } = require( '../Overview' );
		apiCall.mockResolvedValue( { success: true, data: {} } );
		await fetchObjectCache();
		// Without `action: 'status'` the endpoint answers 400.
		expect( apiCall ).toHaveBeenCalledWith(
			'object_cache',
			expect.objectContaining( { action: 'status' } ),
			'POST',
			undefined
		);
	} );
} );

describe( 'envelope handling on every source', () => {
	beforeEach( () => {
		invalidateObjectCacheStatus();
		apiCall.mockReset();
		fetchSystemInfo.mockReset();
		fetchWebVitalsTrends.mockReset();
		fetchSystemInfo.mockResolvedValue( {
			success: true,
			data: { php: { version: '8.3' }, wordpress: { version: '7.1.2' } },
		} );
		apiCall.mockResolvedValue( {
			success: true,
			data: { enabled: true, redis_reachable: true },
		} );
		fetchWebVitalsTrends.mockResolvedValue( {
			success: true,
			data: { trends: {} },
		} );
	} );

	// Two independent reviews found the same asymmetry: `system_info` was the
	// one source still using `response?.data ?? null`, so a response that
	// declared failure was read as data. Live, `{success:false, data:{php:…}}`
	// rendered "Working — Running on WordPress 0.1 and PHP 1.0".
	it( 'does not read a failed system_info as data', async () => {
		fetchSystemInfo.mockResolvedValue( {
			success: false,
			message: 'boom',
			data: { php: { version: '1.0' }, wordpress: { version: '0.1' } },
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await new Promise( ( r ) => setTimeout( r, 400 ) );
		// The versions must not be rendered as a working fact.
		expect( screen.queryByText( /WordPress 0\.1/ ) ).toBeNull();
		expect( screen.queryByText( /PHP 1\.0/ ) ).toBeNull();
		// And the page must offer a way to try again, because it did not load.
		expect(
			screen.getByText( /Some information could not be loaded/ )
		).toBeInTheDocument();
	} );

	it( 'does not read a WP_Error system_info body as data', async () => {
		// No `success` key at all — the shape a negative check lets through.
		fetchSystemInfo.mockResolvedValue( {
			code: 'internal_error',
			message: 'x',
			data: { status: 500 },
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await new Promise( ( r ) => setTimeout( r, 400 ) );
		expect(
			screen.getByText( /Some information could not be loaded/ )
		).toBeInTheDocument();
	} );

	it( 'does not read a failed vitals response as measurements', async () => {
		// The allow-list's own mutation survived a revert; this pins it.
		fetchWebVitalsTrends.mockResolvedValue( {
			success: false,
			data: { trends: { a: [ { lcp: 9999, cls: 0.9 } ] } },
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		await screen.findByRole( 'heading', { name: /Site status/i } );
		await new Promise( ( r ) => setTimeout( r, 400 ) );
		expect( screen.queryByText( /is poor at|is good at/ ) ).toBeNull();
	} );
} );

describe( 'a source that never settles', () => {
	// A hanging `web_vitals_trends` held the whole Overview in "Checking your
	// site…" indefinitely — measured live at 5s, 20s and 45s, with no rows and
	// no retry. An independent review found it.
	//
	// Fake timers, not real ones: these used to sleep out the actual 15 000ms
	// and made the file 18s instead of 3.7s — about a third of the whole suite's
	// wall clock spent waiting for a timeout.
	beforeEach( () => {
		jest.useFakeTimers();
		invalidateObjectCacheStatus();
		apiCall.mockReset();
		fetchSystemInfo.mockReset();
		fetchWebVitalsTrends.mockReset();
		apiCall.mockResolvedValue( {
			success: true,
			data: { enabled: true, redis_reachable: true },
		} );
		fetchSystemInfo.mockResolvedValue( {
			success: true,
			data: { php: { version: '8.3' }, wordpress: { version: '7.1.2' } },
		} );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	/** Let queued microtasks drain, without advancing the fake clock. */
	const flush = async () => {
		await act( async () => {
			await Promise.resolve();
		} );
	};

	/** Advance past the backstop and settle the state updates it triggers. */
	const passBackstop = async () => {
		await act( async () => {
			jest.advanceTimersByTime( OVERVIEW_SETTLE_TIMEOUT_MS + 1 );
			await Promise.resolve();
		} );
	};

	it( 'does not wait for vitals once the required sources have answered', async () => {
		// Vitals are optional: their absence is a normal state, so a slow one
		// must not hold the page.
		fetchWebVitalsTrends.mockReturnValue( new Promise( () => {} ) );
		render( <Overview onNavigate={ jest.fn() } /> );
		await flush();
		expect( screen.queryByText( 'Checking your site…' ) ).toBeNull();
		// And the rows that did load are shown.
		expect( screen.getByText( 'Compatibility' ) ).toBeInTheDocument();
	} );

	it( 'does not claim failure for a slow OPTIONAL source', async () => {
		// The regression: hanging vitals gave 3 correct rows and a "Working"
		// verdict, and then at 15s the backstop added "Some information could
		// not be loaded" over byte-identical content — while an *absent* vitals
		// source produced no banner at all. Slow was reported as broken, and the
		// "Try again" could not work because `apiCall` shares the pending GET.
		fetchWebVitalsTrends.mockReturnValue( new Promise( () => {} ) );
		render( <Overview onNavigate={ jest.fn() } /> );
		await flush();
		await passBackstop();
		expect(
			screen.queryByText( /Some information could not be loaded/ )
		).toBeNull();
		// The page still reports what it actually knows.
		expect( screen.getByText( 'Compatibility' ) ).toBeInTheDocument();
	} );

	it( 'does claim failure for a hanging REQUIRED source, and offers a retry', async () => {
		fetchSystemInfo.mockReturnValue( new Promise( () => {} ) );
		fetchWebVitalsTrends.mockResolvedValue( {
			success: true,
			data: { trends: {} },
		} );
		render( <Overview onNavigate={ jest.fn() } /> );
		await flush();
		await passBackstop();
		expect(
			screen.getByText( /Some information could not be loaded/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Try again' } )
		).toBeInTheDocument();
	} );

	it( 'does not let a superseded load raise a banner on a clean page', async () => {
		// The timer-hygiene property. Removing `clearTimeout` in `load()`, the
		// unmount clear, AND the `aborted` guard together still passed the whole
		// suite, yet the mutant is reachable: load A's timer fires at t=15s into
		// load B and puts a spurious failure banner on a page that fully
		// succeeded. An independent review demonstrated exactly that.
		fetchSystemInfo.mockReturnValue( new Promise( () => {} ) );
		render( <Overview onNavigate={ jest.fn() } /> );
		await flush();

		// Load A backs off and offers a retry.
		await passBackstop();
		expect(
			screen.getByRole( 'button', { name: 'Try again' } )
		).toBeInTheDocument();

		// Retry: every source now answers, so load B is clean.
		fetchSystemInfo.mockResolvedValue( {
			success: true,
			data: { php: { version: '8.3' }, wordpress: { version: '7.1.2' } },
		} );
		fetchWebVitalsTrends.mockResolvedValue( {
			success: true,
			data: { trends: {} },
		} );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Try again' } )
			);
		} );
		await flush();
		expect(
			screen.queryByText( /Some information could not be loaded/ )
		).toBeNull();

		// Load A's timer would fire here if the retry had not cleared it.
		await passBackstop();
		expect(
			screen.queryByText( /Some information could not be loaded/ )
		).toBeNull();
		// The page still shows what it really knows.
		expect( screen.getByText( 'Compatibility' ) ).toBeInTheDocument();
	} );
} );
