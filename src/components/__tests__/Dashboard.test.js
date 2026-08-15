import {
	render,
	screen,
	waitFor,
	fireEvent,
	act,
} from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

jest.mock( '../WelcomePanel', () => () => <div data-testid="welcome-panel" /> );
jest.mock( '../PerformanceAudit', () => () => (
	<div data-testid="performance-audit" />
) );
jest.mock( '../PageSpeedPanel', () => () => (
	<div data-testid="pagespeed-panel" />
) );
jest.mock( '../SuggestionsPanel', () => () => (
	<div data-testid="suggestions-panel" />
) );
jest.mock( '../SystemInfo', () => () => <div data-testid="system-info" /> );
jest.mock( '../WebVitalsTrends', () => () => (
	<div data-testid="web-vitals-trends" />
) );
jest.mock( '../WebVitalsRum', () => () => <div data-testid="rum-panel" /> );
jest.mock( '../AutoloadedOptions', () => () => (
	<div data-testid="autoloaded-options" />
) );
jest.mock( '../ImageOptimizationCard', () => ( { onOptimize, onRemove } ) => (
	<div data-testid="image-card">
		<button onClick={ onOptimize }>Optimize Images</button>
		<button onClick={ onRemove }>Remove Images</button>
	</div>
) );
jest.mock( '../RecentActivityCard', () => () => (
	<div data-testid="activity-card" />
) );
jest.mock( '../common/FeatureHeader', () => ( { actions } ) => (
	<div data-testid="feature-header">{ actions }</div>
) );
jest.mock( '../common/FeatureCard', () => ( { children, footer } ) => (
	<div data-testid="feature-card">
		{ children }
		{ footer }
	</div>
) );
jest.mock( '../common/LoadingSubmitButton', () => ( { onClick, label } ) => (
	<button onClick={ onClick }>{ label }</button>
) );
jest.mock(
	'../common/SwitchField',
	() =>
		( { label, name, checked, onChange } ) => (
			<label htmlFor={ name }>
				<input
					id={ name }
					type="checkbox"
					checked={ checked }
					onChange={ onChange }
				/>
				{ label }
			</label>
		)
);
jest.mock(
	'../common/CheckboxOption',
	() =>
		( { label, name, checked, onChange } ) => (
			<label htmlFor={ `role-${ name }` }>
				<input
					id={ `role-${ name }` }
					type="checkbox"
					name={ name }
					checked={ checked }
					onChange={ onChange }
				/>
				{ label }
			</label>
		)
);
jest.mock(
	'../common/ConfirmDialog',
	() =>
		( { isOpen, onConfirm, onCancel } ) =>
			isOpen ? (
				<div data-testid="confirm-dialog">
					<button onClick={ onConfirm }>Confirm</button>
					<button onClick={ onCancel }>Cancel</button>
				</div>
			) : null
);

import Dashboard from '../Dashboard';
import { apiCall } from '../../lib/apiRequest';

/**
 * Wait for the mount-time database_cleanup_counts call to start AND for the
 * resolved promise to be handled inside act(), so subsequent assertions never
 * race the async state update (which otherwise emits act() warnings).
 */
const flushDashboardMount = async () => {
	await waitFor( () => {
		expect( apiCall ).toHaveBeenCalledWith(
			'database_cleanup_counts',
			{},
			'GET'
		);
	} );
	await act( async () => {} );
};

