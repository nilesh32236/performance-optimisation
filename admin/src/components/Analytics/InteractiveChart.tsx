import React from 'react';
import {
	LineChart,
	Line,
	AreaChart,
	Area,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	CartesianGrid,
	Tooltip,
	Legend,
	ResponsiveContainer,
	PieChart,
	Pie,
	Cell
} from 'recharts';

interface ChartDataPoint {
	date: string;
	value: number;
	sample_count?: number;
	hits?: number;
	total?: number;
}

interface ChartData {
	daily_trends: ChartDataPoint[];
	average: number;
	metric_name: string;
}

interface InteractiveChartProps {
	title: string;
	data: ChartData;
	type?: 'line' | 'area' | 'bar' | 'pie';
	height?: number;
	showGrid?: boolean;
	showLegend?: boolean;
	className?: string;
}

const InteractiveChart: React.FC<InteractiveChartProps> = ({
	title,
	data,
	type = 'area',
	height = 300,
	showGrid = true,
	showLegend = true,
	className = ''
}) => {
	// Format data for Recharts
	const chartData = data.daily_trends.map(point => ({
		...point,
		date: new Date(point.date).toLocaleDateString('en-US', { 
			month: 'short', 
			day: 'numeric' 
		}),
		formattedValue: formatMetricValue(point.value, data.metric_name)
	}));

	// Get colors based on metric type
	const getMetricColors = (metricName: string) => {
		const colorSchemes = {
			page_load_time: {
				primary: '#dc3545',
				gradient: ['#dc3545', '#ff6b7a'],
				background: 'rgba(220, 53, 69, 0.1)'
			},
			cache_hit_ratio: {
				primary: '#28a745',
				gradient: ['#28a745', '#5cb85c'],
				background: 'rgba(40, 167, 69, 0.1)'
			},
			memory_usage: {
				primary: '#ffc107',
				gradient: ['#ffc107', '#ffdb4d'],
				background: 'rgba(255, 193, 7, 0.1)'
			},
			database_queries: {
				primary: '#007bff',
				gradient: ['#007bff', '#4dabf7'],
				background: 'rgba(0, 123, 255, 0.1)'
			},
			optimization_score: {
				primary: '#6c757d',
				gradient: ['#6c757d', '#9ca3af'],
				background: 'rgba(108, 117, 125, 0.1)'
			}
		};
		return colorSchemes[metricName as keyof typeof colorSchemes] || colorSchemes.optimization_score;
	};

	const colors = getMetricColors(data.metric_name);

	// Custom tooltip
	const CustomTooltip = ({ active, payload, label }: any) => {
		if (active && payload && payload.length) {
			const data = payload[0].payload;
			return (
				<div className="wppo-chart-tooltip">
					<div className="wppo-chart-tooltip__header">
						<strong>{label}</strong>
					</div>
					<div className="wppo-chart-tooltip__content">
						<div className="wppo-chart-tooltip__item">
							<span className="wppo-chart-tooltip__label">
								{getMetricLabel(data.metric_name || 'value')}:
							</span>
							<span className="wppo-chart-tooltip__value">
								{data.formattedValue}
							</span>
						</div>
						{data.sample_count && (
							<div className="wppo-chart-tooltip__item">
								<span className="wppo-chart-tooltip__label">Samples:</span>
								<span className="wppo-chart-tooltip__value">{data.sample_count}</span>
							</div>
						)}
						{data.hits !== undefined && data.total !== undefined && (
							<div className="wppo-chart-tooltip__item">
								<span className="wppo-chart-tooltip__label">Hits/Total:</span>
								<span className="wppo-chart-tooltip__value">{data.hits}/{data.total}</span>
							</div>
						)}
					</div>
				</div>
			);
		}
		return null;
	};

	// Render different chart types
	const renderChart = () => {
		const commonProps = {
			data: chartData,
			margin: { top: 20, right: 30, left: 20, bottom: 5 }
		};

		switch (type) {
			case 'line':
				return (
					<LineChart {...commonProps}>
						{showGrid && <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />}
						<XAxis 
							dataKey="date" 
							tick={{ fontSize: 12, fill: '#666' }}
							axisLine={{ stroke: '#e0e0e0' }}
						/>
						<YAxis 
							tick={{ fontSize: 12, fill: '#666' }}
							axisLine={{ stroke: '#e0e0e0' }}
						/>
						<Tooltip content={<CustomTooltip />} />
						{showLegend && <Legend />}
						<Line
							type="monotone"
							dataKey="value"
							stroke={colors.primary}
							strokeWidth={3}
							dot={{ fill: colors.primary, strokeWidth: 2, r: 4 }}
							activeDot={{ r: 6, stroke: colors.primary, strokeWidth: 2 }}
							name={getMetricLabel(data.metric_name)}
						/>
					</LineChart>
				);

			case 'area':
				return (
					<AreaChart {...commonProps}>
						{showGrid && <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />}
						<XAxis 
							dataKey="date" 
							tick={{ fontSize: 12, fill: '#666' }}
							axisLine={{ stroke: '#e0e0e0' }}
						/>
						<YAxis 
							tick={{ fontSize: 12, fill: '#666' }}
							axisLine={{ stroke: '#e0e0e0' }}
						/>
						<Tooltip content={<CustomTooltip />} />
						{showLegend && <Legend />}
						<defs>
							<linearGradient id={`gradient-${data.metric_name}`} x1="0" y1="0" x2="0" y2="1">
								<stop offset="5%" stopColor={colors.primary} stopOpacity={0.8}/>
								<stop offset="95%" stopColor={colors.primary} stopOpacity={0.1}/>
							</linearGradient>
						</defs>
						<Area
							type="monotone"
							dataKey="value"
							stroke={colors.primary}
							strokeWidth={2}
							fill={`url(#gradient-${data.metric_name})`}
							dot={{ fill: colors.primary, strokeWidth: 2, r: 3 }}
							activeDot={{ r: 5, stroke: colors.primary, strokeWidth: 2 }}
							name={getMetricLabel(data.metric_name)}
						/>
					</AreaChart>
				);

			case 'bar':
				return (
					<BarChart {...commonProps}>
						{showGrid && <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />}
						<XAxis 
							dataKey="date" 
							tick={{ fontSize: 12, fill: '#666' }}
							axisLine={{ stroke: '#e0e0e0' }}
						/>
						<YAxis 
							tick={{ fontSize: 12, fill: '#666' }}
							axisLine={{ stroke: '#e0e0e0' }}
						/>
						<Tooltip content={<CustomTooltip />} />
						{showLegend && <Legend />}
						<Bar
							dataKey="value"
							fill={colors.primary}
							radius={[4, 4, 0, 0]}
							name={getMetricLabel(data.metric_name)}
						/>
					</BarChart>
				);

			default:
				return renderChart();
		}
	};

	if (!data.daily_trends || data.daily_trends.length === 0) {
		return (
			<div className={`wppo-interactive-chart ${className}`}>
				<div className="wppo-chart-header">
					<h4>{title}</h4>
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
		<div className={`wppo-interactive-chart ${className}`}>
			<div className="wppo-chart-header">
				<div className="wppo-chart-title">
					<h4>{title}</h4>
					<div className="wppo-chart-stats">
						<div className="wppo-chart-stat">
							<span className="wppo-chart-stat__label">Average:</span>
							<span className="wppo-chart-stat__value">
								{formatMetricValue(data.average, data.metric_name)}
							</span>
						</div>
						<div className="wppo-chart-stat">
							<span className="wppo-chart-stat__label">Data Points:</span>
							<span className="wppo-chart-stat__value">
								{data.daily_trends.length}
							</span>
						</div>
					</div>
				</div>
			</div>
			
			<div className="wppo-chart-content" style={{ height }}>
				<ResponsiveContainer width="100%" height="100%">
					{renderChart()}
				</ResponsiveContainer>
			</div>
		</div>
	);
};

// Helper functions
const formatMetricValue = (value: number, metricName: string): string => {
	switch (metricName) {
		case 'page_load_time':
			return `${(value / 1000).toFixed(2)}s`;
		case 'cache_hit_ratio':
			return `${value.toFixed(1)}%`;
		case 'memory_usage':
			return `${(value / 1024 / 1024).toFixed(1)}MB`;
		case 'database_queries':
			return value.toFixed(0);
		case 'optimization_score':
			return `${value.toFixed(0)}/100`;
		default:
			return value.toFixed(2);
	}
};

const getMetricLabel = (metricName: string): string => {
	const labels: Record<string, string> = {
		page_load_time: 'Page Load Time',
		cache_hit_ratio: 'Cache Hit Ratio',
		memory_usage: 'Memory Usage',
		database_queries: 'Database Queries',
		optimization_score: 'Optimization Score',
	};
	return labels[metricName] || metricName;
};

export default InteractiveChart;