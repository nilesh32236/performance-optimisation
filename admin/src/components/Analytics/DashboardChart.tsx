/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import InteractiveChart from './InteractiveChart';

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

interface DashboardChartProps {
	title: string;
	metric: string;
	data: ChartData;
	type?: 'line' | 'area' | 'bar';
	height?: number;
	className?: string;
}

const DashboardChart: React.FC<DashboardChartProps> = ( {
	title,
	metric,
	data,
	type = 'area',
	height = 280,
	className = '',
} ) => {
	// Determine chart type based on metric if not specified
	const getOptimalChartType = ( metricName: string ): 'line' | 'area' | 'bar' => {
		switch ( metricName ) {
			case 'page_load_time':
				return 'area'; // Good for showing performance trends over time
			case 'cache_hit_ratio':
				return 'line'; // Good for showing percentage changes
			case 'memory_usage':
				return 'area'; // Good for showing resource usage
			case 'database_queries':
				return 'bar'; // Good for showing discrete counts
			default:
				return 'area';
		}
	};

	const chartType = type || getOptimalChartType( metric );

	// Add trend indicators
	const getTrendIndicator = ( chartData: ChartData ) => {
		if ( chartData.daily_trends.length < 2 ) {
			return null;
		}

		const firstValue = chartData.daily_trends[ 0 ].value;
		const lastValue = chartData.daily_trends[ chartData.daily_trends.length - 1 ].value;
		const changeValue = ( ( lastValue - firstValue ) / firstValue ) * 100;

		const isImprovement = ( metricName: string, change: number ) => {
			// For some metrics, lower is better (page_load_time, memory_usage)
			// For others, higher is better (cache_hit_ratio, optimization_score)
			const lowerIsBetter = [ 'page_load_time', 'memory_usage', 'database_queries' ];
			return lowerIsBetter.includes( metricName ) ? change < 0 : change > 0;
		};

		const improvement = isImprovement( chartData.metric_name, changeValue );
		if ( Math.abs( changeValue ) < 1 ) {
			return null;
		} // No significant change

		return (
			<div
				className={ `wppo-trend-indicator wppo-trend-indicator--${ improvement ? 'positive' : 'negative' }` }
			>
				<span
					className={ `dashicons ${ improvement ? 'dashicons-arrow-up-alt' : 'dashicons-arrow-down-alt' }` }
				></span>
				<span className="wppo-trend-value">{ Math.abs( changeValue ).toFixed( 1 ) }%</span>
				<span className="wppo-trend-label">{ improvement ? 'improved' : 'declined' }</span>
			</div>
		);
	};

	return (
		<div className={ `wppo-dashboard-chart ${ className }` }>
			<div className="wppo-dashboard-chart__header">
				<div className="wppo-dashboard-chart__title-section">
					<h4 className="wppo-dashboard-chart__title">{ title }</h4>
					{ getTrendIndicator( data ) }
				</div>
				<div className="wppo-dashboard-chart__summary">
					<div className="wppo-chart-summary-item">
						<span className="wppo-chart-summary-label">Current Avg:</span>
						<span className="wppo-chart-summary-value">
							{ formatMetricValue( data.average, data.metric_name ) }
						</span>
					</div>
				</div>
			</div>

			<InteractiveChart
				title=""
				data={ data }
				type={ chartType }
				height={ height }
				showLegend={ false }
				showGrid={ true }
				className="wppo-dashboard-chart__chart"
			/>
		</div>
	);
};

// Helper function
const formatMetricValue = ( value: number, metricName: string ): string => {
	switch ( metricName ) {
		case 'page_load_time':
			return `${ ( value / 1000 ).toFixed( 2 ) }s`;
		case 'cache_hit_ratio':
			return `${ value.toFixed( 1 ) }%`;
		case 'memory_usage':
			return `${ ( value / 1024 / 1024 ).toFixed( 1 ) }MB`;
		case 'database_queries':
			return value.toFixed( 0 );
		case 'optimization_score':
			return `${ value.toFixed( 0 ) }/100`;
		default:
			return value.toFixed( 2 );
	}
};

export default DashboardChart;
