/**
 * Stats Overview Component
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

import React from 'react';
import { OptimizationStats } from '@types/index';
import { Card } from '@components/index';

interface StatsOverviewProps {
  stats: OptimizationStats;
}

export const StatsOverview: React.FC<StatsOverviewProps> = ({ stats }) => {
  const formatBytes = (bytes: number): string => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  };

  const formatNumber = (num: number): string => {
    return new Intl.NumberFormat().format(num);
  };

  const getCacheHitRate = (): string => {
    const total = stats.cache.hits + stats.cache.misses;
    if (total === 0) return '0%';
    return ((stats.cache.hits / total) * 100).toFixed(1) + '%';
  };

  return (
    <>
      {/* Cache Statistics */}
      <Card title="Cache Performance" className="wppo-stats-card">
        <div className="wppo-stats-grid">
          <div className="wppo-stat-item">
            <div className="wppo-stat-value">{formatNumber(stats.cache.hits)}</div>
            <div className="wppo-stat-label">Cache Hits</div>
          </div>
          <div className="wppo-stat-item">
            <div className="wppo-stat-value">{formatNumber(stats.cache.misses)}</div>
            <div className="wppo-stat-label">Cache Misses</div>
          </div>
          <div className="wppo-stat-item">
            <div className="wppo-stat-value">{getCacheHitRate()}</div>
            <div className="wppo-stat-label">Hit Rate</div>
          </div>
          <div className="wppo-stat-item">
            <div className="wppo-stat-value">{formatBytes(stats.cache.size)}</div>
            <div className="wppo-stat-label">Cache Size</div>
          </div>
        </div>
      </Card>

      {/* Asset Optimization */}
      <Card title="Asset Optimization" className="wppo-stats-card">
        <div className="wppo-stats-grid">
          <div className="wppo-stat-item">
            <div className="wppo-stat-value">{formatNumber(stats.assets.css_files_minified)}</div>
            <div className="wppo-stat-label">CSS Files Minified</div>
          </div>
          <div className="wppo-stat-item">
            <div className="wppo-stat-value">{formatNumber(stats.assets.js_files_minified)}</div>
            <div className="wppo-stat-label">JS Files Minified</div>
          </div>
          <div className="wppo-stat-item">
            <div className="wppo-stat-value">{formatBytes(stats.assets.bytes_saved)}</div>
            <div className="wppo-stat-label">Bytes Saved</div>
          </div>
        </div>
      </Card>

      {/* Image Optimization */}
      <Card title="Image Optimization" className="wppo-stats-card">
        <div className="wppo-stats-grid">
          <div className="wppo-stat-item">
            <div className="wppo-stat-value">{formatNumber(stats.images.images_optimized)}</div>
            <div className="wppo-stat-label">Images Optimized</div>
          </div>
          <div className="wppo-stat-item">
            <div className="wppo-stat-value">{formatNumber(stats.images.webp_conversions)}</div>
            <div className="wppo-stat-label">WebP Conversions</div>
          </div>
          <div className="wppo-stat-item">
            <div className="wppo-stat-value">{formatBytes(stats.images.bytes_saved)}</div>
            <div className="wppo-stat-label">Bytes Saved</div>
          </div>
        </div>
      </Card>
    </>
  );
};