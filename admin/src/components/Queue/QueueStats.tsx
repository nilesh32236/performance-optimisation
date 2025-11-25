/**
 * Queue Statistics Component
 *
 * Displays conversion queue statistics and management controls
 *
 * @package PerformanceOptimisation
 * @since 2.0.0
 */

import React, { useState, useEffect } from 'react';
import { Dashicon } from '@wordpress/components';
import { Card, Button } from '../index';

interface QueueStats {
    stats: {
        [format: string]: {
            pending?: number;
            completed?: number;
            failed?: number;
        };
    };
    totals: {
        total_pending: number;
        total_completed: number;
        total_failed: number;
    };
}

export const QueueStats: React.FC = () => {
    const [stats, setStats] = useState<QueueStats | null>(null);
    const [loading, setLoading] = useState(true);
    const [processing, setProcessing] = useState(false);
    const [clearing, setClearing] = useState(false);

    const fetchStats = async () => {
        try {
            const response = await fetch(
                `${window.wppoAdmin?.apiUrl}/queue/stats`,
                {
                    headers: {
                        'X-WP-Nonce': window.wppoAdmin?.nonce || '',
                    },
                }
            );

            if (response.ok) {
                const data = await response.json();
                setStats(data);
            }
        } catch (error) {
            console.error('Failed to load queue stats:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleProcessQueue = async () => {
        setProcessing(true);
        try {
            const response = await fetch(
                `${window.wppoAdmin?.apiUrl}/queue/process`,
                {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': window.wppoAdmin?.nonce || '',
                    },
                }
            );

            if (response.ok) {
                // Refresh stats after processing
                setTimeout(() => {
                    fetchStats();
                }, 2000);
            }
        } catch (error) {
            console.error('Failed to process queue:', error);
        } finally {
            setProcessing(false);
        }
    };

    const handleClearCompleted = async () => {
        setClearing(true);
        try {
            const response = await fetch(
                `${window.wppoAdmin?.apiUrl}/queue/clear-completed`,
                {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': window.wppoAdmin?.nonce || '',
                    },
                }
            );

            if (response.ok) {
                // Refresh stats
                fetchStats();
            }
        } catch (error) {
            console.error('Failed to clear completed:', error);
        } finally {
            setClearing(false);
        }
    };

    useEffect(() => {
        fetchStats();

        // Auto-refresh every 30 seconds
        const interval = setInterval(fetchStats, 30000);
        return () => clearInterval(interval);
    }, []);

    if (loading || !stats) {
        return (
            <Card title="Image Conversion Queue">
                <div className="flex items-center justify-center p-8 text-slate-500">
                    <span className="animate-spin mr-2">⟳</span> Loading queue stats...
                </div>
            </Card>
        );
    }

    return (
        <Card title="Image Conversion Queue">
            <div className="space-y-8">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {/* Pending Card */}
                    <div className="relative overflow-hidden bg-white rounded-xl border border-slate-200 p-6 hover:shadow-md transition-shadow duration-300 group">
                        <div className="flex items-start justify-between">
                            <div>
                                <p className="text-sm font-medium text-slate-500 mb-1">Pending</p>
                                <h4 className="text-3xl font-bold text-slate-900">{stats.totals.total_pending}</h4>
                            </div>
                            <div className="w-12 h-12 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                {/* @ts-ignore */}
                                <Dashicon icon="clock" size={24} />
                            </div>
                        </div>
                        <div className="mt-4 h-1 w-full bg-slate-100 rounded-full overflow-hidden">
                            <div className="h-full bg-blue-500 rounded-full" style={{ width: `${stats.totals.total_pending > 0 ? '100%' : '0%'}` }}></div>
                        </div>
                    </div>

                    {/* Completed Card */}
                    <div className="relative overflow-hidden bg-white rounded-xl border border-slate-200 p-6 hover:shadow-md transition-shadow duration-300 group">
                        <div className="flex items-start justify-between">
                            <div>
                                <p className="text-sm font-medium text-slate-500 mb-1">Completed</p>
                                <h4 className="text-3xl font-bold text-slate-900">{stats.totals.total_completed}</h4>
                            </div>
                            <div className="w-12 h-12 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                {/* @ts-ignore */}
                                <Dashicon icon="yes" size={24} />
                            </div>
                        </div>
                        <div className="mt-4 h-1 w-full bg-slate-100 rounded-full overflow-hidden">
                            <div className="h-full bg-emerald-500 rounded-full" style={{ width: '100%' }}></div>
                        </div>
                    </div>

                    {/* Failed Card */}
                    <div className="relative overflow-hidden bg-white rounded-xl border border-slate-200 p-6 hover:shadow-md transition-shadow duration-300 group">
                        <div className="flex items-start justify-between">
                            <div>
                                <p className="text-sm font-medium text-slate-500 mb-1">Failed</p>
                                <h4 className="text-3xl font-bold text-slate-900">{stats.totals.total_failed}</h4>
                            </div>
                            <div className="w-12 h-12 rounded-lg bg-red-50 text-red-500 flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                {/* @ts-ignore */}
                                <Dashicon icon="warning" size={24} />
                            </div>
                        </div>
                        <div className="mt-4 h-1 w-full bg-slate-100 rounded-full overflow-hidden">
                            <div className="h-full bg-red-500 rounded-full" style={{ width: `${stats.totals.total_failed > 0 ? '100%' : '0%'}` }}></div>
                        </div>
                    </div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-4 pt-6 border-t border-slate-100">
                    <div className="text-sm text-slate-500">
                        {stats.totals.total_pending === 0
                            ? "All caught up! No images pending conversion."
                            : `${stats.totals.total_pending} images waiting for processing.`}
                    </div>

                    <div className="flex gap-3">
                        <Button
                            variant="secondary"
                            onClick={handleClearCompleted}
                            loading={clearing}
                            disabled={stats.totals.total_completed === 0}
                            icon="trash"
                        >
                            Clear Completed
                        </Button>

                        <Button
                            variant="primary"
                            onClick={handleProcessQueue}
                            loading={processing}
                            disabled={stats.totals.total_pending === 0}
                            icon="update"
                        >
                            Process Queue
                        </Button>
                    </div>
                </div>
            </div>
        </Card>
    );
};
