import React, { useState, useEffect } from 'react';
import { Card, CardBody, CardHeader, Button, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';


interface DashboardStats {
    cache_hit_rate: number;
    page_load_time: number;
    optimized_images: number;
    cache_size: string;
    performance_score: number;
}

interface CacheStats {
    l1_hits: number;
    l2_hits: number;
    l3_hits: number;
    misses: number;
    hit_rate: number;
    memory_items: number;
}

const Dashboard: React.FC = () => {
    const [stats, setStats] = useState<DashboardStats | null>(null);
    const [cacheStats, setCacheStats] = useState<CacheStats | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        fetchDashboardData();
    }, []);

    const fetchDashboardData = async () => {
        try {
            setLoading(true);
            const [dashboardData, cacheData] = await Promise.all([
                apiFetch({ path: '/performance-optimisation/v1/analytics/dashboard' }),
                apiFetch({ path: '/performance-optimisation/v1/cache/stats' })
            ]);
            
            setStats(dashboardData as DashboardStats);
            setCacheStats(cacheData as CacheStats);
            setError(null);
        } catch (err) {
            setError(__('Failed to load dashboard data', 'performance-optimisation'));
        } finally {
            setLoading(false);
        }
    };

    const clearAllCache = async () => {
        try {
            await apiFetch({
                path: '/performance-optimisation/v1/cache/clear',
                method: 'POST'
            });
            fetchDashboardData();
        } catch (err) {
            setError(__('Failed to clear cache', 'performance-optimisation'));
        }
    };

    const getPerformanceColor = (score: number) => {
        if (score >= 90) return 'success';
        if (score >= 70) return 'warning';
        return 'error';
    };

    if (loading) {
        return (
            <div className="wppo-dashboard-loading">
                <Spinner />
                <p>{__('Loading dashboard...', 'performance-optimisation')}</p>
            </div>
        );
    }

    return (
        <div className="wppo-dashboard">
            {error && (
                <Notice status="error" isDismissible onRemove={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <div className="wppo-dashboard-header">
                <h1>{__('Performance Dashboard', 'performance-optimisation')}</h1>
                <Button variant="primary" onClick={clearAllCache}>
                    {__('Clear All Cache', 'performance-optimisation')}
                </Button>
            </div>

            <div className="wppo-dashboard-grid">
                <Card className="wppo-stat-card">
                    <CardHeader>
                        <h3>{__('Performance Score', 'performance-optimisation')}</h3>
                    </CardHeader>
                    <CardBody>
                        <div className={`wppo-score wppo-score-${getPerformanceColor(stats?.performance_score || 0)}`}>
                            <span className="wppo-score-value">{stats?.performance_score || 0}</span>
                            <span className="wppo-score-label">/100</span>
                        </div>
                    </CardBody>
                </Card>

                <Card className="wppo-stat-card">
                    <CardHeader>
                        <h3>{__('Cache Hit Rate', 'performance-optimisation')}</h3>
                    </CardHeader>
                    <CardBody>
                        <div className="wppo-metric">
                            <span className="wppo-metric-value">{stats?.cache_hit_rate || 0}%</span>
                            <div className="wppo-progress-bar">
                                <div 
                                    className="wppo-progress-fill"
                                    style={{ width: `${stats?.cache_hit_rate || 0}%` }}
                                />
                            </div>
                        </div>
                    </CardBody>
                </Card>

                <Card className="wppo-stat-card">
                    <CardHeader>
                        <h3>{__('Page Load Time', 'performance-optimisation')}</h3>
                    </CardHeader>
                    <CardBody>
                        <div className="wppo-metric">
                            <span className="wppo-metric-value">{stats?.page_load_time || 0}s</span>
                            <span className="wppo-metric-label">{__('Average', 'performance-optimisation')}</span>
                        </div>
                    </CardBody>
                </Card>

                <Card className="wppo-stat-card">
                    <CardHeader>
                        <h3>{__('Optimized Images', 'performance-optimisation')}</h3>
                    </CardHeader>
                    <CardBody>
                        <div className="wppo-metric">
                            <span className="wppo-metric-value">{stats?.optimized_images || 0}</span>
                            <span className="wppo-metric-label">{__('Images', 'performance-optimisation')}</span>
                        </div>
                    </CardBody>
                </Card>
            </div>

            {cacheStats && (
                <Card className="wppo-cache-details">
                    <CardHeader>
                        <h3>{__('Cache Layer Performance', 'performance-optimisation')}</h3>
                    </CardHeader>
                    <CardBody>
                        <div className="wppo-cache-layers">
                            <div className="wppo-cache-layer">
                                <span className="wppo-layer-name">L1 (Memory)</span>
                                <span className="wppo-layer-hits">{cacheStats.l1_hits} hits</span>
                            </div>
                            <div className="wppo-cache-layer">
                                <span className="wppo-layer-name">L2 (Redis/Memcached)</span>
                                <span className="wppo-layer-hits">{cacheStats.l2_hits} hits</span>
                            </div>
                            <div className="wppo-cache-layer">
                                <span className="wppo-layer-name">L3 (File)</span>
                                <span className="wppo-layer-hits">{cacheStats.l3_hits} hits</span>
                            </div>
                            <div className="wppo-cache-layer wppo-cache-misses">
                                <span className="wppo-layer-name">Misses</span>
                                <span className="wppo-layer-hits">{cacheStats.misses}</span>
                            </div>
                        </div>
                    </CardBody>
                </Card>
            )}
        </div>
    );
};

export default Dashboard;
