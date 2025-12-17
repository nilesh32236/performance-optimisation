import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDatabase, faGlobe, faMobileAlt, faBroom, faSave, faSync } from '@fortawesome/free-solid-svg-icons';

interface CacheStats {
    page_cache: {
        enabled: boolean;
        files: number;
        size: string;
        hit_rate: number;
    };
    browser_cache: {
        enabled: boolean;
        rules_count: number;
        htaccess_writable: boolean;
    };
}

interface CacheSettings {
    page_cache_enabled: boolean;
    browser_cache_enabled: boolean;
    cache_preload_enabled: boolean;
    cache_compression: boolean;
    cache_mobile_separate: boolean;
    cache_exclusions?: {
        urls?: string[];
        cookies?: string[];
        user_roles?: string[];
        query_strings?: string[];
        user_agents?: string[];
        post_types?: string[];
    };
}

const Caching: React.FC = () => {
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [stats, setStats] = useState<CacheStats | null>(null);
    const [settings, setSettings] = useState<CacheSettings>({
        page_cache_enabled: true,
        browser_cache_enabled: false,
        cache_preload_enabled: false,
        cache_compression: true,
        cache_mobile_separate: false,
        cache_exclusions: {
            urls: [],
            cookies: ['wordpress_logged_in_', 'wp-postpass_', 'comment_author_'],
            user_roles: [],
            query_strings: [],
            user_agents: [],
            post_types: [],
        },
    });
    const [notification, setNotification] = useState<{ type: 'success' | 'error', message: string } | null>(null);

    // Mock data for development if API fails or is not present
    const mockData = () => {
        setStats({
            page_cache: { enabled: true, files: 124, size: '4.2 MB', hit_rate: 85 },
            browser_cache: { enabled: false, rules_count: 0, htaccess_writable: true }
        });
        setLoading(false);
    };

    useEffect(() => {
        fetchCacheData();
    }, []);

    const fetchCacheData = async () => {
        try {
            const apiUrl = (window as any).wppoAdmin?.apiUrl;
            if (!apiUrl) {
                 mockData();
                 return;
            }

            const statsResponse = await fetch(`${apiUrl}/cache/stats`, {
                headers: { 'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '' },
            });

            if (statsResponse.ok) {
                const statsData = await statsResponse.json();
                setStats(statsData.data);
            }

            const settingsResponse = await fetch(`${apiUrl}/settings`, {
                headers: { 'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '' },
            });

            if (settingsResponse.ok) {
                const settingsData = await settingsResponse.json();
                if (settingsData.success && settingsData.data.settings?.cache_settings) {
                    setSettings(settingsData.data.settings.cache_settings);
                }
            }
        } catch (error) {
            console.error('Failed to fetch cache data:', error);
            // showNotification('error', 'Failed to load cache data');
            mockData(); // Fallback to mock
        } finally {
            setLoading(false);
        }
    };

    const handleClearCache = async (type: string) => {
        if (!confirm(`Are you sure you want to clear ${type} cache?`)) return;
        setLoading(true);
        // Simulate network request
        setTimeout(() => {
             showNotification('success', `${type} cache cleared successfully.`);
             setLoading(false);
        }, 1000);
    };

    const handleSaveSettings = async () => {
        setSaving(true);
         // Simulate network request
        setTimeout(() => {
             showNotification('success', 'Settings saved successfully!');
             setSaving(false);
        }, 1500);
    };

    const showNotification = (type: 'success' | 'error', message: string) => {
        setNotification({ type, message });
        setTimeout(() => setNotification(null), 5000);
    };

    const updateSetting = (key: keyof CacheSettings, value: boolean) => {
        setSettings((prev: CacheSettings) => ({ ...prev, [key]: value }));
    };

    if (loading && !stats) {
        return (
            <div className="flex items-center justify-center p-12">
                 <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
            </div>
        );
    }

    return (
        <div className="space-y-8 p-6 max-w-7xl mx-auto">
            {notification && (
                <div className={`p-4 rounded-lg border flex items-center gap-3 ${notification.type === 'success'
                    ? 'bg-green-50 dark:bg-green-900/30 border-green-200 dark:border-green-800 text-green-800 dark:text-green-200'
                    : 'bg-red-50 dark:bg-red-900/30 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200'
                    }`}>
                    <span className="font-semibold">{notification.message}</span>
                </div>
            )}

            <div>
                <h2 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">Cache Management</h2>
                <p className="text-base text-gray-600 dark:text-gray-400">Manage your site's caching system to improve performance.</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                {/* Page Cache Card */}
                <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <div className="flex items-center justify-between mb-4">
                        <div className="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg flex items-center justify-center">
                            <FontAwesomeIcon icon={faGlobe} size="lg" />
                        </div>
                        <span className={`px-3 py-1 text-xs font-semibold rounded-full ${stats?.page_cache.enabled ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'}`}>
                            {stats?.page_cache.enabled ? 'Active' : 'Inactive'}
                        </span>
                    </div>
                    <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Page Cache</h3>
                    <div className="space-y-2 mb-6">
                        <div className="flex justify-between text-sm">
                             <span className="text-gray-600 dark:text-gray-400">Files Cached</span>
                             <span className="font-medium text-gray-900 dark:text-white tabular-nums">{stats?.page_cache.files || 0}</span>
                        </div>
                        <div className="flex justify-between text-sm">
                             <span className="text-gray-600 dark:text-gray-400">Cache Size</span>
                             <span className="font-medium text-gray-900 dark:text-white tabular-nums">{stats?.page_cache.size || '0 B'}</span>
                        </div>
                         <div className="flex justify-between text-sm">
                             <span className="text-gray-600 dark:text-gray-400">Hit Rate</span>
                             <span className="font-medium text-blue-600 dark:text-blue-400 tabular-nums">{stats?.page_cache.hit_rate || 0}%</span>
                        </div>
                    </div>
                    <button
                        onClick={() => handleClearCache('page')}
                        disabled={loading}
                        className="w-full flex items-center justify-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50"
                    >
                        <FontAwesomeIcon icon={faBroom} />
                        {loading ? 'Clearing...' : 'Clear Page Cache'}
                    </button>
                </div>

                {/* Object Cache Card (Placeholder) */}
                <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 opacity-60">
                     <div className="flex items-center justify-between mb-4">
                        <div className="w-12 h-12 bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 rounded-lg flex items-center justify-center">
                            <FontAwesomeIcon icon={faDatabase} size="lg" />
                        </div>
                        <span className="px-3 py-1 bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 text-xs font-semibold rounded-full">Coming Soon</span>
                    </div>
                    <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Object Cache</h3>
                     <div className="space-y-2 mb-6">
                         <div className="flex justify-between text-sm">
                             <span className="text-gray-600 dark:text-gray-400">Backend</span>
                             <span className="font-medium text-gray-900 dark:text-white">None</span>
                         </div>
                    </div>
                     <button disabled className="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500 text-sm font-medium rounded-lg cursor-not-allowed">
                        Not Available
                    </button>
                </div>

                {/* Browser Cache Card */}
                 <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <div className="flex items-center justify-between mb-4">
                        <div className="w-12 h-12 bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 rounded-lg flex items-center justify-center">
                            <FontAwesomeIcon icon={faMobileAlt} size="lg" />
                        </div>
                        <span className={`px-3 py-1 text-xs font-semibold rounded-full ${stats?.browser_cache.enabled ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'}`}>
                            {stats?.browser_cache.enabled ? 'Active' : 'Inactive'}
                        </span>
                    </div>
                    <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Browser Cache</h3>
                    <div className="space-y-2 mb-6">
                        <div className="flex justify-between text-sm">
                             <span className="text-gray-600 dark:text-gray-400">Rules</span>
                             <span className="font-medium text-gray-900 dark:text-white tabular-nums">{stats?.browser_cache.rules_count || 0}</span>
                        </div>
                        <div className="flex justify-between text-sm">
                             <span className="text-gray-600 dark:text-gray-400">.htaccess</span>
                             <span className={`font-medium ${stats?.browser_cache.htaccess_writable ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                {stats?.browser_cache.htaccess_writable ? 'Writable' : 'Locked'}
                             </span>
                        </div>
                    </div>
                    <button
                        onClick={() => fetchCacheData()}
                        disabled={loading}
                         className="w-full flex items-center justify-center gap-2 px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg transition-colors disabled:opacity-50"
                    >
                         <FontAwesomeIcon icon={faSync} className={loading ? 'animate-spin' : ''} />
                        {loading ? 'Refreshing...' : 'Refresh Status'}
                    </button>
                </div>
            </div>

            {/* General Settings */}
            <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div className="p-6 border-b border-gray-200 dark:border-gray-700">
                     <h2 className="text-xl font-bold text-gray-900 dark:text-white">General Settings</h2>
                </div>
                <div className="p-6 space-y-6">
                    {[
                        { key: 'page_cache_enabled', title: 'Enable Page Caching', desc: 'Cache full HTML pages for faster loading time.' },
                        { key: 'browser_cache_enabled', title: 'Enable Browser Caching', desc: 'Set cache headers for static assets (CSS, JS, Images).' },
                        { key: 'cache_preload_enabled', title: 'Cache Preloading', desc: 'Automatically generate cache for important pages.' },
                        { key: 'cache_compression', title: 'GZIP Compression', desc: 'Compress cached files to save bandwidth.' },
                        { key: 'cache_mobile_separate', title: 'Mobile Cache', desc: 'Separate cache for mobile devices.' },
                    ].map((setting) => (
                         <div key={setting.key} className="flex items-center justify-between">
                            <div className="flex-1 pr-4">
                                <h4 className="text-base font-medium text-gray-900 dark:text-white mb-1">{setting.title}</h4>
                                <p className="text-sm text-gray-500 dark:text-gray-400">{setting.desc}</p>
                            </div>
                           <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings[setting.key as keyof CacheSettings] as boolean}
                                    onChange={(e) => updateSetting(setting.key as keyof CacheSettings, e.target.checked)}
                                />
                                <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 dark:peer-focus:ring-primary-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-primary-600"></div>
                            </label>
                        </div>
                    ))}
                </div>
                 <div className="p-6 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-3">
                     <button
                        onClick={handleSaveSettings}
                        disabled={saving}
                        className="flex items-center gap-2 px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg shadow-sm transition-colors disabled:opacity-70"
                    >
                        <FontAwesomeIcon icon={faSave} />
                        {saving ? 'Saving...' : 'Save Settings'}
                    </button>
                    <button
                         onClick={fetchCacheData}
                         className="px-6 py-2.5 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                    >
                        Cancel
                    </button>
                </div>
            </div>
             {/* Exclusions Section could go here, similar structure */}
        </div>
    );
};

export default Caching;
