import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faChartLine,
    faSync,
    faPlay,
    faCalendarAlt,
} from '@fortawesome/free-solid-svg-icons';

interface ChartDataset {
    label: string;
    data: (number | null)[];
    color: string;
}

interface ChartData {
    labels: string[];
    datasets: {
        scores: ChartDataset[];
        vitals: ChartDataset[];
    };
    count: number;
}

interface MonitoringStatus {
    is_scheduled: boolean;
    next_run: string | null;
    last_run: string | null;
    records: number;
}

const HistoryChart: React.FC = () => {
    const [loading, setLoading] = useState(true);
    const [chartData, setChartData] = useState<ChartData | null>(null);
    const [status, setStatus] = useState<MonitoringStatus | null>(null);
    const [device, setDevice] = useState<'mobile' | 'desktop'>('mobile');
    const [timeRange, setTimeRange] = useState<string>('-7 days');
    const [running, setRunning] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const fetchData = async () => {
        setLoading(true);
        setError(null);

        try {
            const apiUrl = (window as any).wppoAdmin?.apiUrl;
            const nonce = (window as any).wppoAdmin?.nonce || '';

            if (!apiUrl) {
                throw new Error('API URL not configured');
            }

            // Fetch chart data
            const chartResponse = await fetch(
                `${apiUrl}/monitor/chart-data?device=${device}&since=${encodeURIComponent(timeRange)}`,
                { headers: { 'X-WP-Nonce': nonce } }
            );
            const chartResult = await chartResponse.json();
            if (chartResult.success) {
                setChartData(chartResult.data);
            }

            // Fetch monitoring status
            const statusResponse = await fetch(
                `${apiUrl}/monitor/monitoring-status`,
                { headers: { 'X-WP-Nonce': nonce } }
            );
            const statusResult = await statusResponse.json();
            if (statusResult.success) {
                setStatus(statusResult.data);
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Failed to load data');
            // Mock data for development
            setChartData({
                labels: ['Dec 10', 'Dec 11', 'Dec 12', 'Dec 13', 'Dec 14'],
                datasets: {
                    scores: [
                        { label: 'Performance', data: [75, 78, 82, 80, 85], color: '#10b981' },
                        { label: 'Accessibility', data: [90, 90, 92, 91, 93], color: '#3b82f6' },
                    ],
                    vitals: [
                        { label: 'LCP (sec)', data: [2.5, 2.3, 2.1, 2.2, 2.0], color: '#ef4444' },
                        { label: 'FCP (sec)', data: [1.8, 1.7, 1.5, 1.6, 1.4], color: '#f97316' },
                    ],
                },
                count: 5,
            });
            setStatus({
                is_scheduled: false,
                next_run: null,
                last_run: null,
                records: 0,
            });
        } finally {
            setLoading(false);
        }
    };

    const runManualCheck = async () => {
        setRunning(true);
        try {
            const apiUrl = (window as any).wppoAdmin?.apiUrl;
            const nonce = (window as any).wppoAdmin?.nonce || '';

            const response = await fetch(`${apiUrl}/monitor/run-check`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': nonce,
                    'Content-Type': 'application/json',
                },
            });

            const result = await response.json();
            if (result.success) {
                // Refresh data
                fetchData();
            }
        } catch (err) {
            setError('Failed to run performance check');
        } finally {
            setRunning(false);
        }
    };

    useEffect(() => {
        fetchData();
    }, [device, timeRange]);

    const getScoreColor = (value: number | null): string => {
        if (value === null) return 'text-gray-400';
        if (value >= 90) return 'text-green-500';
        if (value >= 50) return 'text-amber-500';
        return 'text-red-500';
    };

    // Simple sparkline component
    const Sparkline: React.FC<{ data: (number | null)[], color: string, max?: number }> = ({ data, color, max = 100 }) => {
        const validData = data.filter((v): v is number => v !== null);
        if (validData.length < 2) return <span className="text-gray-400 text-xs">No data</span>;

        const height = 40;
        const width = 200;
        const padding = 2;
        const actualMax = max || Math.max(...validData);
        const points = validData.map((v, i) => {
            const x = padding + (i / (validData.length - 1)) * (width - padding * 2);
            const y = height - padding - (v / actualMax) * (height - padding * 2);
            return `${x},${y}`;
        }).join(' ');

        return (
            <svg width={width} height={height} className="overflow-visible">
                <polyline
                    fill="none"
                    stroke={color}
                    strokeWidth="2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    points={points}
                />
                {validData.map((v, i) => {
                    const x = padding + (i / (validData.length - 1)) * (width - padding * 2);
                    const y = height - padding - (v / actualMax) * (height - padding * 2);
                    return (
                        <circle key={i} cx={x} cy={y} r="3" fill={color} opacity="0.8" />
                    );
                })}
            </svg>
        );
    };

    if (loading && !chartData) {
        return (
            <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-8">
                <div className="flex items-center justify-center gap-3">
                    <FontAwesomeIcon icon={faSync} className="animate-spin text-blue-600" />
                    <span className="text-gray-600 dark:text-gray-400">Loading history...</span>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 rounded-lg flex items-center justify-center">
                        <FontAwesomeIcon icon={faChartLine} />
                    </div>
                    <div>
                        <h3 className="text-xl font-bold text-gray-900 dark:text-white">
                            Performance History
                        </h3>
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            {chartData?.count || 0} data points recorded
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    {/* Time Range Selector */}
                    <div className="flex items-center gap-2">
                        <FontAwesomeIcon icon={faCalendarAlt} className="text-gray-400" />
                        <select
                            value={timeRange}
                            onChange={(e) => setTimeRange(e.target.value)}
                            className="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200"
                        >
                            <option value="-24 hours">Last 24 hours</option>
                            <option value="-7 days">Last 7 days</option>
                            <option value="-30 days">Last 30 days</option>
                            <option value="-90 days">Last 90 days</option>
                        </select>
                    </div>

                    {/* Device Toggle */}
                    <div className="flex bg-gray-100 dark:bg-gray-700 rounded-lg p-1">
                        <button
                            onClick={() => setDevice('mobile')}
                            className={`px-3 py-1 text-sm font-medium rounded-md transition-colors ${
                                device === 'mobile'
                                    ? 'bg-white dark:bg-gray-600 text-gray-900 dark:text-white shadow-sm'
                                    : 'text-gray-600 dark:text-gray-400'
                            }`}
                        >
                            Mobile
                        </button>
                        <button
                            onClick={() => setDevice('desktop')}
                            className={`px-3 py-1 text-sm font-medium rounded-md transition-colors ${
                                device === 'desktop'
                                    ? 'bg-white dark:bg-gray-600 text-gray-900 dark:text-white shadow-sm'
                                    : 'text-gray-600 dark:text-gray-400'
                            }`}
                        >
                            Desktop
                        </button>
                    </div>

                    {/* Run Check Button */}
                    <button
                        onClick={runManualCheck}
                        disabled={running}
                        className="flex items-center gap-2 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50"
                    >
                        <FontAwesomeIcon icon={running ? faSync : faPlay} className={running ? 'animate-spin' : ''} />
                        {running ? 'Running...' : 'Run Check'}
                    </button>
                </div>
            </div>

            {/* Status Bar */}
            {status && (
                <div className="flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4">
                    <span>
                        <strong>Status:</strong>{' '}
                        {status.is_scheduled ? (
                            <span className="text-green-600 dark:text-green-400">Scheduled</span>
                        ) : (
                            <span className="text-amber-600 dark:text-amber-400">Not scheduled</span>
                        )}
                    </span>
                    {status.last_run && (
                        <span>
                            <strong>Last run:</strong> {new Date(status.last_run).toLocaleString()}
                        </span>
                    )}
                    {status.next_run && (
                        <span>
                            <strong>Next run:</strong> {new Date(status.next_run).toLocaleString()}
                        </span>
                    )}
                    <span>
                        <strong>Records:</strong> {status.records}
                    </span>
                </div>
            )}

            {/* Charts Grid */}
            {chartData && chartData.count > 0 ? (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Lighthouse Scores Chart */}
                    <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                        <h4 className="font-semibold text-gray-900 dark:text-white mb-4">Lighthouse Scores</h4>
                        <div className="space-y-4">
                            {chartData.datasets.scores.map((dataset) => {
                                const latest = dataset.data[dataset.data.length - 1];
                                return (
                                    <div key={dataset.label} className="flex items-center justify-between">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <div
                                                className="w-3 h-3 rounded-full flex-shrink-0"
                                                style={{ backgroundColor: dataset.color }}
                                            />
                                            <span className="text-sm text-gray-700 dark:text-gray-300 truncate">
                                                {dataset.label}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-4">
                                            <Sparkline data={dataset.data} color={dataset.color} />
                                            <span className={`font-bold tabular-nums ${getScoreColor(latest)}`}>
                                                {latest ?? '-'}
                                            </span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {/* Core Web Vitals Chart */}
                    <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                        <h4 className="font-semibold text-gray-900 dark:text-white mb-4">Core Web Vitals</h4>
                        <div className="space-y-4">
                            {chartData.datasets.vitals.map((dataset) => {
                                const latest = dataset.data[dataset.data.length - 1];
                                const max = Math.max(...dataset.data.filter((v): v is number => v !== null)) * 1.2;
                                return (
                                    <div key={dataset.label} className="flex items-center justify-between">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <div
                                                className="w-3 h-3 rounded-full flex-shrink-0"
                                                style={{ backgroundColor: dataset.color }}
                                            />
                                            <span className="text-sm text-gray-700 dark:text-gray-300 truncate">
                                                {dataset.label}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-4">
                                            <Sparkline data={dataset.data} color={dataset.color} max={max} />
                                            <span className="font-bold tabular-nums text-gray-900 dark:text-white">
                                                {latest !== null ? latest.toFixed(2) : '-'}
                                            </span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            ) : (
                <div className="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-8 text-center">
                    <FontAwesomeIcon icon={faChartLine} className="text-4xl text-gray-400 mb-3" />
                    <h4 className="font-semibold text-gray-700 dark:text-gray-300 mb-2">No History Data</h4>
                    <p className="text-gray-500 dark:text-gray-400 mb-4">
                        Run a performance check to start collecting historical data.
                    </p>
                    <button
                        onClick={runManualCheck}
                        disabled={running}
                        className="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors"
                    >
                        Run First Check
                    </button>
                </div>
            )}
        </div>
    );
};

export default HistoryChart;
