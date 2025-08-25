/**
 * Dashboard Page Component
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

import React, { useState, useEffect } from 'react';
import { PerformanceMetrics, OptimizationStats } from '@types/index';
import { Card, Button, LoadingSpinner } from '@components/index';
import { MetricsChart } from './components/MetricsChart';
import { StatsOverview } from './components/StatsOverview';
import { RecentActivity } from './components/RecentActivity';
import './Dashboard.scss';

export const Dashboard: React.FC = () => {
  const [metrics, setMetrics] = useState<PerformanceMetrics | null>(null);
  const [stats, setStats] = useState<OptimizationStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const loadDashboardData = async () => {
    try {
      // Load data from WordPress global or API
      if (window.wppoAdmin?.metrics && window.wppoAdmin?.stats) {
        setMetrics(window.wppoAdmin.metrics);
        setStats(window.wppoAdmin.stats);
      } else {
        // Fallback to API call
        const response = await fetch(`${window.wppoAdmin?.apiUrl}/dashboard`, {
          headers: {
            'X-WP-Nonce': window.wppoAdmin?.nonce || ''
          }
        });
        
        if (response.ok) {
          const data = await response.json();
          setMetrics(data.metrics);
          setStats(data.stats);
        }
      }
    } catch (error) {
      console.error('Failed to load dashboard data:', error);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  const handleRefresh = async () => {
    setRefreshing(true);
    await loadDashboardData();
  };

  useEffect(() => {
    loadDashboardData();
  }, []);

  if (loading) {
    return (
      <div className="wppo-dashboard-loading">
        <LoadingSpinner size="large" />
        <p>Loading dashboard data...</p>
      </div>
    );
  }

  return (
    <div className="wppo-dashboard">
      <div className="wppo-dashboard__header">
        <div className="wppo-dashboard__header-content">
          <h1>Performance Dashboard</h1>
          <p>Monitor your site's performance metrics and optimization statistics.</p>
        </div>
        <div className="wppo-dashboard__header-actions">
          <Button
            variant="secondary"
            onClick={handleRefresh}
            loading={refreshing}
          >
            Refresh Data
          </Button>
        </div>
      </div>

      <div className="wppo-dashboard__content">
        {/* Performance Metrics Overview */}
        <div className="wppo-dashboard__section">
          <h2>Performance Metrics</h2>
          <div className="wppo-dashboard__metrics-grid">
            <Card title="Page Load Time" className="wppo-metric-card">
              <div className="wppo-metric-value">
                {metrics?.page_load_time ? `${metrics.page_load_time.toFixed(2)}s` : 'N/A'}
              </div>
              <div className="wppo-metric-label">Average load time</div>
            </Card>

            <Card title="First Contentful Paint" className="wppo-metric-card">
              <div className="wppo-metric-value">
                {metrics?.first_contentful_paint ? `${metrics.first_contentful_paint.toFixed(2)}s` : 'N/A'}
              </div>
              <div className="wppo-metric-label">Time to first content</div>
            </Card>

            <Card title="Largest Contentful Paint" className="wppo-metric-card">
              <div className="wppo-metric-value">
                {metrics?.largest_contentful_paint ? `${metrics.largest_contentful_paint.toFixed(2)}s` : 'N/A'}
              </div>
              <div className="wppo-metric-label">Time to largest content</div>
            </Card>

            <Card title="Cumulative Layout Shift" className="wppo-metric-card">
              <div className="wppo-metric-value">
                {metrics?.cumulative_layout_shift ? metrics.cumulative_layout_shift.toFixed(3) : 'N/A'}
              </div>
              <div className="wppo-metric-label">Layout stability score</div>
            </Card>
          </div>
        </div>

        {/* Performance Chart */}
        {metrics && (
          <div className="wppo-dashboard__section">
            <Card title="Performance Trends">
              <MetricsChart metrics={metrics} />
            </Card>
          </div>
        )}

        {/* Optimization Statistics */}
        <div className="wppo-dashboard__section">
          <h2>Optimization Statistics</h2>
          <div className="wppo-dashboard__stats-grid">
            {stats && <StatsOverview stats={stats} />}
          </div>
        </div>

        {/* Recent Activity */}
        <div className="wppo-dashboard__section">
          <Card title="Recent Activity">
            <RecentActivity />
          </Card>
        </div>

        {/* Quick Actions */}
        <div className="wppo-dashboard__section">
          <Card title="Quick Actions">
            <div className="wppo-dashboard__actions">
              <Button variant="primary">
                Run Performance Test
              </Button>
              <Button variant="secondary">
                Clear All Caches
              </Button>
              <Button variant="secondary">
                Optimize Images
              </Button>
              <Button variant="tertiary">
                View Settings
              </Button>
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
};