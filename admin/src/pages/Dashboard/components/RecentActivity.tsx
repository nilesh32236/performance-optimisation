/**
 * Recent Activity Component
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

import React, { useState, useEffect } from 'react';
import { LoadingSpinner } from '@components/index';

interface ActivityItem {
  id: string;
  type: 'optimization' | 'cache' | 'settings' | 'error';
  message: string;
  timestamp: string;
  details?: string;
}

export const RecentActivity: React.FC = () => {
  const [activities, setActivities] = useState<ActivityItem[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Simulate loading recent activities
    const loadActivities = async () => {
      try {
        // In a real implementation, this would fetch from an API
        const mockActivities: ActivityItem[] = [
          {
            id: '1',
            type: 'optimization',
            message: 'Optimized 15 images to WebP format',
            timestamp: new Date(Date.now() - 1000 * 60 * 30).toISOString(), // 30 minutes ago
            details: 'Saved 2.3 MB in total file size'
          },
          {
            id: '2',
            type: 'cache',
            message: 'Cache cleared successfully',
            timestamp: new Date(Date.now() - 1000 * 60 * 60 * 2).toISOString(), // 2 hours ago
          },
          {
            id: '3',
            type: 'settings',
            message: 'Minification settings updated',
            timestamp: new Date(Date.now() - 1000 * 60 * 60 * 4).toISOString(), // 4 hours ago
          },
          {
            id: '4',
            type: 'optimization',
            message: 'CSS and JS files minified',
            timestamp: new Date(Date.now() - 1000 * 60 * 60 * 6).toISOString(), // 6 hours ago
            details: 'Processed 8 CSS files and 12 JS files'
          },
          {
            id: '5',
            type: 'error',
            message: 'Failed to optimize image: large-banner.jpg',
            timestamp: new Date(Date.now() - 1000 * 60 * 60 * 8).toISOString(), // 8 hours ago
            details: 'File size exceeds maximum limit'
          }
        ];

        // Simulate API delay
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        setActivities(mockActivities);
      } catch (error) {
        console.error('Failed to load activities:', error);
      } finally {
        setLoading(false);
      }
    };

    loadActivities();
  }, []);

  const formatTimestamp = (timestamp: string): string => {
    const date = new Date(timestamp);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / (1000 * 60));
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    if (diffMins < 60) {
      return `${diffMins} minutes ago`;
    } else if (diffHours < 24) {
      return `${diffHours} hours ago`;
    } else {
      return `${diffDays} days ago`;
    }
  };

  const getActivityIcon = (type: ActivityItem['type']): string => {
    switch (type) {
      case 'optimization':
        return '⚡';
      case 'cache':
        return '🗄️';
      case 'settings':
        return '⚙️';
      case 'error':
        return '❌';
      default:
        return '📝';
    }
  };

  const getActivityClass = (type: ActivityItem['type']): string => {
    return `wppo-activity-item wppo-activity-item--${type}`;
  };

  if (loading) {
    return (
      <div className="wppo-activity-loading">
        <LoadingSpinner size="medium" />
        <p>Loading recent activity...</p>
      </div>
    );
  }

  if (activities.length === 0) {
    return (
      <div className="wppo-activity-empty">
        <p>No recent activity to display.</p>
      </div>
    );
  }

  return (
    <div className="wppo-recent-activity">
      <div className="wppo-activity-list">
        {activities.map((activity) => (
          <div key={activity.id} className={getActivityClass(activity.type)}>
            <div className="wppo-activity-item__icon">
              {getActivityIcon(activity.type)}
            </div>
            <div className="wppo-activity-item__content">
              <div className="wppo-activity-item__message">
                {activity.message}
              </div>
              {activity.details && (
                <div className="wppo-activity-item__details">
                  {activity.details}
                </div>
              )}
              <div className="wppo-activity-item__timestamp">
                {formatTimestamp(activity.timestamp)}
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};