/**
 * Real-Time Performance Monitor Component
 *
 * Provides live performance monitoring with WebSocket connections
 * and real-time metrics updates.
 *
 * @package PerformanceOptimisation
 * @since 2.0.0
 */

import React, { useState, useEffect, useRef } from 'react';
import { Card, Button } from '@components/index';

interface RealTimeMetrics {
	timestamp: number;
	page_load_time: number;
	memory_usage: number;
	cpu_usage: number;
	active_users: number;
	cache_hit_ratio: number;
	optimization_queue: number;
}

interface RealTimeMonitorProps {
	refreshInterval?: number;
	maxDataPoints?: number;
}

export const RealTimeMonitor: React.FC<RealTimeMonitorProps> = ({
	refreshInterval = 5000, // 5 seconds
	maxDataPoints = 60, // Keep last 60 data points (5 minutes at 5s intervals)
}) => {
	const [metrics, setMetrics] = useState<RealTimeMetrics[]>([]);
	const [isConnected, setIsConnected] = useState(false);
	const [isMonitoring, setIsMonitoring] = useState(false);
	const intervalRef = useRef<NodeJS.Timeout | null>(null);
	const wsRef = useRef<WebSocket | null>(null);

	/**
	 * Fetch current metrics from API
	 */
	const fetchMetrics = async (): Promise<RealTimeMetrics | null> => {
		try {
			const response = await fetch(`${window.wppoAdmin?.apiUrl}/analytics/real-time`, {
				headers: {
					'X-WP-Nonce': window.wppoAdmin?.nonce || '',
				},
			});

			if (response.ok) {
				const data = await response.json();
				return {
					timestamp: Date.now(),
					page_load_time: data.page_load_time || 0,
					memory_usage: data.memory_usage || 0,
					cpu_usage: data.cpu_usage || 0,
					active_users: data.active_users || 0,
					cache_hit_ratio: data.cache_hit_ratio || 0,
					optimization_queue: data.optimization_queue || 0,
				};
			}
		} catch (error) {
			console.error('Failed to fetch real-time metrics:', error);
		}
		return null;
	};

	/**
	 * Add new metrics data point
	 */
	const addMetricsPoint = (newMetrics: RealTimeMetrics) => {
		setMetrics(prev => {
			const updated = [...prev, newMetrics];
			// Keep only the last maxDataPoints
			return updated.slice(-maxDataPoints);
		});
	};

	/**
	 * Start monitoring with polling
	 */
	const startPollingMonitor = () => {
		if (intervalRef.current) {
			clearInterval(intervalRef.current);
		}

		intervalRef.current = setInterval(async () => {
			const newMetrics = await fetchMetrics();
			if (newMetrics) {
				addMetricsPoint(newMetrics);
			}
		}, refreshInterval);

		setIsMonitoring(true);
	};

	/**
	 * Stop monitoring
	 */
	const stopMonitoring = () => {
		if (intervalRef.current) {
			clearInterval(intervalRef.current);
			intervalRef.current = null;
		}

		if (wsRef.current) {
			wsRef.current.close();
			wsRef.current = null;
		}

		setIsMonitoring(false);
		setIsConnected(false);
	};

	/**
	 * Initialize WebSocket connection (if available)
	 */
	const initWebSocket = () => {
		// Check if WebSocket endpoint is available
		const wsUrl = window.wppoAdmin?.wsUrl;
		if (!wsUrl) {
			// Fallback to polling
			startPollingMonitor();
			return;
		}

		try {
			wsRef.current = new WebSocket(wsUrl);

			wsRef.current.onopen = () => {
				setIsConnected(true);
				setIsMonitoring(true);
				console.log('Real-time monitoring WebSocket connected');
			};

			wsRef.current.onmessage = (event) => {
				try {
					const data = JSON.parse(event.data);
					if (data.type === 'metrics') {
						addMetricsPoint({
							timestamp: Date.now(),
							...data.metrics,
						});
					}
				} catch (error) {
					console.error('Failed to parse WebSocket message:', error);
				}
			};

			wsRef.current.onclose = () => {
				setIsConnected(false);
				// Fallback to polling if WebSocket fails
				if (isMonitoring) {
					startPollingMonitor();
				}
			};

			wsRef.current.onerror = (error) => {
				console.error('WebSocket error:', error);
				setIsConnected(false);
				// Fallback to polling
				startPollingMonitor();
			};
		} catch (error) {
			console.error('Failed to initialize WebSocket:', error);
			// Fallback to polling
			startPollingMonitor();
		}
	};

	/**
	 * Toggle monitoring
	 */
	const toggleMonitoring = () => {
		if (isMonitoring) {
			stopMonitoring();
		} else {
			initWebSocket();
		}
	};

	/**
	 * Clear metrics data
	 */
	const clearMetrics = () => {
		setMetrics([]);
	};

	/**
	 * Get latest metrics
	 */
	const latestMetrics = metrics.length > 0 ? metrics[metrics.length - 1] : null;

	/**
	 * Calculate trend for a metric
	 */
	const getTrend = (metricKey: keyof RealTimeMetrics): 'up' | 'down' | 'stable' => {
		if (metrics.length < 2) return 'stable';
		
		const current = metrics[metrics.length - 1][metricKey] as number;
		const previous = metrics[metrics.length - 2][metricKey] as number;
		
		if (current > previous) return 'up';
		if (current < previous) return 'down';
		return 'stable';
	};

	/**
	 * Format metric value
	 */
	const formatMetric = (value: number, type: string): string => {
		switch (type) {
			case 'time':
				return `${value.toFixed(2)}s`;
			case 'memory':
				return `${(value / 1024 / 1024).toFixed(1)}MB`;
			case 'percentage':
				return `${value.toFixed(1)}%`;
			case 'count':
				return value.toString();
			default:
				return value.toFixed(2);
		}
	};

	/**
	 * Cleanup on unmount
	 */
	useEffect(() => {
		return () => {
			stopMonitoring();
		};
	}, []);

	return (
		<Card title="Real-Time Performance Monitor" className="wppo-realtime-monitor">
			<div className="wppo-realtime-monitor__header">
				<div className="wppo-realtime-monitor__status">
					<span className={`wppo-status-indicator ${isConnected ? 'connected' : 'disconnected'}`}>
						{isConnected ? '🟢' : '🔴'}
					</span>
					<span className="wppo-status-text">
						{isConnected ? 'WebSocket Connected' : isMonitoring ? 'Polling Mode' : 'Disconnected'}
					</span>
				</div>
				<div className="wppo-realtime-monitor__controls">
					<Button
						variant={isMonitoring ? 'secondary' : 'primary'}
						onClick={toggleMonitoring}
						size="small"
					>
						{isMonitoring ? 'Stop Monitoring' : 'Start Monitoring'}
					</Button>
					<Button
						variant="tertiary"
						onClick={clearMetrics}
						size="small"
						disabled={metrics.length === 0}
					>
						Clear Data
					</Button>
				</div>
			</div>

			{latestMetrics && (
				<div className="wppo-realtime-monitor__metrics">
					<div className="wppo-metric-grid">
						<div className="wppo-metric-item">
							<div className="wppo-metric-label">Page Load Time</div>
							<div className="wppo-metric-value">
								{formatMetric(latestMetrics.page_load_time, 'time')}
								<span className={`wppo-trend wppo-trend--${getTrend('page_load_time')}`}>
									{getTrend('page_load_time') === 'up' ? '↗' : getTrend('page_load_time') === 'down' ? '↘' : '→'}
								</span>
							</div>
						</div>

						<div className="wppo-metric-item">
							<div className="wppo-metric-label">Memory Usage</div>
							<div className="wppo-metric-value">
								{formatMetric(latestMetrics.memory_usage, 'memory')}
								<span className={`wppo-trend wppo-trend--${getTrend('memory_usage')}`}>
									{getTrend('memory_usage') === 'up' ? '↗' : getTrend('memory_usage') === 'down' ? '↘' : '→'}
								</span>
							</div>
						</div>

						<div className="wppo-metric-item">
							<div className="wppo-metric-label">Cache Hit Ratio</div>
							<div className="wppo-metric-value">
								{formatMetric(latestMetrics.cache_hit_ratio, 'percentage')}
								<span className={`wppo-trend wppo-trend--${getTrend('cache_hit_ratio')}`}>
									{getTrend('cache_hit_ratio') === 'up' ? '↗' : getTrend('cache_hit_ratio') === 'down' ? '↘' : '→'}
								</span>
							</div>
						</div>

						<div className="wppo-metric-item">
							<div className="wppo-metric-label">Active Users</div>
							<div className="wppo-metric-value">
								{formatMetric(latestMetrics.active_users, 'count')}
								<span className={`wppo-trend wppo-trend--${getTrend('active_users')}`}>
									{getTrend('active_users') === 'up' ? '↗' : getTrend('active_users') === 'down' ? '↘' : '→'}
								</span>
							</div>
						</div>

						<div className="wppo-metric-item">
							<div className="wppo-metric-label">Optimization Queue</div>
							<div className="wppo-metric-value">
								{formatMetric(latestMetrics.optimization_queue, 'count')}
								<span className={`wppo-trend wppo-trend--${getTrend('optimization_queue')}`}>
									{getTrend('optimization_queue') === 'up' ? '↗' : getTrend('optimization_queue') === 'down' ? '↘' : '→'}
								</span>
							</div>
						</div>
					</div>
				</div>
			)}

			{metrics.length > 0 && (
				<div className="wppo-realtime-monitor__chart">
					<div className="wppo-mini-chart">
						{/* Simple ASCII-style chart for now - could be replaced with a proper chart library */}
						<div className="wppo-chart-title">Page Load Time Trend (Last {metrics.length} points)</div>
						<div className="wppo-chart-data">
							{metrics.slice(-20).map((metric, index) => (
								<div
									key={metric.timestamp}
									className="wppo-chart-bar"
									style={{
										height: `${Math.min(metric.page_load_time * 20, 100)}px`,
										backgroundColor: metric.page_load_time > 3 ? '#e74c3c' : metric.page_load_time > 1.5 ? '#f39c12' : '#27ae60'
									}}
									title={`${formatMetric(metric.page_load_time, 'time')} at ${new Date(metric.timestamp).toLocaleTimeString()}`}
								/>
							))}
						</div>
					</div>
				</div>
			)}

			{!isMonitoring && metrics.length === 0 && (
				<div className="wppo-realtime-monitor__empty">
					<p>Click "Start Monitoring" to begin real-time performance tracking.</p>
				</div>
			)}
		</Card>
	);
};
