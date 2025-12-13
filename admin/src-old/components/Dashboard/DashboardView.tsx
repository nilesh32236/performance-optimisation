import React from 'react';
import { Dashicon } from '@wordpress/components';

interface MetricCardProps {
    title: string;
    value: string | number;
    subtitle: string;
    change?: string;
    changeType?: 'positive' | 'negative' | 'neutral';
    icon: string;
    color: 'blue' | 'green' | 'purple' | 'orange';
}

const MetricCard: React.FC<MetricCardProps> = ({ title, value, subtitle, change, changeType, icon, color }) => {
    const colorClasses = {
        blue: { bg: 'bg-blue-500', light: 'bg-blue-50', text: 'text-blue-600', border: 'border-blue-200' },
        green: { bg: 'bg-green-500', light: 'bg-green-50', text: 'text-green-600', border: 'border-green-200' },
        purple: { bg: 'bg-purple-500', light: 'bg-purple-50', text: 'text-purple-600', border: 'border-purple-200' },
        orange: { bg: 'bg-orange-500', light: 'bg-orange-50', text: 'text-orange-600', border: 'border-orange-200' },
    };

    const changeColors = {
        positive: 'text-green-600 bg-green-50',
        negative: 'text-red-600 bg-red-50',
        neutral: 'text-gray-600 bg-gray-50',
    };

    return (
        <div className="bg-white rounded-xl border-2 border-gray-200 p-6 hover:shadow-lg hover:border-gray-300 transition-all">
            <div className="flex items-start justify-between mb-4">
                <div className={`w-14 h-14 rounded-xl ${colorClasses[color].bg} flex items-center justify-center shadow-lg`}>
                    <Dashicon icon={icon as any} style={{ fontSize: '28px', color: 'white' }} />
                </div>
                {change && (
                    <span className={`px-3 py-1 rounded-full text-sm font-semibold ${changeColors[changeType || 'neutral']}`}>
                        {change}
                    </span>
                )}
            </div>
            <h3 className="text-gray-600 text-base font-semibold mb-2">{title}</h3>
            <p className="text-4xl font-bold text-gray-900 mb-1">{value}</p>
            <p className="text-sm text-gray-500">{subtitle}</p>
        </div>
    );
};

