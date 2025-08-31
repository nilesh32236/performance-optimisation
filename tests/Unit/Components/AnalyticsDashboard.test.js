/**
 * Unit tests for AnalyticsDashboard component.
 *
 * @package
 */

/**
 * External dependencies
 */
import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
/**
 * Internal dependencies
 */
import AnalyticsDashboard from '../../../admin/src/components/Analytics/AnalyticsDashboard';

// Mock the child components
jest.mock( '../../../admin/src/components/Analytics/MetricsOverview', () => {
	return function MetricsOverview( { overview } ) {
		return (
			<div data-testid="metrics-overview">Metrics Overview: { overview.performance_score }</div>
		);
	};
} );

jest.mock( '../../../admin/src/components/Analytics/OptimizationStatus', () => {
	return function OptimizationStatus( { status } ) {
		return <div data-testid="optimization-status">Optimization Status</div>;
	};
} );

jest.mock( '../../../admin/src/components/Analytics/DashboardChart', () => {
	return function DashboardChart( { title, metric } ) {
		return <div data-testid={ `chart-${ metric }` }>Chart: { title }</div>;
	};
} );

jest.mock( '../../../admin/src/components/Analytics/RecommendationsList', () => {
	return function RecommendationsList( { recommendations } ) {
		return (
			<div data-testid="recommendations-list">Recommendations: { recommendations.length }</div>
		);
	};
} );

jest.mock( '../../../admin/src/components/Card', () => {
	return function Card( { children, title, className } ) {
		return (
			<div className={ className } data-testid="card">
				{ title && <h3>{ title }</h3> }
				{ children }
			</div>
		);
	};
} );

jest.mock( '../../../admin/src/components/Button', () => {
	return function Button( { children, onClick, loading, variant } ) {
		return (
			<button
				onClick={ onClick }
				disabled={ loading }
				data-testid="button"
				data-variant={ variant }
			>
				{ loading ? 'Loading...' : children }
			</button>
		);
	};
} );

jest.mock( '../../../admin/src/components/LoadingSpinner', () => {
	return function LoadingSpinner( { size } ) {
		return (
			<div data-testid="loading-spinner" data-size={ size }>
				Loading...
			</div>
		);
	};
} );

// Mock fetch
global.fetch = jest.fn();

// Mock window.wppoAdmin
Object.defineProperty( window, 'wppoAdmin', {
	value: {
		apiUrl: '/wp-json/performance-optimisation/v1',
		nonce: 'test-nonce',
	},
	writable: true,
} );

