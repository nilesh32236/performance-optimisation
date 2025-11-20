import React, { useState, useEffect } from 'react';
import { Card, Progress, Button, Select } from '../components/UI';
import { secureApiFetch } from '../utils/security';
import { handleApiError, logError } from '../utils/errorHandler';

interface PerformanceMetrics {
	page_load_time: number;
	cache_hit_rate: number;
	optimization_score: number;
	images_optimized: number;
	size_saved: string;
	recommendations: Array<{
		id: string;
		title: string;
		impact: 'high' | 'medium' | 'low';
		description: string;
	}>;
}

export const DashboardAnalytics: React.FC = () => {
	const [metrics, setMetrics] = useState<PerformanceMetrics | null>(null);
	const [timeRange, setTimeRange] = useState('7d');
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);

	const fetchMetrics = async () => {
		setLoading(true);
		setError(null);
		
		try {
			const data = await secureApiFetch(`/wp-json/performance-optimisation/v1/analytics/dashboard?range=${encodeURIComponent(timeRange)}`);
			
			// Validate received data
			if (!data || typeof data !== 'object') {
				throw new Error('Invalid metrics data received');
			}
			
			setMetrics(data);
		} catch (err) {
			logError(err, { component: 'DashboardAnalytics', action: 'fetchMetrics', timeRange });
			setError(handleApiError(err));
		}
		setLoading(false);
	};

	const applyRecommendation = async (recommendationId: string) => {
		try {
			await secureApiFetch('/wp-json/performance-optimisation/v1/recommendations/apply', {
				method: 'POST',
				data: { id: recommendationId }
			});
			
			// Refresh metrics after applying recommendation
			await fetchMetrics();
		} catch (err) {
			logError(err, { component: 'DashboardAnalytics', action: 'applyRecommendation', recommendationId });
			setError(handleApiError(err));
		}
	};

	useEffect(() => {
		fetchMetrics();
	}, [timeRange]);

	const getScoreColor = (score: number): string => {
		if (score >= 90) return 'success';
		if (score >= 70) return 'warning';
		return 'error';
	};

	const getImpactColor = (impact: string): string => {
		switch (impact) {
			case 'high': return 'error';
			case 'medium': return 'warning';
			case 'low': return 'success';
			default: return 'info';
		}
	};

	if (loading) {
		return (
			<div className="wppo-analytics-loading" role="status" aria-label="Loading analytics">
				<div className="wppo-skeleton-grid">
					{[...Array(6)].map((_, i) => (
						<div key={i} className="wppo-skeleton-card" aria-hidden="true" />
					))}
				</div>
			</div>
		);
	}

	if (error) {
		return (
			<div className="wppo-analytics-error" role="alert">
				<Card title="Analytics Error">
					<p>{error}</p>
					<Button variant="primary" onClick={fetchMetrics}>
						Retry
					</Button>
				</Card>
			</div>
		);
	}

	if (!metrics) {
		return (
			<div className="wppo-analytics-empty">
				<Card title="No Data Available">
					<p>No analytics data is available for the selected time period.</p>
					<Button variant="secondary" onClick={fetchMetrics}>
						Refresh
					</Button>
				</Card>
			</div>
		);
	}

	return (
		<div className="wppo-dashboard-analytics">
			{/* Header Controls */}
			<div className="wppo-analytics-header">
				<h2>Performance Analytics & Insights</h2>
				<div className="wppo-controls">
					<Select 
						value={timeRange}
						onChange={setTimeRange}
						options={[
							{ value: '24h', label: 'Last 24 Hours' },
							{ value: '7d', label: 'Last 7 Days' },
							{ value: '30d', label: 'Last 30 Days' },
							{ value: '90d', label: 'Last 90 Days' }
						]}
					/>
					<Button variant="secondary" onClick={fetchMetrics}>
						Refresh
					</Button>
				</div>
			</div>

			{/* Key Metrics Grid */}
			<div className="wppo-metrics-grid">
				<Card title="Performance Score" className="wppo-score-card">
					<div className="wppo-score-display">
						<div className={`wppo-score-circle ${getScoreColor(metrics.optimization_score)}`}>
							<span className="wppo-score-value">{metrics.optimization_score}</span>
							<span className="wppo-score-label">/ 100</span>
						</div>
						<div className="wppo-score-details">
							<Progress 
								value={metrics.optimization_score} 
								color={getScoreColor(metrics.optimization_score)}
							/>
							<p>Overall optimization score</p>
						</div>
					</div>
				</Card>

				<Card title="Page Load Time">
					<div className="wppo-metric">
						<span className="wppo-metric-value">{metrics.page_load_time.toFixed(2)}s</span>
						<span className="wppo-metric-change">
							{metrics.page_load_time < 3 ? '↓ Good' : metrics.page_load_time < 5 ? '→ Average' : '↑ Needs Work'}
						</span>
						<p>Average load time</p>
					</div>
				</Card>

				<Card title="Cache Hit Rate">
					<div className="wppo-metric">
						<span className="wppo-metric-value">{metrics.cache_hit_rate}%</span>
						<Progress value={metrics.cache_hit_rate} />
						<p>Cache effectiveness</p>
					</div>
				</Card>

				<Card title="Images Optimized">
					<div className="wppo-metric">
						<span className="wppo-metric-value">{metrics.images_optimized}</span>
						<span className="wppo-metric-label">images</span>
						<p>Size saved: {metrics.size_saved}</p>
					</div>
				</Card>
			</div>

			{/* Recommendations Section */}
			<Card title="Performance Recommendations" className="wppo-recommendations-card">
				{metrics.recommendations.length > 0 ? (
					<div className="wppo-recommendations-list">
						{metrics.recommendations.map(rec => (
							<div key={rec.id} className="wppo-recommendation">
								<div className="wppo-recommendation-header">
									<h4>{rec.title}</h4>
									<span className={`wppo-impact-badge ${getImpactColor(rec.impact)}`}>
										{rec.impact} impact
									</span>
								</div>
								<p>{rec.description}</p>
								<Button variant="secondary" size="small">
									Apply Recommendation
								</Button>
							</div>
						))}
					</div>
				) : (
					<div className="wppo-no-recommendations">
						<div className="wppo-success-icon">✓</div>
						<h3>Great job!</h3>
						<p>No critical performance issues found. Your site is well optimized.</p>
					</div>
				)}
			</Card>

			{/* Performance Trends */}
			<div className="wppo-trends-grid">
				<Card title="Cache Performance" className="wppo-trend-card">
					<div className="wppo-trend-stats">
						<div className="wppo-trend-item">
							<span className="wppo-trend-label">Page Cache</span>
							<span className="wppo-trend-value">94%</span>
						</div>
						<div className="wppo-trend-item">
							<span className="wppo-trend-label">Object Cache</span>
							<span className="wppo-trend-value">87%</span>
						</div>
						<div className="wppo-trend-item">
							<span className="wppo-trend-label">Browser Cache</span>
							<span className="wppo-trend-value">98%</span>
						</div>
					</div>
				</Card>

				<Card title="Optimization Impact" className="wppo-trend-card">
					<div className="wppo-impact-stats">
						<div className="wppo-impact-item">
							<span className="wppo-impact-label">CSS Minification</span>
							<span className="wppo-impact-value">-23% size</span>
						</div>
						<div className="wppo-impact-item">
							<span className="wppo-impact-label">JS Minification</span>
							<span className="wppo-impact-value">-31% size</span>
						</div>
						<div className="wppo-impact-item">
							<span className="wppo-impact-label">Image Optimization</span>
							<span className="wppo-impact-value">-45% size</span>
						</div>
					</div>
				</Card>
			</div>

			{/* Quick Actions */}
			<Card title="Quick Actions" className="wppo-actions-card">
				<div className="wppo-quick-actions">
					<Button variant="primary">Clear All Cache</Button>
					<Button variant="secondary">Optimize Images</Button>
					<Button variant="secondary">Run Performance Test</Button>
					<Button variant="secondary">Generate Report</Button>
				</div>
			</Card>
		</div>
	);
};
