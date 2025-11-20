/**
 * Metrics Overview Component - Refactored
 */

/**
 * External dependencies
 */
import React from 'react';
import { Card, CardHeader, CardBody } from '@wordpress/components';

/**
 * Internal dependencies
 */
import './MetricsOverview.scss';

interface MetricsOverviewProps {
    config: {
        overview?: {
            performance_score: number;
            average_load_time: number;
            cache_hit_ratio: number;
        };
    };
}

const MetricsOverview: React.FC<MetricsOverviewProps> = ( { config } ) => {
    const overview = config.overview || {
        performance_score: 0,
        average_load_time: 0,
        cache_hit_ratio: 0,
    };

    const getScoreColor = ( score: number ): string => {
        if ( score >= 85 ) return 'var(--wp-admin-color-success)';
        if ( score >= 60 ) return 'var(--wp-admin-color-warning)';
        return 'var(--wp-admin-color-error)';
    };

    const getLoadTimeColor = ( time: number ): string => {
        if ( time <= 1.5 ) return 'var(--wp-admin-color-success)';
        if ( time <= 3.0 ) return 'var(--wp-admin-color-warning)';
        return 'var(--wp-admin-color-error)';
    };

    const getCacheColor = ( ratio: number ): string => {
        if ( ratio >= 90 ) return 'var(--wp-admin-color-success)';
        if ( ratio >= 70 ) return 'var(--wp-admin-color-warning)';
        return 'var(--wp-admin-color-error)';
    };

    return (
        <div className="wppo-metrics-overview">
            <Card>
                <CardHeader>Overall Performance Score</CardHeader>
                <CardBody style={{ color: getScoreColor(overview.performance_score) }}>
                    {overview.performance_score}/100
                </CardBody>
            </Card>

            <Card>
                <CardHeader>Average Load Time</CardHeader>
                <CardBody style={{ color: getLoadTimeColor(overview.average_load_time) }}>
                    {overview.average_load_time.toFixed(2)}s
                </CardBody>
            </Card>

            <Card>
                <CardHeader>Cache Hit Ratio</CardHeader>
                <CardBody style={{ color: getCacheColor(overview.cache_hit_ratio) }}>
                    {overview.cache_hit_ratio.toFixed(1)}%
                </CardBody>
            </Card>
        </div>
    );
};

export default MetricsOverview;