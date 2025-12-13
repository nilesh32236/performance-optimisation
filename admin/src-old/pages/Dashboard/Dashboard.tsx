/**
 * Dashboard Page Component
 *
 * @package
 * @since 1.1.0
 */

/**
 * External dependencies
 */
import React, { useState, useEffect } from 'react';
import { PerformanceMetrics, OptimizationStats } from '@types/index';
import { Card, Button, LoadingSpinner, Tabs, Tab } from '@components/index';
/**
 * Internal dependencies
 */
import { MetricsChart } from './components/MetricsChart';
import { StatsOverview } from './components/StatsOverview';
import { RecentActivity } from './components/RecentActivity';
import { RealTimeMonitor } from '@components/RealTimeMonitor';
import { InteractiveOptimizationControls } from '@components/InteractiveOptimizationControls';
import { QueueStats } from '@components/Queue';


export const Dashboard: React.FC = () => {
	const [metrics, setMetrics] = useState<PerformanceMetrics | null>(null);
	const [stats, setStats] = useState<OptimizationStats | null>(null);
	const [loading, setLoading] = useState(true);
	const [refreshing, setRefreshing] = useState(false);
	const [activeTab, setActiveTab] = useState('overview');

	const loadDashboardData = async () => {
		try {
			// Load data from WordPress global or API
			if (window.wppoAdmin?.metrics && window.wppoAdmin?.stats) {
				setMetrics(window.wppoAdmin.metrics);
				setStats(window.wppoAdmin.stats);
			} else {
				// Fallback to API call
				const response = await fetch(`${window.wppoAdmin?.apiUrl}/dashboard`, {
					headers: {
						'X-WP-Nonce': window.wppoAdmin?.nonce || '',
					},
				});

				if (response.ok) {
					const data = await response.json();
					setMetrics(data.metrics);
					setStats(data.stats);
				}
			}
		} catch (error) {
			console.error('Failed to load dashboard data:', error);
		} finally {
			setLoading(false);
			setRefreshing(false);
		}
	};

	const handleRefresh = async () => {
		setRefreshing(true);
		await loadDashboardData();
	};

	useEffect(() => {
		loadDashboardData();
	}, []);

	if (loading) {
		return (
			<div className="wppo-dashboard-loading">
				<LoadingSpinner size="large" />
				<p>Loading dashboard data...</p>
			</div>
		);
	}

	return (
		<div className="wppo-dashboard">
			<div className="wppo-dashboard__header">
				<div className="wppo-dashboard__header-content">
					<h1>Performance Dashboard</h1>
					<p>Monitor your site&apos;s performance metrics and optimization statistics in real-time.</p>
				</div>
				<div className="wppo-dashboard__header-actions">
					<Button variant="secondary" onClick={handleRefresh} loading={refreshing}>
						Refresh Data
					</Button>
				</div>
			</div>

			<div className="wppo-dashboard__content">
				<Tabs activeTab={activeTab} onTabChange={setActiveTab}>
					<Tab id="overview" label="Overview">
						{ /* Performance Metrics Overview */}
						<div className="wppo-dashboard__section">
							<h2>Performance Metrics</h2>
							<div className="wppo-dashboard__metrics-grid">
								<Card title="Page Load Time" className="wppo-metric-card">
									<div className="wppo-metric-value">
										{metrics?.page_load_time
											? `${metrics.page_load_time.toFixed(2)}s`
											: 'N/A'}
									</div>
									<div className="wppo-metric-label">Average load time</div>
								</Card>

								<Card title="First Contentful Paint" className="wppo-metric-card">
									<div className="wppo-metric-value">
										{metrics?.first_contentful_paint
											? `${metrics.first_contentful_paint.toFixed(2)}s`
											: 'N/A'}
									</div>
									<div className="wppo-metric-label">Time to first content</div>
								</Card>

								<Card title="Largest Contentful Paint" className="wppo-metric-card">
									<div className="wppo-metric-value">
										{metrics?.largest_contentful_paint
											? `${metrics.largest_contentful_paint.toFixed(2)}s`
											: 'N/A'}
									</div>
									<div className="wppo-metric-label">Time to largest content</div>
								</Card>

								<Card title="Cumulative Layout Shift" className="wppo-metric-card">
									<div className="wppo-metric-value">
										{metrics?.cumulative_layout_shift
											? metrics.cumulative_layout_shift.toFixed(3)
											: 'N/A'}
									</div>
									<div className="wppo-metric-label">Layout stability score</div>
								</Card>
							</div>
						</div>

						{ /* Performance Chart */}
						{metrics && (
							<div className="wppo-dashboard__section">
								<Card title="Performance Trends">
									<MetricsChart metrics={metrics} />
								</Card>
							</div>
						)}

						{ /* Optimization Statistics */}
						<div className="wppo-dashboard__section">
							<h2>Optimization Statistics</h2>
							<div className="wppo-dashboard__stats-grid">
								{stats && <StatsOverview stats={stats} />}
							</div>
						</div>

						{ /* Image Conversion Queue */}
						<div className="wppo-dashboard__section">
							<QueueStats />
						</div>

						{ /* Quick Actions */}
						<div className="wppo-dashboard__section">
							<Card title="Quick Actions">
								<div className="wppo-dashboard__actions">
									<Button variant="primary">Run Performance Test</Button>
									<Button variant="secondary">Clear All Caches</Button>
									<Button variant="secondary">Optimize Images</Button>
									<Button variant="tertiary">View Settings</Button>
								</div>
							</Card>
						</div>
					</Tab>

					<Tab id="realtime" label="Real-Time Monitoring">
						<div className="wppo-dashboard__section">
							<RealTimeMonitor />
						</div>

						{ /* Recent Activity */}
						<div className="wppo-dashboard__section">
							<Card title="Recent Activity">
								<RecentActivity />
							</Card>
						</div>
					</Tab>

					<Tab id="optimization" label="Interactive Controls">
						<div className="wppo-dashboard__section">
							<InteractiveOptimizationControls />
						</div>
					</Tab>

					<Tab id="analytics" label="Advanced Analytics">
						<div className="wppo-dashboard__section">
							<Card title="Performance Analysis">
								<div className="wppo-performance-analysis">
									<div className="wppo-analysis-grid">
										<div className="wppo-analysis-item">
											<h4>Core Web Vitals</h4>
											<div className="wppo-vitals">
												<div className="wppo-vital">
													<span className="wppo-vital-label">LCP</span>
													<span className="wppo-vital-value good">1.2s</span>
												</div>
												<div className="wppo-vital">
													<span className="wppo-vital-label">FID</span>
													<span className="wppo-vital-value good">45ms</span>
												</div>
												<div className="wppo-vital">
													<span className="wppo-vital-label">CLS</span>
													<span className="wppo-vital-value good">0.05</span>
												</div>
											</div>
										</div>
										<div className="wppo-analysis-item">
											<h4>Resource Breakdown</h4>
											<div className="wppo-resources">
												<div className="wppo-resource">
													<span>HTML</span>
													<div className="wppo-resource-bar">
														<div className="wppo-resource-fill" style={{ width: '15%' }}></div>
													</div>
													<span>23KB</span>
												</div>
												<div className="wppo-resource">
													<span>CSS</span>
													<div className="wppo-resource-bar">
														<div className="wppo-resource-fill" style={{ width: '25%' }}></div>
													</div>
													<span>45KB</span>
												</div>
												<div className="wppo-resource">
													<span>JS</span>
													<div className="wppo-resource-bar">
														<div className="wppo-resource-fill" style={{ width: '35%' }}></div>
													</div>
													<span>67KB</span>
												</div>
												<div className="wppo-resource">
													<span>Images</span>
													<div className="wppo-resource-bar">
														<div className="wppo-resource-fill" style={{ width: '60%' }}></div>
													</div>
													<span>234KB</span>
												</div>
											</div>
										</div>
									</div>
								</div>
							</Card>
						</div>

						<div className="wppo-dashboard__section">
							<Card title="Optimization Recommendations">
								<p>AI-powered optimization recommendations based on your site's performance data.</p>
								{ /* This could integrate with the RecommendationsController */}
							</Card>
						</div>
					</Tab>
				</Tabs>
			</div>
		</div>
	);
};