describe( 'AnalyticsDashboard', () => {
	const mockDashboardData = {
		overview: {
			performance_score: 85,
			average_load_time: 1500,
			cache_hit_ratio: 80,
			total_page_views: 1000,
			optimization_status: {
				page_caching: true,
				css_minification: true,
			},
		},
		optimization_status: {
			features: {
				page_caching: true,
				css_minification: true,
				js_minification: false,
			},
			image_optimization: {
				total_optimized: 50,
				total_pending: 10,
				optimization_ratio: 83.3,
			},
		},
		charts: {
			page_load_time: {
				daily_trends: [
					{ date: '2024-01-01', value: 1500, sample_count: 100 },
					{ date: '2024-01-02', value: 1400, sample_count: 120 },
				],
				average: 1450,
				metric_name: 'page_load_time',
			},
			cache_hit_ratio: {
				daily_trends: [
					{ date: '2024-01-01', value: 80, hits: 80, total: 100 },
					{ date: '2024-01-02', value: 85, hits: 85, total: 100 },
				],
				average: 82.5,
				metric_name: 'cache_hit_ratio',
			},
			memory_usage: {
				daily_trends: [
					{ date: '2024-01-01', value: 128000000, sample_count: 50 },
					{ date: '2024-01-02', value: 132000000, sample_count: 55 },
				],
				average: 130000000,
				metric_name: 'memory_usage',
			},
		},
		recommendations: [
			{
				type: 'performance',
				priority: 'medium',
				title: 'Enable JS Minification',
				description: 'JavaScript minification can reduce file sizes.',
				actions: [ 'Enable JS minification in settings' ],
			},
		],
		last_updated: '2024-01-01 12:00:00',
	};

	beforeEach( () => {
		fetch.mockClear();
	} );

	afterEach( () => {
		jest.clearAllMocks();
	} );

	test( 'renders loading state initially', () => {
		fetch.mockImplementation( () => new Promise( () => {} ) ); // Never resolves

		render( <AnalyticsDashboard /> );

		expect( screen.getByTestId( 'loading-spinner' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Loading analytics dashboard...' ) ).toBeInTheDocument();
	} );

	test( 'renders dashboard data after successful fetch', async () => {
		fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( {
				success: true,
				data: mockDashboardData,
			} ),
		} );

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'Performance Analytics' ) ).toBeInTheDocument();
		} );

		expect( screen.getByTestId( 'metrics-overview' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'optimization-status' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'chart-page_load_time' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'chart-cache_hit_ratio' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'chart-memory_usage' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'recommendations-list' ) ).toBeInTheDocument();
	} );

	test( 'renders error state on fetch failure', async () => {
		fetch.mockRejectedValueOnce( new Error( 'Network error' ) );

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'Failed to Load Analytics' ) ).toBeInTheDocument();
		} );

		expect( screen.getByText( 'Network error' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Retry' ) ).toBeInTheDocument();
	} );

	test( 'renders error state on API error response', async () => {
		fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( {
				success: false,
				message: 'API Error',
			} ),
		} );

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'Failed to Load Analytics' ) ).toBeInTheDocument();
		} );

		expect( screen.getByText( 'API Error' ) ).toBeInTheDocument();
	} );

	test( 'renders empty state when no data available', async () => {
		fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( {
				success: true,
				data: null,
			} ),
		} );

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'No Analytics Data Available' ) ).toBeInTheDocument();
		} );

		expect(
			screen.getByText(
				'Analytics data will appear here once your site starts collecting performance metrics.'
			)
		).toBeInTheDocument();
	} );

	test( 'handles refresh button click', async () => {
		fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { success: true, data: mockDashboardData } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { success: true, data: mockDashboardData } ),
			} );

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'Performance Analytics' ) ).toBeInTheDocument();
		} );

		const refreshButton = screen.getByText( 'Refresh' );
		fireEvent.click( refreshButton );

		expect( fetch ).toHaveBeenCalledTimes( 2 );
	} );

	test( 'handles period selector change', async () => {
		fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( { success: true, data: mockDashboardData } ),
		} );

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'Performance Analytics' ) ).toBeInTheDocument();
		} );

		const periodSelector = screen.getByDisplayValue( 'Last 30 Days' );
		fireEvent.change( periodSelector, { target: { value: 'month' } } );

		expect( periodSelector.value ).toBe( 'month' );
	} );

	test( 'handles CSV export', async () => {
		fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { success: true, data: mockDashboardData } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( {
					success: true,
					data: 'CSV,Data\nTest,123',
					filename: 'performance-report.csv',
				} ),
			} );

		// Mock URL.createObjectURL and related functions
		global.URL.createObjectURL = jest.fn( () => 'blob:test-url' );
		global.URL.revokeObjectURL = jest.fn();

		// Mock document.createElement and appendChild/removeChild
		const mockLink = {
			href: '',
			download: '',
			click: jest.fn(),
		};
		document.createElement = jest.fn( () => mockLink );
		document.body.appendChild = jest.fn();
		document.body.removeChild = jest.fn();

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'Performance Analytics' ) ).toBeInTheDocument();
		} );

		const exportButton = screen.getByText( 'Export CSV' );
		fireEvent.click( exportButton );

		await waitFor( () => {
			expect( fetch ).toHaveBeenCalledWith(
				expect.stringContaining( '/analytics/export?format=csv' ),
				expect.objectContaining( {
					headers: {
						'X-WP-Nonce': 'test-nonce',
					},
				} )
			);
		} );
	} );

	test( 'handles JSON export', async () => {
		fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { success: true, data: mockDashboardData } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( {
					success: true,
					data: { test: 'data' },
					filename: 'performance-report.json',
				} ),
			} );

		// Mock URL.createObjectURL and related functions
		global.URL.createObjectURL = jest.fn( () => 'blob:test-url' );
		global.URL.revokeObjectURL = jest.fn();

		const mockLink = {
			href: '',
			download: '',
			click: jest.fn(),
		};
		document.createElement = jest.fn( () => mockLink );
		document.body.appendChild = jest.fn();
		document.body.removeChild = jest.fn();

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'Performance Analytics' ) ).toBeInTheDocument();
		} );

		const exportButton = screen.getByText( 'Export JSON' );
		fireEvent.click( exportButton );

		await waitFor( () => {
			expect( fetch ).toHaveBeenCalledWith(
				expect.stringContaining( '/analytics/export?format=json' ),
				expect.objectContaining( {
					headers: {
						'X-WP-Nonce': 'test-nonce',
					},
				} )
			);
		} );
	} );

	test( 'displays last updated time', async () => {
		fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( { success: true, data: mockDashboardData } ),
		} );

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( /Last updated:/ ) ).toBeInTheDocument();
		} );
	} );

	test( 'renders quick actions section', async () => {
		fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( { success: true, data: mockDashboardData } ),
		} );

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'Quick Actions' ) ).toBeInTheDocument();
		} );

		expect( screen.getByText( 'Generate Full Report' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Clear All Caches' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Run Performance Test' ) ).toBeInTheDocument();
		expect( screen.getByText( 'View Detailed Metrics' ) ).toBeInTheDocument();
	} );

	test( 'handles network errors gracefully', async () => {
		fetch.mockImplementation( () => Promise.reject( new Error( 'Network error' ) ) );

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'Failed to Load Analytics' ) ).toBeInTheDocument();
		} );

		expect( screen.getByText( 'Network error' ) ).toBeInTheDocument();
	} );

	test( 'handles HTTP error responses', async () => {
		fetch.mockResolvedValueOnce( {
			ok: false,
			status: 500,
			statusText: 'Internal Server Error',
		} );

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'Failed to Load Analytics' ) ).toBeInTheDocument();
		} );

		expect( screen.getByText( 'Failed to fetch dashboard data' ) ).toBeInTheDocument();
	} );

	test( 'shows recommendations section only when recommendations exist', async () => {
		const dataWithoutRecommendations = {
			...mockDashboardData,
			recommendations: [],
		};

		fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( { success: true, data: dataWithoutRecommendations } ),
		} );

		render( <AnalyticsDashboard /> );

		await waitFor( () => {
			expect( screen.getByText( 'Performance Analytics' ) ).toBeInTheDocument();
		} );

		expect( screen.queryByTestId( 'recommendations-list' ) ).not.toBeInTheDocument();
	} );

	test( 'applies correct CSS classes', async () => {
		fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( { success: true, data: mockDashboardData } ),
		} );

		const { container } = render( <AnalyticsDashboard className="custom-class" /> );

		await waitFor( () => {
			expect( screen.getByText( 'Performance Analytics' ) ).toBeInTheDocument();
		} );

		expect( container.firstChild ).toHaveClass( 'wppo-analytics-dashboard' );
		expect( container.firstChild ).toHaveClass( 'custom-class' );
	} );
} );
