/**
 * External dependencies
 */
import React, { useState, useEffect } from 'react';
/**
 * Internal dependencies
 */
import { Card } from '../Card';
import { Button } from '../Button';
import { LoadingSpinner } from '../LoadingSpinner';
import DashboardChart from './DashboardChart';
import MetricsOverview from './MetricsOverview';
import RecommendationsList from './RecommendationsList';
import OptimizationStatus from './OptimizationStatus';
import './AnalyticsDashboard.scss';

interface DashboardData {
	overview: {
		performance_score: number;
		average_load_time: number;
		cache_hit_ratio: number;
		total_page_views: number;
		optimization_status: Record<string, boolean>;
	};
	optimization_status: {
		features: Record<string, boolean>;
		image_optimization: {
			total_optimized: number;
			total_pending: number;
			optimization_ratio: number;
		};
	};
	charts: {
		page_load_time: ChartData;
		cache_hit_ratio: ChartData;
		memory_usage: ChartData;
	};
	recommendations: Recommendation[];
	last_updated: string;
}

interface ChartData {
	daily_trends: Array<{
		date: string;
		value: number;
		sample_count?: number;
	}>;
	average: number;
	metric_name: string;
}

interface Recommendation {
	type: string;
	priority: 'high' | 'medium' | 'low';
	title: string;
	description: string;
	actions: string[];
}

interface AnalyticsDashboardProps {
	className?: string;
}

