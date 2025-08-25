import React, { useState, useEffect } from 'react';

interface ChartDataPoint {
	date: string;
	value: number;
	sample_count?: number;
}

interface ChartData {
	daily_trends: ChartDataPoint[];
	average: number;
	metric_name: string;
}

interface PerformanceChartProps {
	title: string;
	metric: string;
	period: 'day' | 'week' | 'month';
	height?: number;
	showLegend?: boolean;
	className?: string;
	data?: ChartData; // Optional pre-loaded data
}

function PerformanceChart({ 
	title, 
	metric, 
	period, 
	height = 300, 
	showLegend = true,
	className = '',
	data: preloadedData
}: PerformanceChartProps) {
	const [chartData, setChartData] = useState<ChartData | null>(preloadedData || null);
	const [isLoading, setIsLoading] = useState(!preloadedData);
	const [error, setError] = useState<string | null>(null);

	useEffect(() => {
		if (preloadedData) {
			setChartData(preloadedData);
			setIsLoading(false);
		} else {
			fetchChartData();
		}
	}, [metric, period, preloadedData]);

	const fetchChartData = async () => {
		setIsLoading(true);
		setError(null);

		try {
			const endDate = new Date();
			const startDate = new Date();
			
			switch (period) {
				case 'day':
					startDate.setDate(endDate.getDate() - 7); // Last 7 days
					break;
				case 'week':
					startDate.setDate(endDate.getDate() - 30); // Last 30 days
					break;
				case 'month':
					startDate.setMonth(endDate.getMonth() - 3); // Last 3 months
					break;
			}

			const response = await fetch(
				`${(window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1'}/analytics/metrics?` +
				`metric=${metric}&` +
				`period=${period}&` +
				`start_date=${startDate.toISOString().split('T')[0]}&` +
				`end_date=${endDate.toISOString().split('T')[0]}`,
				{
					headers: {
						'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '',
					},
				}
			);

			if (!response.ok) {
				throw new Error('Failed to fetch chart data');
			}

			const result = await response.json();
			if (!result.success) {
				throw new Error(result.message || 'Failed to load chart data');
			}

			setChartData(result.data);
		} catch (err) {
			setError(err instanceof Error ? err.message : 'An error occurred');
		} finally {
			setIsLoading(false);
		}
	};

	const getMetricLabel = (metricName: string): string => {
		const labels: Record<string, string> = {
			'page_load_time': 'Page Load Time (ms)',
			'cache_hit': 'Cache Hit Ratio (%)',
			'memory_usage': 'Memory Usage (MB)',
			'database_queries': 'Database Queries',
			'optimization_score': 'Optimization Score',
		};
		return labels[metricName] || metricName;
	};

	const getMetricColor = (metricName: string, alpha = 1): string => {
		const colors: Record<string, string> = {
			'page_load_time': `rgba(220, 53, 69, ${alpha})`,
			'cache_hit': `rgba(40, 167, 69, ${alpha})`,
			'memory_usage': `rgba(255, 193, 7, ${alpha})`,
			'database_queries': `rgba(0, 123, 255, ${alpha})`,
			'optimization_score': `rgba(108, 117, 125, ${alpha})`,
		};
		return colors[metricName] || `rgba(108, 117, 125, ${alpha})`;
	};

	if (isLoading) {
		return (
			<div className={`wppo-chart-container ${className}`}>
				<div className="wppo-chart-header">
					<h3>{title}</h3>
				</div>
				<div className="wppo-chart-loading" style={{ height }}>
					<div className="wppo-loading-spinner">
						<div className="wppo-spinner"></div>
					</div>
					<p>Loading chart data...</p>
				</div>
			</div>
		);
	}

	if (error) {
		return (
			<div className={`wppo-chart-container ${className}`}>
				<div className="wppo-chart-header">
					<h3>{title}</h3>
				</div>
				<div className="wppo-chart-error" style={{ height }}>
					<div className="wppo-error-icon">
						<span className="dashicons dashicons-warning"></span>
					</div>
					<p>{error}</p>
					<button 
						type="button"
						className="wppo-button wppo-button--secondary"
						onClick={fetchChartData}
					>
						Retry
					</button>
				</div>
			</div>
		);
	}

	if (!chartData || chartData.daily_trends.length === 0) {
		return (
			<div className={`wppo-chart-container ${className}`}>
				<div className="wppo-chart-header">
					<h3>{title}</h3>
				</div>
				<div className="wppo-chart-empty" style={{ height }}>
					<div className="wppo-empty-icon">
						<span className="dashicons dashicons-chart-line"></span>
					</div>
					<p>No data available for the selected period.</p>
				</div>
			</div>
		);
	}

	return (
		<div className={`wppo-chart-container ${className}`}>
			<div className="wppo-chart-header">
				<div className="wppo-chart-title">
					<h4>{title}</h4>
					<div className="wppo-chart-average">
						Avg: {formatMetricValue(chartData.average, metric)}
					</div>
				</div>
			</div>
			
			<div className="wppo-chart-content" style={{ height }}>
				<svg 
					className="wppo-chart-svg" 
					width="100%" 
					height="100%" 
					viewBox="0 0 800 300"
					preserveAspectRatio="xMidYMid meet"
				>
					{renderSimpleLineChart(chartData, 800, 300)}
				</svg>
				
				{showLegend && (
					<div className="wppo-chart-legend">
						<div className="wppo-legend-item">
							<div 
								className="wppo-legend-color"
								style={{ backgroundColor: getMetricColor(metric) }}
							></div>
							<span className="wppo-legend-label">{getMetricLabel(metric)}</span>
						</div>
					</div>
				)}
			</div>
		</div>
	);
}

