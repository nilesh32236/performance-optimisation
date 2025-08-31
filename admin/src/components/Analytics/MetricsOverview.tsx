/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import { Card } from '../Card';

interface MetricsOverviewProps {
	overview: {
		performance_score: number;
		average_load_time: number;
		cache_hit_ratio: number;
		total_page_views: number;
		optimization_status: Record<string, boolean>;
	};
}

const MetricsOverview: React.FC<MetricsOverviewProps> = ( { overview } ) => {
	const getScoreColor = ( score: number ): string => {
		if ( score >= 90 ) {
			return 'excellent';
		}
		if ( score >= 70 ) {
			return 'good';
		}
		if ( score >= 50 ) {
			return 'fair';
		}
		return 'poor';
	};

	const getLoadTimeColor = ( time: number ): string => {
		if ( time <= 1000 ) {
			return 'excellent';
		}
		if ( time <= 2000 ) {
			return 'good';
		}
		if ( time <= 3000 ) {
			return 'fair';
		}
		return 'poor';
	};

	const getCacheColor = ( ratio: number ): string => {
		if ( ratio >= 80 ) {
			return 'excellent';
		}
		if ( ratio >= 60 ) {
			return 'good';
		}
		if ( ratio >= 40 ) {
			return 'fair';
		}
		return 'poor';
	};

	return (
		<div className="wppo-metrics-overview">
			<h3>Performance Overview</h3>
			<div className="wppo-metrics-overview__grid">
				<Card className="wppo-metric-card">
					<div className="wppo-metric-card__content">
						<div className="wppo-metric-card__icon">
							<span className="dashicons dashicons-performance"></span>
						</div>
						<div className="wppo-metric-card__details">
							<div
								className={ `wppo-metric-card__value wppo-metric-card__value--${ getScoreColor( overview.performance_score ) }` }
							>
								{ overview.performance_score }
							</div>
							<div className="wppo-metric-card__label">Performance Score</div>
							<div className="wppo-metric-card__description">
								Overall performance rating
							</div>
						</div>
					</div>
				</Card>

				<Card className="wppo-metric-card">
					<div className="wppo-metric-card__content">
						<div className="wppo-metric-card__icon">
							<span className="dashicons dashicons-clock"></span>
						</div>
						<div className="wppo-metric-card__details">
							<div
								className={ `wppo-metric-card__value wppo-metric-card__value--${ getLoadTimeColor( overview.average_load_time ) }` }
							>
								{ ( overview.average_load_time / 1000 ).toFixed( 2 ) }s
							</div>
							<div className="wppo-metric-card__label">Average Load Time</div>
							<div className="wppo-metric-card__description">
								Time to fully load pages
							</div>
						</div>
					</div>
				</Card>

				<Card className="wppo-metric-card">
					<div className="wppo-metric-card__content">
						<div className="wppo-metric-card__icon">
							<span className="dashicons dashicons-database-view"></span>
						</div>
						<div className="wppo-metric-card__details">
							<div
								className={ `wppo-metric-card__value wppo-metric-card__value--${ getCacheColor( overview.cache_hit_ratio ) }` }
							>
								{ overview.cache_hit_ratio.toFixed( 1 ) }%
							</div>
							<div className="wppo-metric-card__label">Cache Hit Ratio</div>
							<div className="wppo-metric-card__description">
								Percentage of cached requests
							</div>
						</div>
					</div>
				</Card>

				<Card className="wppo-metric-card">
					<div className="wppo-metric-card__content">
						<div className="wppo-metric-card__icon">
							<span className="dashicons dashicons-visibility"></span>
						</div>
						<div className="wppo-metric-card__details">
							<div className="wppo-metric-card__value">
								{ overview.total_page_views.toLocaleString() }
							</div>
							<div className="wppo-metric-card__label">Total Page Views</div>
							<div className="wppo-metric-card__description">
								Pages served in period
							</div>
						</div>
					</div>
				</Card>
			</div>
		</div>
	);
};

export default MetricsOverview;
