/**
 * Metrics Chart Component
 *
 * @package
 * @since 1.1.0
 */

/**
 * External dependencies
 */
import React from 'react';
import { PerformanceMetrics } from '@types/index';
import {
	LineChart,
	Line,
	XAxis,
	YAxis,
	CartesianGrid,
	Tooltip,
	ResponsiveContainer,
} from 'recharts';

interface MetricsChartProps {
	metrics: PerformanceMetrics;
}

export const MetricsChart: React.FC<MetricsChartProps> = ( { metrics } ) => {
	// Convert metrics to chart data format
	const chartData = [
		{
			name: 'Page Load Time',
			value: metrics.page_load_time,
			benchmark: 3.0,
		},
		{
			name: 'FCP',
			value: metrics.first_contentful_paint,
			benchmark: 1.8,
		},
		{
			name: 'LCP',
			value: metrics.largest_contentful_paint,
			benchmark: 2.5,
		},
		{
			name: 'FID',
			value: metrics.first_input_delay,
			benchmark: 0.1,
		},
		{
			name: 'TTI',
			value: metrics.time_to_interactive,
			benchmark: 3.8,
		},
	];

	return (
		<div className="wppo-metrics-chart">
			<ResponsiveContainer width="100%" height={ 300 }>
				<LineChart data={ chartData }>
					<CartesianGrid strokeDasharray="3 3" />
					<XAxis dataKey="name" />
					<YAxis />
					<Tooltip
						formatter={ ( value: number, name: string ) => [
							`${ value.toFixed( 2 ) }s`,
							name === 'value' ? 'Current' : 'Benchmark',
						] }
					/>
					<Line
						type="monotone"
						dataKey="value"
						stroke="#007cba"
						strokeWidth={ 2 }
						name="Current"
					/>
					<Line
						type="monotone"
						dataKey="benchmark"
						stroke="#d63638"
						strokeWidth={ 2 }
						strokeDasharray="5 5"
						name="Benchmark"
					/>
				</LineChart>
			</ResponsiveContainer>

			<div className="wppo-metrics-chart__legend">
				<div className="wppo-metrics-chart__legend-item">
					<span
						className="wppo-metrics-chart__legend-color"
						style={ { backgroundColor: '#007cba' } }
					></span>
					Current Performance
				</div>
				<div className="wppo-metrics-chart__legend-item">
					<span
						className="wppo-metrics-chart__legend-color wppo-metrics-chart__legend-color--dashed"
						style={ { backgroundColor: '#d63638' } }
					></span>
					Industry Benchmark
				</div>
			</div>
		</div>
	);
};