const formatMetricValue = (value: number, metricName: string): string => {
	switch (metricName) {
		case 'page_load_time':
			return `${(value / 1000).toFixed(2)}s`;
		case 'cache_hit_ratio':
			return `${value.toFixed(1)}%`;
		case 'memory_usage':
			return `${(value / 1024 / 1024).toFixed(1)}MB`;
		default:
			return value.toFixed(2);
	}
};

// Simple SVG line chart renderer
function renderSimpleLineChart(data: ChartData, width: number, height: number) {
	if (!data.daily_trends || data.daily_trends.length === 0) {
		return null;
	}

	const values = data.daily_trends.map(trend => trend.value);
	const labels = data.daily_trends.map(trend => new Date(trend.date).toLocaleDateString());
	
	const padding = 40;
	const chartWidth = width - (padding * 2);
	const chartHeight = height - (padding * 2);
	
	const minValue = Math.min(...values);
	const maxValue = Math.max(...values);
	const valueRange = maxValue - minValue || 1;
	
	// Generate path for line
	const pathData = values.map((value, index) => {
		const x = padding + (index / (values.length - 1)) * chartWidth;
		const y = padding + chartHeight - ((value - minValue) / valueRange) * chartHeight;
		return `${index === 0 ? 'M' : 'L'} ${x} ${y}`;
	}).join(' ');

	return (
		<g>
			{/* Grid lines */}
			{Array.from({ length: 5 }, (_, i) => {
				const y = padding + (i / 4) * chartHeight;
				return (
					<line
						key={`grid-${i}`}
						x1={padding}
						y1={y}
						x2={width - padding}
						y2={y}
						stroke="#e0e0e0"
						strokeWidth="1"
					/>
				);
			})}
			
			{/* Chart line */}
			<path
				d={pathData}
				fill="none"
				stroke={getMetricColor(data.metric_name)}
				strokeWidth="2"
			/>
			
			{/* Data points */}
			{values.map((value, index) => {
				const x = padding + (index / (values.length - 1)) * chartWidth;
				const y = padding + chartHeight - ((value - minValue) / valueRange) * chartHeight;
				return (
					<circle
						key={`point-${index}`}
						cx={x}
						cy={y}
						r="4"
						fill={getMetricColor(data.metric_name)}
					/>
				);
			})}
			
			{/* Y-axis labels */}
			{Array.from({ length: 5 }, (_, i) => {
				const value = minValue + (i / 4) * valueRange;
				const y = padding + chartHeight - (i / 4) * chartHeight;
				return (
					<text
						key={`y-label-${i}`}
						x={padding - 10}
						y={y + 4}
						textAnchor="end"
						fontSize="12"
						fill="#666"
					>
						{Math.round(value)}
					</text>
				);
			})}
			
			{/* X-axis labels */}
			{labels.map((label, index) => {
				if (index % Math.ceil(labels.length / 6) === 0) { // Show every nth label
					const x = padding + (index / (values.length - 1)) * chartWidth;
					return (
						<text
							key={`x-label-${index}`}
							x={x}
							y={height - 10}
							textAnchor="middle"
							fontSize="12"
							fill="#666"
						>
							{label}
						</text>
					);
				}
				return null;
			})}
		</g>
	);
}

export default PerformanceChart;