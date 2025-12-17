import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faLightbulb,
    faExclamationTriangle,
    faExclamationCircle,
    faInfoCircle,
    faCheckCircle,
    faSync,
    faChevronDown,
    faChevronUp,
    faRocket,
    faCog,
    faBolt,
} from '@fortawesome/free-solid-svg-icons';

interface Suggestion {
    title: string;
    description: string;
    priority: 'high' | 'medium' | 'low';
    impact: 'high' | 'medium' | 'low';
    category: string;
    fix_actions: string[];
    created_at?: string;
}

interface SuggestionsData {
    suggestions: Suggestion[];
    counts: {
        high: number;
        medium: number;
        low: number;
        total: number;
    };
    url: string;
    timestamp: string;
}

interface SuggestionsPanelProps {
    onNavigateToSettings?: (tab: string) => void;
}

const SuggestionsPanel: React.FC<SuggestionsPanelProps> = ({ onNavigateToSettings }) => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [data, setData] = useState<SuggestionsData | null>(null);
    const [expandedItems, setExpandedItems] = useState<Set<number>>(new Set());
    const [filter, setFilter] = useState<'all' | 'high' | 'medium' | 'low'>('all');
    const [applyingFix, setApplyingFix] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ type: 'success' | 'error', message: string } | null>(null);
    const [appliedActions, setAppliedActions] = useState<Set<string>>(new Set());

    const showNotification = (type: 'success' | 'error', message: string) => {
        setNotification({ type, message });
        setTimeout(() => setNotification(null), 4000);
    };

    const applyFix = async (action: string) => {
        setApplyingFix(action);
        
        try {
            const apiUrl = (window as any).wppoAdmin?.apiUrl;
            if (!apiUrl) {
                throw new Error('API URL not configured');
            }

            const response = await fetch(`${apiUrl}/monitor/apply-fix`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '',
                },
                body: JSON.stringify({ action }),
            });

            const result = await response.json();
            
            if (result.success) {
                showNotification('success', result.message || `${action} enabled successfully!`);
                setAppliedActions(prev => new Set([...prev, action]));
                // Refresh suggestions after a short delay
                setTimeout(() => fetchSuggestions(true), 1000);
            } else {
                throw new Error(result.message || 'Failed to apply fix');
            }
        } catch (err) {
            showNotification('error', err instanceof Error ? err.message : 'Failed to apply fix');
        } finally {
            setApplyingFix(null);
        }
    };

    const fetchSuggestions = async (refresh = false) => {
        setLoading(true);
        setError(null);
        
        try {
            const apiUrl = (window as any).wppoAdmin?.apiUrl;
            if (!apiUrl) {
                throw new Error('API URL not configured');
            }

            const response = await fetch(
                `${apiUrl}/monitor/suggestions?refresh=${refresh ? '1' : '0'}`,
                {
                    headers: {
                        'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '',
                    },
                }
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();
            if (result.success) {
                setData(result.data);
                setAppliedActions(new Set()); // Reset applied actions on refresh
            } else {
                throw new Error(result.message || 'Failed to fetch suggestions');
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Unknown error');
            // Set mock data for development
            setData({
                suggestions: [
                    {
                        title: 'Page caching is disabled',
                        description: 'Enable page caching to serve static HTML and dramatically improve load times.',
                        priority: 'high',
                        impact: 'high',
                        category: 'caching',
                        fix_actions: ['caching'],
                    },
                    {
                        title: 'Lazy loading is disabled',
                        description: 'Enable lazy loading to defer off-screen images and improve initial load time.',
                        priority: 'high',
                        impact: 'high',
                        category: 'images',
                        fix_actions: ['lazy_load'],
                    },
                    {
                        title: 'CSS minification is disabled',
                        description: 'Enable CSS minification to reduce file sizes.',
                        priority: 'medium',
                        impact: 'medium',
                        category: 'code',
                        fix_actions: ['minify_css'],
                    },
                ],
                counts: { high: 2, medium: 1, low: 0, total: 3 },
                url: window.location.origin,
                timestamp: new Date().toISOString(),
            });
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchSuggestions();
    }, []);

    const toggleExpand = (index: number) => {
        const newExpanded = new Set(expandedItems);
        if (newExpanded.has(index)) {
            newExpanded.delete(index);
        } else {
            newExpanded.add(index);
        }
        setExpandedItems(newExpanded);
    };

    const getPriorityStyles = (priority: string) => {
        switch (priority) {
            case 'high':
                return {
                    bg: 'bg-red-50 dark:bg-red-900/20',
                    border: 'border-red-200 dark:border-red-800',
                    icon: faExclamationCircle,
                    iconColor: 'text-red-500 dark:text-red-400',
                    badge: 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
                };
            case 'medium':
                return {
                    bg: 'bg-amber-50 dark:bg-amber-900/20',
                    border: 'border-amber-200 dark:border-amber-800',
                    icon: faExclamationTriangle,
                    iconColor: 'text-amber-500 dark:text-amber-400',
                    badge: 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
                };
            case 'low':
            default:
                return {
                    bg: 'bg-blue-50 dark:bg-blue-900/20',
                    border: 'border-blue-200 dark:border-blue-800',
                    icon: faInfoCircle,
                    iconColor: 'text-blue-500 dark:text-blue-400',
                    badge: 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
                };
        }
    };

    const getImpactStyles = (impact: string) => {
        switch (impact) {
            case 'high':
                return 'text-green-600 dark:text-green-400';
            case 'medium':
                return 'text-yellow-600 dark:text-yellow-400';
            case 'low':
            default:
                return 'text-gray-500 dark:text-gray-400';
        }
    };

    const getActionLabel = (action: string) => {
        const labels: Record<string, string> = {
            caching: 'Enable Caching',
            lazy_load: 'Enable Lazy Load',
            minify_css: 'Enable CSS Minification',
            minify_js: 'Enable JS Minification',
            minify_html: 'Enable HTML Minification',
            defer_js: 'Enable Defer JS',
            delay_js: 'Enable Delay JS',
            images: 'Enable WebP',
        };
        return labels[action] || action;
    };

    const filteredSuggestions = data?.suggestions.filter((s) => {
        if (filter === 'all') return true;
        return s.priority === filter;
    }) || [];

    if (loading && !data) {
        return (
            <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-8">
                <div className="flex items-center justify-center gap-3">
                    <FontAwesomeIcon icon={faSync} className="animate-spin text-primary-600" />
                    <span className="text-gray-600 dark:text-gray-400">Analyzing performance...</span>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* Notification Toast */}
            {notification && (
                <div className={`fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg flex items-center gap-3 animate-pulse ${
                    notification.type === 'success'
                        ? 'bg-green-500 text-white'
                        : 'bg-red-500 text-white'
                }`}>
                    <FontAwesomeIcon icon={notification.type === 'success' ? faCheckCircle : faExclamationCircle} />
                    <span className="font-medium">{notification.message}</span>
                </div>
            )}

            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 dark:text-yellow-400 rounded-lg flex items-center justify-center">
                        <FontAwesomeIcon icon={faLightbulb} />
                    </div>
                    <div>
                        <h3 className="text-xl font-bold text-gray-900 dark:text-white">
                            Performance Suggestions
                        </h3>
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            {data?.counts.total || 0} recommendations to improve your site
                        </p>
                    </div>
                </div>
                <button
                    onClick={() => fetchSuggestions(true)}
                    disabled={loading}
                    className="flex items-center gap-2 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50"
                >
                    <FontAwesomeIcon icon={faSync} className={loading ? 'animate-spin' : ''} />
                    {loading ? 'Analyzing...' : 'Re-analyze'}
                </button>
            </div>

            {/* Summary Cards */}
            {data && (
                <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    {[
                        { label: 'Total', count: data.counts.total, color: 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200' },
                        { label: 'High Priority', count: data.counts.high, color: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' },
                        { label: 'Medium', count: data.counts.medium, color: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300' },
                        { label: 'Low', count: data.counts.low, color: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' },
                    ].map((item) => (
                        <div
                            key={item.label}
                            className={`${item.color} rounded-xl p-4 text-center`}
                        >
                            <div className="text-3xl font-bold">{item.count}</div>
                            <div className="text-sm font-medium opacity-80">{item.label}</div>
                        </div>
                    ))}
                </div>
            )}

            {/* Filter Tabs */}
            <div className="flex gap-2 border-b border-gray-200 dark:border-gray-700 pb-2">
                {(['all', 'high', 'medium', 'low'] as const).map((f) => (
                    <button
                        key={f}
                        onClick={() => setFilter(f)}
                        className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${
                            filter === f
                                ? 'bg-primary-600 text-white'
                                : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700'
                        }`}
                    >
                        {f.charAt(0).toUpperCase() + f.slice(1)}
                        {f !== 'all' && data && (
                            <span className="ml-1.5 opacity-70">
                                ({data.counts[f]})
                            </span>
                        )}
                    </button>
                ))}
            </div>

            {/* Suggestions List */}
            {filteredSuggestions.length === 0 ? (
                <div className="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl p-8 text-center">
                    <FontAwesomeIcon icon={faCheckCircle} className="text-4xl text-green-500 dark:text-green-400 mb-3" />
                    <h4 className="text-lg font-bold text-green-700 dark:text-green-300 mb-2">
                        Great job!
                    </h4>
                    <p className="text-green-600 dark:text-green-400">
                        {filter === 'all'
                            ? 'No performance issues detected. Your site is well optimized!'
                            : `No ${filter} priority suggestions.`}
                    </p>
                </div>
            ) : (
                <div className="space-y-3">
                    {filteredSuggestions.map((suggestion, index) => {
                        const styles = getPriorityStyles(suggestion.priority);
                        const isExpanded = expandedItems.has(index);

                        return (
                            <div
                                key={index}
                                className={`${styles.bg} ${styles.border} border rounded-xl overflow-hidden transition-all`}
                            >
                                <button
                                    onClick={() => toggleExpand(index)}
                                    className="w-full flex items-center justify-between p-4 text-left"
                                >
                                    <div className="flex items-center gap-3 flex-1 min-w-0">
                                        <FontAwesomeIcon
                                            icon={styles.icon}
                                            className={`${styles.iconColor} text-lg flex-shrink-0`}
                                        />
                                        <div className="flex-1 min-w-0">
                                            <h4 className="font-semibold text-gray-900 dark:text-white truncate">
                                                {suggestion.title}
                                            </h4>
                                            <div className="flex items-center gap-2 mt-1">
                                                <span className={`${styles.badge} text-xs font-medium px-2 py-0.5 rounded-full`}>
                                                    {suggestion.priority.toUpperCase()}
                                                </span>
                                                <span className={`text-xs ${getImpactStyles(suggestion.impact)}`}>
                                                    <FontAwesomeIcon icon={faRocket} className="mr-1" />
                                                    {suggestion.impact} impact
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <FontAwesomeIcon
                                        icon={isExpanded ? faChevronUp : faChevronDown}
                                        className="text-gray-400 ml-3 flex-shrink-0"
                                    />
                                </button>

                                {isExpanded && (
                                    <div className="px-4 pb-4 pt-0 border-t border-gray-200/50 dark:border-gray-700/50">
                                        <p className="text-sm text-gray-600 dark:text-gray-300 mt-3 mb-4">
                                            {suggestion.description}
                                        </p>
                                        {suggestion.fix_actions.length > 0 && (
                                            <div className="flex flex-wrap gap-2">
                                                {suggestion.fix_actions.map((action) => {
                                                    const isApplying = applyingFix === action;
                                                    const isApplied = appliedActions.has(action);
                                                    
                                                    return (
                                                        <button
                                                            key={action}
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                if (!isApplied) {
                                                                    applyFix(action);
                                                                }
                                                            }}
                                                            disabled={isApplying || isApplied}
                                                            className={`flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors ${
                                                                isApplied
                                                                    ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 cursor-default'
                                                                    : 'bg-primary-600 hover:bg-primary-700 text-white'
                                                            } disabled:opacity-70`}
                                                        >
                                                            <FontAwesomeIcon 
                                                                icon={isApplied ? faCheckCircle : (isApplying ? faSync : faBolt)} 
                                                                className={isApplying ? 'animate-spin' : ''} 
                                                            />
                                                            {isApplied ? 'Applied!' : (isApplying ? 'Applying...' : getActionLabel(action))}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Footer */}
            {data && (
                <div className="text-center text-xs text-gray-500 dark:text-gray-400">
                    Last analyzed: {new Date(data.timestamp).toLocaleString()}
                </div>
            )}
        </div>
    );
};

export default SuggestionsPanel;