export const DashboardView: React.FC = () => {
    const handleClearCache = () => {
        if (confirm('Are you sure you want to clear all cache?')) {
            alert('Cache cleared successfully!');
        }
    };

    const handleOptimizeImages = () => {
        alert('Navigate to Images tab to optimize');
    };

    const handleRunTest = () => {
        alert('Running performance test...');
    };

    return (
        <div className="space-y-8">
            {/* Welcome Section */}
            <div className="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl p-8 text-white shadow-lg">
                <h1 className="text-3xl font-bold mb-2">Welcome to Performance Optimisation</h1>
                <p className="text-lg text-blue-100">Your site is performing well. Here's your current status.</p>
            </div>

            {/* Metrics Grid */}
            <div>
                <h2 className="text-2xl font-bold text-gray-900 mb-6">Performance Metrics</h2>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <MetricCard
                        title="Performance Score"
                        value="85/100"
                        subtitle="Good performance rating"
                        change="+5 points"
                        changeType="positive"
                        icon="performance"
                        color="blue"
                    />
                    <MetricCard
                        title="Cache Hit Rate"
                        value="92%"
                        subtitle="Excellent cache efficiency"
                        change="+3%"
                        changeType="positive"
                        icon="database"
                        color="green"
                    />
                    <MetricCard
                        title="Page Load Time"
                        value="1.2s"
                        subtitle="Fast loading speed"
                        change="-0.3s faster"
                        changeType="positive"
                        icon="clock"
                        color="purple"
                    />
                    <MetricCard
                        title="Images Optimized"
                        value="248"
                        subtitle="Total optimized images"
                        change="+12 today"
                        changeType="positive"
                        icon="format-image"
                        color="orange"
                    />
                </div>
            </div>

            {/* Quick Actions */}
            <div className="bg-white rounded-xl border-2 border-gray-200 p-8">
                <h2 className="text-2xl font-bold text-gray-900 mb-6">Quick Actions</h2>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <button
                        onClick={handleClearCache}
                        className="group p-6 rounded-xl border-2 border-blue-200 bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-all text-left"
                    >
                        <div className="w-12 h-12 bg-blue-500 rounded-lg flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                            <Dashicon icon="trash" style={{ fontSize: '24px', color: 'white' }} />
                        </div>
                        <h3 className="text-lg font-bold text-gray-900 mb-2">Clear All Cache</h3>
                        <p className="text-base text-gray-600">Remove all cached files to see fresh content</p>
                    </button>

                    <button
                        onClick={handleOptimizeImages}
                        className="group p-6 rounded-xl border-2 border-gray-200 bg-white hover:bg-gray-50 hover:border-gray-300 transition-all text-left"
                    >
                        <div className="w-12 h-12 bg-orange-500 rounded-lg flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                            <Dashicon icon="format-image" style={{ fontSize: '24px', color: 'white' }} />
                        </div>
                        <h3 className="text-lg font-bold text-gray-900 mb-2">Optimize Images</h3>
                        <p className="text-base text-gray-600">Compress and convert images to WebP format</p>
                    </button>

                    <button
                        onClick={handleRunTest}
                        className="group p-6 rounded-xl border-2 border-gray-200 bg-white hover:bg-gray-50 hover:border-gray-300 transition-all text-left"
                    >
                        <div className="w-12 h-12 bg-purple-500 rounded-lg flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                            <Dashicon icon="chart-line" style={{ fontSize: '24px', color: 'white' }} />
                        </div>
                        <h3 className="text-lg font-bold text-gray-900 mb-2">Run Performance Test</h3>
                        <p className="text-base text-gray-600">Analyze your site speed and get recommendations</p>
                    </button>
                </div>
            </div>

            {/* Two Column Layout */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                {/* Cache Statistics */}
                <div className="bg-white rounded-xl border-2 border-gray-200 p-8">
                    <h2 className="text-2xl font-bold text-gray-900 mb-6">Cache Statistics</h2>
                    <div className="space-y-4">
                        <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p className="text-base font-semibold text-gray-900">Page Cache</p>
                                <p className="text-sm text-gray-600">HTML pages cached</p>
                            </div>
                            <div className="text-right">
                                <p className="text-2xl font-bold text-green-600">1,234</p>
                                <p className="text-sm text-gray-500">files</p>
                            </div>
                        </div>
                        <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p className="text-base font-semibold text-gray-900">Object Cache</p>
                                <p className="text-sm text-gray-600">Database queries cached</p>
                            </div>
                            <div className="text-right">
                                <p className="text-2xl font-bold text-blue-600">5,678</p>
                                <p className="text-sm text-gray-500">objects</p>
                            </div>
                        </div>
                        <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p className="text-base font-semibold text-gray-900">Cache Size</p>
                                <p className="text-sm text-gray-600">Total disk space used</p>
                            </div>
                            <div className="text-right">
                                <p className="text-2xl font-bold text-purple-600">45.2 MB</p>
                                <p className="text-sm text-gray-500">storage</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Recent Activity */}
                <div className="bg-white rounded-xl border-2 border-gray-200 p-8">
                    <h2 className="text-2xl font-bold text-gray-900 mb-6">Recent Activity</h2>
                    <div className="space-y-4">
                        {[
                            { 
                                icon: 'yes-alt', 
                                text: 'Cache cleared successfully', 
                                detail: 'All cached files removed',
                                time: '2 minutes ago', 
                                color: 'green' 
                            },
                            { 
                                icon: 'format-image', 
                                text: '15 images optimized', 
                                detail: 'Saved 2.3 MB of space',
                                time: '1 hour ago', 
                                color: 'blue' 
                            },
                            { 
                                icon: 'update', 
                                text: 'Settings updated', 
                                detail: 'Cache settings modified',
                                time: '3 hours ago', 
                                color: 'gray' 
                            },
                            { 
                                icon: 'performance', 
                                text: 'Performance test completed', 
                                detail: 'Score improved by 5 points',
                                time: '5 hours ago', 
                                color: 'purple' 
                            },
                        ].map((activity, index) => (
                            <div key={index} className="flex items-start gap-4 p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                <div className={`w-12 h-12 rounded-lg bg-${activity.color}-100 text-${activity.color}-600 flex items-center justify-center flex-shrink-0`}>
                                    <Dashicon icon={activity.icon as any} style={{ fontSize: '20px' }} />
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-base font-semibold text-gray-900">{activity.text}</p>
                                    <p className="text-sm text-gray-600">{activity.detail}</p>
                                    <p className="text-xs text-gray-500 mt-1">{activity.time}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Recommendations */}
            <div className="bg-yellow-50 border-2 border-yellow-200 rounded-xl p-8">
                <div className="flex items-start gap-4">
                    <div className="w-12 h-12 bg-yellow-500 rounded-lg flex items-center justify-center flex-shrink-0">
                        <Dashicon icon="lightbulb" style={{ fontSize: '24px', color: 'white' }} />
                    </div>
                    <div className="flex-1">
                        <h2 className="text-2xl font-bold text-gray-900 mb-3">Recommendations</h2>
                        <ul className="space-y-3">
                            <li className="flex items-start gap-3">
                                <span className="text-yellow-600 font-bold text-lg">•</span>
                                <div>
                                    <p className="text-base font-semibold text-gray-900">Enable Browser Caching</p>
                                    <p className="text-sm text-gray-600">Set cache headers to improve repeat visitor load times</p>
                                </div>
                            </li>
                            <li className="flex items-start gap-3">
                                <span className="text-yellow-600 font-bold text-lg">•</span>
                                <div>
                                    <p className="text-base font-semibold text-gray-900">Optimize Remaining Images</p>
                                    <p className="text-sm text-gray-600">52 images can still be optimized to save bandwidth</p>
                                </div>
                            </li>
                            <li className="flex items-start gap-3">
                                <span className="text-yellow-600 font-bold text-lg">•</span>
                                <div>
                                    <p className="text-base font-semibold text-gray-900">Minify CSS and JavaScript</p>
                                    <p className="text-sm text-gray-600">Reduce file sizes by removing unnecessary characters</p>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    );
};