describe( 'Dashboard', () => {
	beforeEach( () => {
		global.wppoSettings = {
			cache_size: '10 MB',
			total_js_css: { js: 2, css: 3 },
			image_info: {},
			performance_audit: { homeUrl: 'http://example.com' },
			settings: { cache_settings: {} },
			userRoles: {
				administrator: 'Administrator',
				subscriber: 'Subscriber',
			},
		};
		jest.clearAllMocks();
		// Persistent default so the mount-time database_cleanup_counts call
		// always resolves; action-specific responses queue via mockResolvedValueOnce.
		apiCall.mockResolvedValue( { success: true, data: {} } );
	} );

	it( 'renders stats and the welcome panel', async () => {
		render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

		await flushDashboardMount();

		expect( screen.getByTestId( 'welcome-panel' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Cache Size' ) ).toBeInTheDocument();
		expect( screen.getByText( '10 MB' ) ).toBeInTheDocument();
		expect( screen.getByText( '5' ) ).toBeInTheDocument();
	} );

	it( 'clears the cache and announces success', async () => {
		apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
		apiCall.mockResolvedValueOnce( { success: true } );

		render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

		await flushDashboardMount();

		fireEvent.click(
			screen.getByRole( 'button', { name: /Purge All Cache/i } )
		);

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'clear_cache', {
				action: 'clear_cache',
			} )
		);
		expect(
			screen.getByText( 'Cache cleared successfully.' )
		).toBeInTheDocument();
	} );

	it( 'announces failure when clearing the cache fails', async () => {
		apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
		apiCall.mockRejectedValueOnce( new Error( 'boom' ) );

		render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

		await flushDashboardMount();

		fireEvent.click(
			screen.getByRole( 'button', { name: /Purge All Cache/i } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( 'Failed to clear cache.' )
			).toBeInTheDocument()
		);
	} );

	it( 'starts a background image optimisation', async () => {
		try {
			jest.useFakeTimers();
			apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
			apiCall.mockResolvedValueOnce( {
				success: true,
				data: { background: true, jobs_queued: 3 },
			} );
			apiCall.mockResolvedValueOnce( {
				// pollJobStatus
				success: true,
				data: {
					queued_jobs: 0,
					completed: {},
					pending: {},
					failed: {},
				},
			} );

			render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

			await flushDashboardMount();

			fireEvent.click(
				screen.getByRole( 'button', { name: /Optimize Images/i } )
			);

			await waitFor( () =>
				expect(
					screen.getByText(
						'Image optimisation started in background.'
					)
				).toBeInTheDocument()
			);

			await act( async () => {
				jest.advanceTimersByTime( 5000 );
			} );
			await act( async () => {} );

			await waitFor( () => {
				expect( apiCall ).toHaveBeenCalledWith(
					'image_job_status',
					{},
					'GET'
				);
				expect(
					screen.getByText( 'Image optimisation completed.' )
				).toBeInTheDocument();
			} );
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'fails a synchronous image optimisation', async () => {
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		try {
			apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
			apiCall.mockRejectedValueOnce( new Error( 'error' ) );

			render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

			await flushDashboardMount();

			fireEvent.click(
				screen.getByRole( 'button', { name: /Optimize Images/i } )
			);

			await waitFor( () =>
				expect(
					screen.getByText( 'Image optimisation failed.' )
				).toBeInTheDocument()
			);
		} finally {
			consoleSpy.mockRestore();
		}
	} );

	it( 'does not poll image_job_status after a synchronous optimisation clears the poll state', async () => {
		try {
			jest.useFakeTimers();
			apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
			apiCall.mockResolvedValueOnce( {
				success: true,
				data: { background: true, jobs_queued: 3 },
			} );

			render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

			await flushDashboardMount();

			fireEvent.click(
				screen.getByRole( 'button', { name: /Optimize Images/i } )
			);

			await waitFor( () =>
				expect(
					screen.getByText(
						'Image optimisation started in background.'
					)
				).toBeInTheDocument()
			);

			apiCall.mockResolvedValueOnce( {
				// Mock next job status instead to bypass 'bgProcessing' guard early
				success: true,
				data: {
					queued_jobs: 0,
					completed: {},
					pending: {},
					failed: {},
				},
			} );

			await act( async () => {
				jest.advanceTimersByTime( 5000 );
			} );
			await act( async () => {} );

			// Now that the job finished (queued_jobs: 0), it clears bgProcessing.
			// Let's trigger a fresh optimization sync path.
			apiCall.mockResolvedValueOnce( {
				success: true,
				data: {
					pending: { webp: 0, avif: 0 },
					completed: { webp: 1, avif: 1 },
					failed: { webp: 0, avif: 0 },
				}, // Sync path clears timeout too
			} );

			fireEvent.click(
				screen.getByRole( 'button', { name: /Optimize Images/i } )
			);

			await waitFor( () =>
				expect(
					screen.getByText( 'Images optimized successfully.' )
				).toBeInTheDocument()
			);

			// The background timer should not fire anymore because the sync
			// path cleared it. Wait 5s and make sure image_job_status
			// is not called again.
			apiCall.mockClear();

			await act( async () => {
				jest.advanceTimersByTime( 5000 );
			} );
			await act( async () => {} );

			expect( apiCall ).not.toHaveBeenCalledWith(
				'image_job_status',
				{},
				'GET'
			);
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'completes a synchronous image optimisation', async () => {
		apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				pending: { webp: 0, avif: 0 },
				completed: { webp: 1, avif: 1 },
				failed: { webp: 0, avif: 0 },
			},
		} );

		render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

		await flushDashboardMount();

		fireEvent.click(
			screen.getByRole( 'button', { name: /Optimize Images/i } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( 'Images optimized successfully.' )
			).toBeInTheDocument()
		);
	} );

	it( 'fails a background image optimisation status check', async () => {
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		try {
			jest.useFakeTimers();
			apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
			apiCall.mockResolvedValueOnce( {
				success: true,
				data: { background: true, jobs_queued: 3 },
			} );
			apiCall.mockRejectedValue( new Error( 'network error' ) ); // pollJobStatus fail

			render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

			await flushDashboardMount();

			fireEvent.click(
				screen.getByRole( 'button', { name: /Optimize Images/i } )
			);

			await waitFor( () =>
				expect(
					screen.getByText(
						'Image optimisation started in background.'
					)
				).toBeInTheDocument()
			);

			// Loop until the terminal notice appears instead of a hardcoded count
			let iterations = 0;
			while (
				iterations++ < 10 &&
				! screen.queryByText(
					'Status check stopped after repeated failures.'
				)
			) {
				await act( async () => {
					jest.advanceTimersByTime( 5000 );
				} );
				await act( async () => {} );
			}

			await waitFor( () => {
				expect(
					screen.getByText(
						'Status check stopped after repeated failures.'
					)
				).toBeInTheDocument();
			} );
		} finally {
			jest.useRealTimers();
			consoleSpy.mockRestore();
		}
	} );

	it( 'saves logged-in cache settings', async () => {
		apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
		apiCall.mockResolvedValueOnce( { success: true, data: {} } );

		render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

		await flushDashboardMount();

		// Enable the toggle then save.
		fireEvent.click( screen.getByLabelText( 'Enable' ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Settings/i } )
		);

		await waitFor( () =>
			expect(
				screen.getByText( 'Logged-in cache settings saved.' )
			).toBeInTheDocument()
		);
	} );

	it( 'saves the page cache master toggle', async () => {
		apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
		apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // save

		render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

		await flushDashboardMount();

		fireEvent.click( screen.getByLabelText( 'Enable Page Cache' ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Page Cache Settings/i } )
		);

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'cache_settings',
				settings: expect.objectContaining( { enableCache: true } ),
			} )
		);
	} );

	it( 'preserves other cache settings when saving one group', async () => {
		global.wppoSettings.settings.cache_settings = {
			enableCache: true,
		};
		apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
		apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // save

		render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

		await flushDashboardMount();

		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Settings/i } )
		);

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'cache_settings',
				settings: expect.objectContaining( { enableCache: true } ),
			} )
		);
	} );

	it( 'saves CDN purge settings', async () => {
		apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
		apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // save

		render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

		await flushDashboardMount();

		fireEvent.change( screen.getByLabelText( /CDN Purge Service/i ), {
			target: { value: 'cloudflare' },
		} );
		fireEvent.change( screen.getByLabelText( /Cloudflare Zone ID/i ), {
			target: { value: 'abc123' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save CDN Purge/i } )
		);

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'cache_settings',
				settings: expect.objectContaining( {
					cdnPurgeService: 'cloudflare',
					cloudflareZoneId: 'abc123',
				} ),
			} )
		);
	} );

	it( 'removes optimized images after confirming the dialog', async () => {
		apiCall.mockResolvedValueOnce( { success: true, data: {} } ); // mount db counts
		apiCall.mockResolvedValueOnce( { success: true } );

		render( <Dashboard activities={ [] } onNavigate={ jest.fn() } /> );

		await flushDashboardMount();

		fireEvent.click(
			screen.getByRole( 'button', { name: /Remove Images/i } )
		);
		expect( screen.getByTestId( 'confirm-dialog' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: /Confirm/i } ) );

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith(
				'delete_optimised_image',
				{}
			)
		);
		expect(
			screen.getByText( 'Optimized images removed.' )
		).toBeInTheDocument();
	} );
} );