const AnalyticsDashboard: React.FC<AnalyticsDashboardProps> = ( { className = '' } ) => {
	const [ dashboardData, setDashboardData ] = useState<DashboardData | null>( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isRefreshing, setIsRefreshing ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );
	const [ selectedPeriod, setSelectedPeriod ] = useState<'day' | 'week' | 'month'>( 'week' );

	useEffect( () => {
		loadDashboardData();
	}, [] );

	const loadDashboardData = async () => {
		try {
			setError( null );

			const response = await fetch(
				`${ ( window as any ).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1' }/analytics/dashboard`,
				{
					headers: {
						'X-WP-Nonce': ( window as any ).wppoAdmin?.nonce || '',
					},
				}
			);

			if ( ! response.ok ) {
				throw new Error( 'Failed to fetch dashboard data' );
			}

			const result = await response.json();

			if ( ! result.success ) {
				throw new Error( result.message || 'Failed to load dashboard data' );
			}

			setDashboardData( result.data );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : 'An error occurred' );
		} finally {
			setIsLoading( false );
			setIsRefreshing( false );
		}
	};

	const handleRefresh = async () => {
		setIsRefreshing( true );
		await loadDashboardData();
	};

	const handleExportData = async ( format: 'json' | 'csv' ) => {
		try {
			const response = await fetch(
				`${ ( window as any ).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1' }/analytics/export?format=${ format }`,
				{
					headers: {
						'X-WP-Nonce': ( window as any ).wppoAdmin?.nonce || '',
					},
				}
			);

			if ( ! response.ok ) {
				throw new Error( 'Failed to export data' );
			}

			const result = await response.json();

			if ( result.success ) {
				// Create download link
				const dataStr =
					format === 'json' ? JSON.stringify( result.data, null, 2 ) : result.data;

				const dataBlob = new Blob( [ dataStr ], {
					type: format === 'json' ? 'application/json' : 'text/csv',
				} );

				const url = URL.createObjectURL( dataBlob );
				const link = document.createElement( 'a' );
				link.href = url;
				link.download = result.filename || `performance-report.${ format }`;
				document.body.appendChild( link );
				link.click();
				document.body.removeChild( link );
				URL.revokeObjectURL( url );
			}
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'Export failed:', err );
		}
	};

	if ( isLoading ) {
		return (
			<div className={ `wppo-analytics-dashboard ${ className }` }>
				<div className="wppo-analytics-dashboard__loading">
					<LoadingSpinner size="large" />
					<p>Loading analytics dashboard...</p>
				</div>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className={ `wppo-analytics-dashboard ${ className }` }>
				<div className="wppo-analytics-dashboard__error">
					<div className="wppo-error-icon">
						<span className="dashicons dashicons-warning"></span>
					</div>
					<h3>Failed to Load Analytics</h3>
					<p>{ error }</p>
					<Button onClick={ loadDashboardData } variant="primary">
						Retry
					</Button>
				</div>
			</div>
		);
	}

	if ( ! dashboardData ) {
		return (
			<div className={ `wppo-analytics-dashboard ${ className }` }>
				<div className="wppo-analytics-dashboard__empty">
					<div className="wppo-empty-icon">
						<span className="dashicons dashicons-chart-line"></span>
					</div>
					<h3>No Analytics Data Available</h3>
					<p>
						Analytics data will appear here once your site&apos;s collecting performance
						metrics.
					</p>
				</div>
			</div>
		);
	}

	return (
		<div className={ `wppo-analytics-dashboard ${ className }` }>
			{ /* Dashboard Header */ }
			<div className="wppo-analytics-dashboard__header">
				<div className="wppo-analytics-dashboard__header-content">
					<h2>Performance Analytics</h2>
					<p>
						Monitor your site&apos;s performance metrics and optimization effectiveness.
					</p>
					<div className="wppo-analytics-dashboard__last-updated">
						Last updated: { new Date( dashboardData.last_updated ).toLocaleString() }
					</div>
				</div>
				<div className="wppo-analytics-dashboard__header-actions">
					<div className="wppo-period-selector">
						<select
							value={ selectedPeriod }
							onChange={ ( e ) =>
								setSelectedPeriod( e.target.value as 'day' | 'week' | 'month' )
							}
							className="wppo-select"
						>
							<option value="day">Last 7 Days</option>
							<option value="week">Last 30 Days</option>
							<option value="month">Last 3 Months</option>
						</select>
					</div>
					<div className="wppo-export-buttons">
						<Button
							variant="tertiary"
							size="small"
							onClick={ () => handleExportData( 'csv' ) }
						>
							Export CSV
						</Button>
						<Button
							variant="tertiary"
							size="small"
							onClick={ () => handleExportData( 'json' ) }
						>
							Export JSON
						</Button>
					</div>
					<Button variant="secondary" onClick={ handleRefresh } loading={ isRefreshing }>
						Refresh
					</Button>
				</div>
			</div>

			{ /* Metrics Overview */ }
			<div className="wppo-analytics-dashboard__section">
				<MetricsOverview overview={ dashboardData.overview } />
			</div>

			{ /* Optimization Status */ }
			<div className="wppo-analytics-dashboard__section">
				<OptimizationStatus status={ dashboardData.optimization_status } />
			</div>

			{ /* Performance Charts */ }
			<div className="wppo-analytics-dashboard__section">
				<h3>Performance Trends</h3>
				<div className="wppo-analytics-dashboard__charts-grid">
					<Card className="wppo-chart-card">
						<DashboardChart
							title="Page Load Time"
							metric="page_load_time"
							data={ dashboardData.charts.page_load_time }
							type="area"
							height={ 280 }
						/>
					</Card>

					<Card className="wppo-chart-card">
						<DashboardChart
							title="Cache Hit Ratio"
							metric="cache_hit_ratio"
							data={ dashboardData.charts.cache_hit_ratio }
							type="line"
							height={ 280 }
						/>
					</Card>

					<Card className="wppo-chart-card">
						<DashboardChart
							title="Memory Usage"
							metric="memory_usage"
							data={ dashboardData.charts.memory_usage }
							type="area"
							height={ 280 }
						/>
					</Card>
				</div>
			</div>

			{ /* Recommendations */ }
			{ dashboardData.recommendations.length > 0 && (
				<div className="wppo-analytics-dashboard__section">
					<Card title="Performance Recommendations">
						<RecommendationsList recommendations={ dashboardData.recommendations } />
					</Card>
				</div>
			) }

			{ /* Quick Actions */ }
			<div className="wppo-analytics-dashboard__section">
				<Card title="Quick Actions">
					<div className="wppo-analytics-dashboard__actions">
						<Button variant="primary">Generate Full Report</Button>
						<Button variant="secondary">Clear All Caches</Button>
						<Button variant="secondary">Run Performance Test</Button>
						<Button variant="tertiary">View Detailed Metrics</Button>
					</div>
				</Card>
			</div>
		</div>
	);
};

export default AnalyticsDashboard;
