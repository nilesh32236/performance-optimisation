import {
	render,
	screen,
	act,
	fireEvent,
	waitFor,
} from '@testing-library/react';
import WelcomePanel, {
	getStepAriaLabel,
	scrollToWooSafeMode,
} from '../WelcomePanel';
import { apiCall, fetchWooCacheSelfTest } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
	fetchWooCacheSelfTest: jest.fn(),
	getErrorLogMessage: ( error ) =>
		error instanceof Error ? error.message : String( error ),
	getWppoSettings: () => global.wppoSettings ?? {},
} ) );

describe( 'WelcomePanel', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	beforeEach( () => {
		global.wppoSettings = {
			show_welcome: true,
			settings: {
				cache_settings: { enableCache: false },
				file_optimisation: { minifyJS: false, minifyCSS: false },
				image_optimisation: { lazyLoadImages: false },
			},
		};
	} );

	it( 'does not render when show_welcome is false', () => {
		global.wppoSettings.show_welcome = false;
		const { container } = render( <WelcomePanel /> );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders the steps correctly when show_welcome is true', () => {
		render( <WelcomePanel /> );
		expect(
			screen.getByText( 'Welcome to Performance Optimisation' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/Start Safe, test your site, review Performance Audit/
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Enable – Enable Page Caching',
			} )
		).toBeInTheDocument();
	} );

	it( 'shows loading state and updates aria-label during API call', async () => {
		let resolveApiCall;
		apiCall.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					resolveApiCall = resolve;
				} )
		);

		render( <WelcomePanel /> );
		const cacheButton = screen.getByRole( 'button', {
			name: 'Enable – Enable Page Caching',
		} );

		expect( cacheButton ).not.toHaveAttribute( 'aria-busy', 'true' );
		expect( cacheButton ).not.toBeDisabled();

		await act( async () => {
			fireEvent.click( cacheButton );
		} );

		// Button should now be busy and disabled
		expect( cacheButton ).toHaveAttribute( 'aria-busy', 'true' );
		expect( cacheButton ).toBeDisabled();
		// Screen reader label should update
		expect( cacheButton ).toHaveAttribute(
			'aria-label',
			'Enabling… – Enable Page Caching…'
		);
		// Visual text should update
		expect( cacheButton ).toHaveTextContent( 'Enabling…' );

		// Resolve the mock promise
		await act( async () => {
			resolveApiCall( { success: true } );
		} );
	} );

	it( 'handles API failure when attempting to enable a feature', async () => {
		apiCall.mockImplementation( ( endpoint ) => {
			if ( endpoint === 'update_settings' ) {
				return Promise.resolve( {
					success: false,
					message: 'Custom API error',
				} );
			}
			return Promise.resolve( { success: true } );
		} );

		render( <WelcomePanel /> );
		const cacheButton = screen.getByRole( 'button', {
			name: 'Enable – Enable Page Caching',
		} );

		await act( async () => {
			fireEvent.click( cacheButton );
		} );
		await waitFor( () => {
			expect(
				screen.getByText( 'Custom API error' )
			).toBeInTheDocument();
		} );
		expect( cacheButton ).not.toHaveAttribute( 'aria-busy', 'true' );
		// Dismissal is gated on the settings update succeeding.
		expect( apiCall ).not.toHaveBeenCalledWith( 'dismiss_welcome' );
		expect(
			screen.getByText( 'Welcome to Performance Optimisation' )
		).toBeInTheDocument();
	} );

	it( 'does not dismiss the panel when update_settings rejects', async () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		apiCall.mockImplementation( ( endpoint ) => {
			if ( endpoint === 'update_settings' ) {
				return Promise.reject( new Error( 'Network Error' ) );
			}
			return Promise.resolve( { success: true } );
		} );

		render( <WelcomePanel /> );
		const cacheButton = screen.getByRole( 'button', {
			name: 'Enable – Enable Page Caching',
		} );

		await act( async () => {
			fireEvent.click( cacheButton );
		} );
		await waitFor( () => {
			expect(
				screen.getByText( 'Failed to enable the feature.' )
			).toBeInTheDocument();
		} );
		// A rejected update must never fire dismiss_welcome, otherwise the
		// panel would disappear server-side with the feature not enabled.
		expect( apiCall ).not.toHaveBeenCalledWith( 'dismiss_welcome' );
		expect(
			screen.getByText( 'Welcome to Performance Optimisation' )
		).toBeInTheDocument();
	} );

	it( 'hides the panel when update_settings and dismiss_welcome both succeed', async () => {
		fetchWooCacheSelfTest.mockResolvedValueOnce( {
			success: true,
			data: {
				woo_active: true,
				runnable: true,
				all_pass: true,
				excluded_paths: [ 'cart', 'checkout' ],
			},
		} );
		apiCall.mockImplementation( ( endpoint ) => {
			if ( endpoint === 'update_settings' ) {
				return Promise.resolve( { success: true } );
			}
			return Promise.resolve( { success: true } );
		} );

		render( <WelcomePanel /> );
		const cacheButton = screen.getByRole( 'button', {
			name: 'Enable – Enable Page Caching',
		} );

		await act( async () => {
			fireEvent.click( cacheButton );
		} );
		await waitFor( () => {
			expect(
				screen.queryByText( 'Welcome to Performance Optimisation' )
			).not.toBeInTheDocument();
		} );
		expect( apiCall ).toHaveBeenCalledWith( 'dismiss_welcome' );
	} );

	it( 'sends wooSafeMode:true and auto-runs the self-test on cache enable (PASS dismisses)', async () => {
		fetchWooCacheSelfTest.mockResolvedValueOnce( {
			success: true,
			data: {
				woo_active: true,
				runnable: true,
				all_pass: true,
				excluded_paths: [ 'cart', 'checkout' ],
			},
		} );
		apiCall.mockImplementation( ( endpoint ) => {
			if ( endpoint === 'update_settings' ) {
				return Promise.resolve( { success: true } );
			}
			return Promise.resolve( { success: true } );
		} );

		render( <WelcomePanel /> );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', {
					name: 'Enable – Enable Page Caching',
				} )
			);
		} );

		await waitFor( () =>
			expect( fetchWooCacheSelfTest ).toHaveBeenCalled()
		);
		expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
			tab: 'cache_settings',
			settings: { enableCache: true, wooSafeMode: true },
		} );
		await waitFor( () => {
			expect(
				screen.queryByText( 'Welcome to Performance Optimisation' )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'keeps the panel visible on FAIL all_pass:false after cache enable', async () => {
		fetchWooCacheSelfTest.mockResolvedValueOnce( {
			success: true,
			data: {
				woo_active: true,
				runnable: true,
				all_pass: false,
				excluded_paths: [ 'cart' ],
			},
		} );
		apiCall.mockImplementation( ( endpoint ) => {
			if ( endpoint === 'update_settings' ) {
				return Promise.resolve( { success: true } );
			}
			return Promise.resolve( { success: true } );
		} );

		render( <WelcomePanel /> );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', {
					name: 'Enable – Enable Page Caching',
				} )
			);
		} );

		await waitFor( () =>
			expect( fetchWooCacheSelfTest ).toHaveBeenCalled()
		);
		await waitFor( () => {
			expect(
				screen.getByText(
					'WooCommerce self-test found a cacheable dynamic route. Re-enable safe mode in Dashboard → Page Cache.'
				)
			).toBeInTheDocument();
		} );
		expect( apiCall ).not.toHaveBeenCalledWith( 'dismiss_welcome' );
		expect(
			screen.getByText( 'Welcome to Performance Optimisation' )
		).toBeInTheDocument();
	} );

	it( 'keeps the panel visible on fail-closed force_exclude after cache enable', async () => {
		fetchWooCacheSelfTest.mockResolvedValueOnce( {
			success: true,
			data: {
				woo_active: true,
				runnable: true,
				all_pass: true,
				force_exclude: true,
				excluded_paths: [ 'cart' ],
			},
		} );
		apiCall.mockImplementation( ( endpoint ) => {
			if ( endpoint === 'update_settings' ) {
				return Promise.resolve( { success: true } );
			}
			return Promise.resolve( { success: true } );
		} );

		render( <WelcomePanel /> );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', {
					name: 'Enable – Enable Page Caching',
				} )
			);
		} );

		await waitFor( () =>
			expect( fetchWooCacheSelfTest ).toHaveBeenCalled()
		);
		// Canonical predicate treats force_exclude as failure evidence,
		// so the panel must stay even though all_pass is true.
		await waitFor( () => {
			expect(
				screen.getByText( 'Welcome to Performance Optimisation' )
			).toBeInTheDocument();
		} );
		expect( apiCall ).not.toHaveBeenCalledWith( 'dismiss_welcome' );
	} );

	it( 'dismisses normally when the onboarding self-test rejects (best-effort)', async () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		fetchWooCacheSelfTest.mockRejectedValueOnce(
			new Error( 'Network Error' )
		);
		apiCall.mockImplementation( ( endpoint ) => {
			if ( endpoint === 'update_settings' ) {
				return Promise.resolve( { success: true } );
			}
			return Promise.resolve( { success: true } );
		} );

		render( <WelcomePanel /> );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', {
					name: 'Enable – Enable Page Caching',
				} )
			);
		} );

		await waitFor( () => {
			expect(
				screen.queryByText( 'Welcome to Performance Optimisation' )
			).not.toBeInTheDocument();
		} );
		expect( apiCall ).toHaveBeenCalledWith( 'dismiss_welcome' );
		expect( console.error ).toHaveBeenCalled();
	} );

	it( 'shows the dismiss error when dismiss_welcome throws after a successful update', async () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		fetchWooCacheSelfTest.mockResolvedValueOnce( {
			success: true,
			data: {
				woo_active: true,
				runnable: true,
				all_pass: true,
				excluded_paths: [ 'cart' ],
			},
		} );
		apiCall.mockImplementation( ( endpoint ) => {
			if ( endpoint === 'update_settings' ) {
				return Promise.resolve( { success: true } );
			}
			return Promise.reject( new Error( 'Dismiss Network Error' ) );
		} );

		render( <WelcomePanel /> );
		const cacheButton = screen.getByRole( 'button', {
			name: 'Enable – Enable Page Caching',
		} );

		await act( async () => {
			fireEvent.click( cacheButton );
		} );
		// Per-call granularity: a thrown dismiss must not masquerade as an
		// enable failure, since the feature was already enabled.
		await waitFor( () => {
			expect(
				screen.getByText( 'Failed to dismiss the welcome panel.' )
			).toBeInTheDocument();
		} );
		expect(
			screen.queryByText( 'Failed to enable the feature.' )
		).not.toBeInTheDocument();
		expect(
			screen.getByText( 'Welcome to Performance Optimisation' )
		).toBeInTheDocument();
	} );

	it( 'keeps the panel visible when dismiss_welcome fails after a successful update', async () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		fetchWooCacheSelfTest.mockResolvedValueOnce( {
			success: true,
			data: {
				woo_active: true,
				runnable: true,
				all_pass: true,
				excluded_paths: [ 'cart' ],
			},
		} );
		apiCall.mockImplementation( ( endpoint ) => {
			if ( endpoint === 'update_settings' ) {
				return Promise.resolve( { success: true } );
			}
			return Promise.resolve( {
				success: false,
				message: 'Dismiss failed',
			} );
		} );

		render( <WelcomePanel /> );
		const cacheButton = screen.getByRole( 'button', {
			name: 'Enable – Enable Page Caching',
		} );

		await act( async () => {
			fireEvent.click( cacheButton );
		} );
		await waitFor( () => {
			expect( screen.getByText( 'Dismiss failed' ) ).toBeInTheDocument();
		} );
		// Partial success: panel stays so the user can retry dismissal.
		expect(
			screen.getByText( 'Welcome to Performance Optimisation' )
		).toBeInTheDocument();
		expect( cacheButton ).not.toHaveAttribute( 'aria-busy', 'true' );
	} );

	it( 'handles API exception when attempting to enable a feature', async () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		apiCall.mockRejectedValue( new Error( 'Network Error' ) );

		render( <WelcomePanel /> );
		const cacheButton = screen.getByRole( 'button', {
			name: 'Enable – Enable Page Caching',
		} );

		await act( async () => {
			fireEvent.click( cacheButton );
		} );
		await waitFor( () => {
			expect(
				screen.getByText( 'Failed to enable the feature.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'dismisses the panel successfully when Got it button is clicked', async () => {
		apiCall.mockResolvedValue( { success: true } );

		render( <WelcomePanel /> );
		const dismissButton = screen.getByRole( 'button', { name: 'Got it' } );

		await act( async () => {
			fireEvent.click( dismissButton );
		} );
		await waitFor( () => {
			expect(
				screen.queryByText( 'Welcome to Performance Optimisation' )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'handles API failure when attempting to dismiss the panel', async () => {
		apiCall.mockResolvedValue( {
			success: false,
			message: 'Dismiss failed',
		} );

		render( <WelcomePanel /> );
		const dismissButton = screen.getByRole( 'button', { name: 'Got it' } );

		await act( async () => {
			fireEvent.click( dismissButton );
		} );
		await waitFor( () => {
			expect( screen.getByText( 'Dismiss failed' ) ).toBeInTheDocument();
		} );
		expect(
			screen.getByText( 'Welcome to Performance Optimisation' )
		).toBeInTheDocument();
	} );

	it( 'handles API exception when attempting to dismiss the panel', async () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		apiCall.mockRejectedValue( new Error( 'Network Error' ) );

		render( <WelcomePanel /> );
		const dismissButton = screen.getByRole( 'button', { name: 'Got it' } );

		await act( async () => {
			fireEvent.click( dismissButton );
		} );
		await waitFor( () => {
			expect(
				screen.getByText( 'Failed to dismiss the welcome panel.' )
			).toBeInTheDocument();
		} );
		expect(
			screen.getByText( 'Welcome to Performance Optimisation' )
		).toBeInTheDocument();
	} );

	it( 'appears when the global show_welcome arrives late', async () => {
		global.wppoSettings.show_welcome = false;
		const { rerender } = render( <WelcomePanel /> );
		expect(
			screen.queryByText( 'Welcome to Performance Optimisation' )
		).not.toBeInTheDocument();

		global.wppoSettings.show_welcome = true;
		rerender( <WelcomePanel /> );

		await waitFor( () => {
			expect(
				screen.getByText( 'Welcome to Performance Optimisation' )
			).toBeInTheDocument();
		} );
	} );

	it( 'renders the woo-verify step with a Run test action', () => {
		render( <WelcomePanel /> );
		expect(
			screen.getByText( 'Verify WooCommerce Cart Bypass' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Run test – Verify WooCommerce Cart Bypass',
			} )
		).toBeInTheDocument();
	} );

	it( 'getStepAriaLabel guards malformed steps', () => {
		expect( getStepAriaLabel( null, false, false ) ).toBe( '' );
		expect( getStepAriaLabel( undefined, true, true ) ).toBe( 'Running …' );
		expect( getStepAriaLabel( { label: 'X' }, true, false ) ).toBe( 'X…' );
		expect(
			getStepAriaLabel( { label: 'Enable Page Caching' }, false, false )
		).toBe( 'Enable Page Caching' );
	} );

	it( 'getStepAriaLabel includes visible text when provided', () => {
		expect(
			getStepAriaLabel(
				{ label: 'Verify WooCommerce Cart Bypass' },
				false,
				true,
				'Run test'
			)
		).toBe( 'Run test – Verify WooCommerce Cart Bypass' );
		expect(
			getStepAriaLabel(
				{ label: 'Enable Page Caching' },
				true,
				false,
				'Enabling…'
			)
		).toBe( 'Enabling… – Enable Page Caching…' );
	} );

	it( 'scrollToWooSafeMode focuses the switch input', () => {
		document.body.innerHTML =
			'<div id="wppoWooSafeMode"><input type="checkbox" /></div>';
		const input = document.querySelector( '#wppoWooSafeMode input' );
		input.focus = jest.fn();
		input.scrollIntoView = jest.fn();
		const anchor = document.getElementById( 'wppoWooSafeMode' );
		anchor.scrollIntoView = jest.fn();
		scrollToWooSafeMode();
		expect( anchor.scrollIntoView ).toHaveBeenCalled();
		expect( input.focus ).toHaveBeenCalled();
		document.body.innerHTML = '';
	} );

	it( 'passes the woo self-test and shows inline PASS results', async () => {
		fetchWooCacheSelfTest.mockResolvedValueOnce( {
			success: true,
			data: {
				woo_active: true,
				runnable: true,
				all_pass: true,
				excluded_paths: [ 'cart', 'checkout' ],
			},
		} );

		render( <WelcomePanel /> );
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Run test – Verify WooCommerce Cart Bypass',
			} )
		);

		await waitFor( () =>
			expect( fetchWooCacheSelfTest ).toHaveBeenCalled()
		);
		// Audit #1354: await the rendered result — the timeout wrapper
		// resolves a microtask later than the direct call did.
		await waitFor( () =>
			expect(
				screen.getByText(
					'WooCommerce self-test passed: cart, checkout and account pages bypass the cache; the guest cart survives.'
				)
			).toBeInTheDocument()
		);
		expect( screen.getByText( /Cart, checkout/ ) ).toBeInTheDocument();
		// Read-only proof must not dismiss onboarding.
		expect( apiCall ).not.toHaveBeenCalledWith( 'dismiss_welcome' );
	} );

	it( 'shows the unrunnable read-only path when WooCommerce is inactive', async () => {
		fetchWooCacheSelfTest.mockResolvedValueOnce( {
			success: true,
			data: {
				woo_active: false,
				runnable: true,
				all_pass: false,
				excluded_paths: [ 'cart' ],
			},
		} );

		render( <WelcomePanel /> );
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Run test – Verify WooCommerce Cart Bypass',
			} )
		);

		await waitFor( () =>
			expect(
				screen.getAllByText(
					'WooCommerce is not active — showing default exclusion paths read-only. Dynamic pages fail open to uncached.'
				).length
			).toBeGreaterThanOrEqual( 1 )
		);
	} );

	it( 'FAIL result routes to safe mode via onNavigate plus scroll', async () => {
		jest.useFakeTimers();
		try {
			fetchWooCacheSelfTest.mockResolvedValueOnce( {
				success: true,
				data: {
					woo_active: true,
					runnable: true,
					all_pass: false,
					excluded_paths: [ 'cart' ],
				},
			} );
			document.body.innerHTML =
				'<div id="wppoWooSafeMode"><input type="checkbox" /></div>';
			const anchor = document.getElementById( 'wppoWooSafeMode' );
			anchor.scrollIntoView = jest.fn();
			const input = anchor.querySelector( 'input' );
			input.focus = jest.fn();
			const onNavigate = jest.fn();

			render( <WelcomePanel onNavigate={ onNavigate } /> );
			fireEvent.click(
				screen.getByRole( 'button', {
					name: 'Run test – Verify WooCommerce Cart Bypass',
				} )
			);

			await waitFor( () =>
				expect( fetchWooCacheSelfTest ).toHaveBeenCalled()
			);
			const failButton = await screen.findByRole( 'button', {
				name: 'Enable safe mode in Dashboard → Page Cache',
			} );
			fireEvent.click( failButton );
			expect( onNavigate ).toHaveBeenCalledWith( 'dashboard' );
			act( () => {
				jest.runAllTimers();
			} );
			expect( anchor.scrollIntoView ).toHaveBeenCalled();
			expect( input.focus ).toHaveBeenCalled();
		} finally {
			jest.useRealTimers();
			document.body.innerHTML = '';
		}
	} );

	it( 'renders the fail CTA as a button even when onNavigate is absent', async () => {
		jest.useFakeTimers();
		try {
			fetchWooCacheSelfTest.mockResolvedValueOnce( {
				success: true,
				data: {
					woo_active: true,
					runnable: true,
					all_pass: false,
					excluded_paths: [ 'cart' ],
				},
			} );

			render( <WelcomePanel /> );
			fireEvent.click(
				screen.getByRole( 'button', {
					name: 'Run test – Verify WooCommerce Cart Bypass',
				} )
			);

			await waitFor( () =>
				expect( fetchWooCacheSelfTest ).toHaveBeenCalled()
			);
			const failButton = await screen.findByRole( 'button', {
				name: 'Enable safe mode in Dashboard → Page Cache',
			} );
			expect( failButton.tagName ).toBe( 'BUTTON' );
			// No anchor fallback: jsdom has no #wppoWooSafeMode, so the
			// button surfaces the guidance notice instead of hash-jumping.
			fireEvent.click( failButton );
			act( () => {
				// Advance past the last 500ms scroll retry without
				// reaching the 5s notice auto-dismiss timer.
				jest.advanceTimersByTime( 600 );
			} );
			expect(
				screen.getByText(
					'Open Dashboard → Page Cache and turn WooCommerce safe mode back on.'
				)
			).toBeInTheDocument();
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'treats a malformed payload with all_pass missing as inconclusive', async () => {
		fetchWooCacheSelfTest.mockResolvedValueOnce( {
			success: true,
			data: {
				woo_active: true,
				runnable: true,
				excluded_paths: [ 'cart' ],
			},
		} );

		render( <WelcomePanel /> );
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Run test – Verify WooCommerce Cart Bypass',
			} )
		);

		await waitFor( () =>
			expect( fetchWooCacheSelfTest ).toHaveBeenCalled()
		);
		// Inconclusive info notice — never a FAIL warning with no evidence.
		expect(
			await screen.findByText(
				'WooCommerce self-test result inconclusive — please re-run the test.'
			)
		).toBeInTheDocument();
		// No fix CTA without explicit failure evidence.
		expect(
			screen.queryByRole( 'button', {
				name: 'Enable safe mode in Dashboard → Page Cache',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'surfaces a retry hint on AbortError timeout', async () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		const abortError = new Error( 'Aborted' );
		abortError.name = 'AbortError';
		fetchWooCacheSelfTest.mockRejectedValueOnce( abortError );

		render( <WelcomePanel /> );
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Run test – Verify WooCommerce Cart Bypass',
			} )
		);

		await waitFor( () =>
			expect(
				screen.getByText(
					'The WooCommerce self-test timed out after 5 seconds. Please retry.'
				)
			).toBeInTheDocument()
		);
		expect( console.error ).toHaveBeenCalledWith(
			'Woo self-test timed out:',
			'Aborted'
		);
	} );

	it( 'aborts the in-flight self-test on unmount', async () => {
		let abortListener = null;
		fetchWooCacheSelfTest.mockImplementationOnce(
			( signal ) =>
				new Promise( ( resolve, reject ) => {
					if ( signal ) {
						abortListener = () => {
							const abortError = new Error( 'Aborted' );
							abortError.name = 'AbortError';
							reject( abortError );
						};
						signal.addEventListener( 'abort', abortListener );
					}
				} )
		);
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		const { unmount } = render( <WelcomePanel /> );
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Run test – Verify WooCommerce Cart Bypass',
			} )
		);
		await waitFor( () =>
			expect( fetchWooCacheSelfTest ).toHaveBeenCalled()
		);
		unmount();
		// Cleanup aborts the controller; the abort listener fires.
		expect( abortListener ).not.toBeNull();
	} );
} );
