/**
 * Metrics Overview Component
 *
 * @package PerformanceOptimisation
 * @since 2.0.0
 */

import React from 'react';
import { Card } from '../UI';
import { Dashicon } from '@wordpress/components';

interface MetricsOverviewProps {
    config: {
        overview?: {
            performance_score: number;
            average_load_time: number;
            cache_hit_ratio: number;
        };
    };
}

const MetricsOverview: React.FC<MetricsOverviewProps> = ({ config }) => {
    const metrics = [
        {
            label: 'Performance Score',
            value: config?.overview?.performance_score || 0,
            unit: '/100',
            icon: 'performance',
            color: 'text-blue-600',
            bg: 'bg-blue-50'
        },
        {
            label: 'Load Time',
            value: config?.overview?.average_load_time || 0,
            unit: 's',
            icon: 'clock',
            color: 'text-green-600',
            bg: 'bg-green-50'
        },
        {
            label: 'Cache Hit Ratio',
            value: config?.overview?.cache_hit_ratio || 0,
            unit: '%',
            icon: 'saved',
            color: 'text-purple-600',
            bg: 'bg-purple-50'
        }
    ];

    return (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            {metrics.map((metric, index) => (
                <Card key={index} className="transform transition-all hover:scale-[1.02]">
                    <div className="flex items-center justify-between">
                        <div>
                            <div className="text-sm text-gray-500 font-medium uppercase tracking-wider mb-1">
                                {metric.label}
                            </div>
                            <div className="flex items-baseline gap-1">
                                <span className={`text-3xl font-bold ${metric.color}`}>
                                    {metric.value}
                                </span>
                                <span className="text-gray-400 font-medium">{metric.unit}</span>
                            </div>
                        </div>
                        <div className={`p-3 rounded-full ${metric.bg} ${metric.color}`}>
                            <Dashicon icon={metric.icon as any} size={24} />
                        </div>
                    </div>
                </Card>
            ))}
        </div>
    );
};

export default MetricsOverview;