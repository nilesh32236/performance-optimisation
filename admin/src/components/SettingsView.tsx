import React, { useState, useEffect } from 'react';
import { Dashicon, Spinner } from '@wordpress/components';
import { EnhancedFeatureCard } from './EnhancedFeatureCard';
import { QueueStats } from './Queue/QueueStats';

interface Settings {
    caching: boolean;
    images: boolean;
    code: boolean;
    storageMode: 'safe' | 'space_saver';
    // Granular settings
    minify_css: boolean;
    minify_js: boolean;
    minify_html: boolean;
    defer_js: boolean;
    delay_js: boolean;
    lazy_load: boolean;
}

export const SettingsView: React.FC = () => {
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [settings, setSettings] = useState<Settings>({
        caching: false,
        images: false,
        code: false,
        storageMode: 'safe',
        minify_css: false,
        minify_js: false,
        minify_html: false,
        defer_js: false,
        delay_js: false,
        lazy_load: false,
    });
    const [diskSpaceSaved, setDiskSpaceSaved] = useState<number>(0);
    const [notification, setNotification] = useState<{ type: 'success' | 'error', message: string } | null>(null);

    useEffect(() => {
        fetchSettings();
    }, []);

    const fetchSettings = async () => {
        try {
            const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
            const response = await fetch(`${apiUrl}/settings`, {
                headers: { 'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '' },
            });

            if (response.ok) {
                const data = await response.json();
                if (data.success && data.data.settings) {
                    const cacheSettings = data.data.settings.cache_settings || {};
                    const imageSettings = data.data.settings.image_optimization || {};
                    const minificationSettings = data.data.settings.minification || {};
                    const advancedSettings = data.data.settings.advanced || {};

                    setSettings({
                        caching: cacheSettings.page_cache_enabled && cacheSettings.browser_cache_enabled,
                        images: imageSettings.auto_convert_on_upload,
                        code: minificationSettings.minify_css || minificationSettings.minify_js || minificationSettings.minify_html,
                        storageMode: imageSettings.storage_mode || data.data.settings.images?.storage_mode || 'safe',
                        // Granular
                        minify_css: minificationSettings.minify_css || false,
                        minify_js: minificationSettings.minify_js || false,
                        minify_html: minificationSettings.minify_html || false,
                        defer_js: advancedSettings.defer_js || false,
                        delay_js: advancedSettings.delay_js || false,
                        lazy_load: imageSettings.lazy_load_enabled || false,
                    });
                }
            }

            // Fetch queue stats for disk space
            const queueResponse = await fetch(`${apiUrl}/queue/stats`, {
                headers: { 'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '' },
            });
            if (queueResponse.ok) {
                const queueData = await queueResponse.json();
                if (queueData.totals?.disk_space_saved) {
                    setDiskSpaceSaved(queueData.totals.disk_space_saved);
                }
            }
        } catch (error) {
            console.error('Failed to fetch settings:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleToggle = async (key: keyof Settings, value: boolean | string) => {
        setSettings(prev => ({ ...prev, [key]: value }));
        setSaving(true);

        // Construct the full settings object based on current state + new value
        // We need to be careful to send the structure the backend expects
        const currentSettings = { ...settings, [key]: value };

        const newSettings: any = {};

        // Map flat state back to nested API structure
        if (key === 'caching') {
            newSettings.cache_settings = {
                page_cache_enabled: value,
                browser_cache_enabled: value,
                cache_preload_enabled: value,
                cache_compression: value,
            };
        } else if (key === 'images') {
            newSettings.image_optimization = {
                auto_convert_on_upload: value,
                webp_conversion: value,
                lazy_load_enabled: currentSettings.lazy_load,
            };
        } else if (key === 'code') {
            newSettings.minification = {
                minify_css: value,
                minify_js: value,
                minify_html: value,
            };
            // Also update granular state to match main toggle
            setSettings(prev => ({
                ...prev,
                minify_css: value as boolean,
                minify_js: value as boolean,
                minify_html: value as boolean
            }));
        } else if (key === 'storageMode') {
            newSettings.images = { storage_mode: value };
        } else {
            // Handle granular updates
            if (['minify_css', 'minify_js', 'minify_html'].includes(key)) {
                newSettings.minification = {
                    minify_css: currentSettings.minify_css,
                    minify_js: currentSettings.minify_js,
                    minify_html: currentSettings.minify_html,
                };
            } else if (['defer_js', 'delay_js'].includes(key)) {
                newSettings.advanced = {
                    defer_js: currentSettings.defer_js,
                    delay_js: currentSettings.delay_js,
                };
            } else if (key === 'lazy_load') {
                newSettings.image_optimization = {
                    lazy_load_enabled: value,
                    auto_convert_on_upload: currentSettings.images,
                    webp_conversion: currentSettings.images,
                };
            }
        }

        try {
            const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
            const response = await fetch(`${apiUrl}/settings`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '',
                },
                body: JSON.stringify({ settings: newSettings }),
            });

            const data = await response.json();
            if (data.success) {
                setNotification({ type: 'success', message: 'Settings updated!' });
                setTimeout(() => setNotification(null), 3000);
            } else {
                setNotification({ type: 'error', message: 'Failed to save settings' });
            }
        } catch (error) {
            setNotification({ type: 'error', message: 'Error saving settings' });
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return <div className="flex justify-center p-12"><Spinner /></div>;
    }

    return (
        <div className="max-w-7xl mx-auto space-y-10 pb-12">
            {/* Hero Status Section */}
            <div className="bg-gradient-to-r from-slate-900 to-slate-800 rounded-2xl p-8 text-white shadow-xl relative overflow-hidden">
                <div className="absolute top-0 right-0 w-64 h-64 bg-blue-500 rounded-full mix-blend-overlay filter blur-3xl opacity-20 -mr-16 -mt-16"></div>
                <div className="relative z-10 flex flex-col md:flex-row items-center justify-between gap-8">
                    <div className="text-center md:text-left">
                        <h2 className="text-3xl font-bold mb-2">Performance Optimization</h2>
                        <p className="text-slate-300 text-lg">
                            Configure your optimization settings below.
                            <br />
                            Enable features with a simple toggle, or expand advanced options for fine-tuning.
                        </p>
                    </div>
                    <div className="flex items-center gap-8 bg-slate-800/50 p-6 rounded-xl border border-slate-700 backdrop-blur-sm">
                        <div className="text-center">
                            <div className="text-3xl font-bold text-emerald-400 mb-1">
                                {Object.values(settings).filter(v => v === true).length}/3
                            </div>
                            <div className="text-xs text-slate-400 uppercase tracking-wider font-semibold">Active</div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Notification */}
            {notification && (
                <div className={`fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 animate-fade-in-up flex items-center gap-3 ${notification.type === 'success' ? 'bg-emerald-500 text-white' : 'bg-red-500 text-white'
                    }`}>
                    {/* @ts-ignore */}
                    <Dashicon icon={notification.type === 'success' ? 'yes' : 'no'} />
                    <span className="font-medium">{notification.message}</span>
                </div>
            )}

            {/* Feature Cards Grid */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                {/* Caching Card */}
                <EnhancedFeatureCard
                    icon="database"
                    title="Caching System"
                    description="Accelerate page loads by serving static HTML copies instead of processing PHP for every visit."
                    enabled={settings.caching}
                    onToggle={(enabled) => handleToggle('caching', enabled)}
                    color="blue"
                    disabled={saving}
                >
                    <div className="space-y-3 text-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Page Cache</span>
                            <span className="text-blue-600 font-semibold">Enabled</span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Browser Cache</span>
                            <span className="text-blue-600 font-semibold">Enabled</span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Cache Preload</span>
                            <span className="text-blue-600 font-semibold">Enabled</span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Compression</span>
                            <span className="text-blue-600 font-semibold">Enabled</span>
                        </div>
                    </div>
                </EnhancedFeatureCard>

                {/* Images Card */}
                <EnhancedFeatureCard
                    icon="format-image"
                    title="Image Optimization"
                    description="Automatically compress uploads and convert to modern formats like WebP/AVIF to save bandwidth."
                    enabled={settings.images}
                    onToggle={(enabled) => handleToggle('images', enabled)}
                    color="purple"
                    disabled={saving}
                >
                    <div className="space-y-3 text-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Auto-convert on Upload</span>
                            <span className="text-purple-600 font-semibold">Enabled</span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">WebP Conversion</span>
                            <span className="text-purple-600 font-semibold">Enabled</span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Lazy Loading</span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.lazy_load}
                                    onChange={(e) => handleToggle('lazy_load', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-purple-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-600"></div>
                            </label>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">CSS Backgrounds</span>
                            <span className="text-purple-600 font-semibold">Enabled</span>
                        </div>
                    </div>
                </EnhancedFeatureCard>

                {/* Code Minification Card */}
                <EnhancedFeatureCard
                    icon="editor-code"
                    title="Code Minification"
                    description="Minify CSS and JavaScript files to reduce payload size and improve parsing speed."
                    enabled={settings.code}
                    onToggle={(enabled) => handleToggle('code', enabled)}
                    color="emerald"
                    disabled={saving}
                >
                    <div className="space-y-3 text-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Minify CSS</span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.minify_css}
                                    onChange={(e) => handleToggle('minify_css', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600"></div>
                            </label>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Minify JavaScript</span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.minify_js}
                                    onChange={(e) => handleToggle('minify_js', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600"></div>
                            </label>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Minify HTML</span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.minify_html}
                                    onChange={(e) => handleToggle('minify_html', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600"></div>
                            </label>
                        </div>
                    </div>
                </EnhancedFeatureCard>

                {/* Asset Optimization Card */}
                <EnhancedFeatureCard
                    icon="performance"
                    title="Asset Optimization"
                    description="Optimize how scripts are loaded to improve Core Web Vitals and initial render time."
                    enabled={settings.defer_js || settings.delay_js}
                    onToggle={(enabled) => {
                        handleToggle('defer_js', enabled);
                        if (!enabled) handleToggle('delay_js', false);
                    }}
                    color="indigo"
                    disabled={saving}
                >
                    <div className="space-y-3 text-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Defer JavaScript</span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.defer_js}
                                    onChange={(e) => handleToggle('defer_js', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                            </label>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Delay JavaScript Execution</span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.delay_js}
                                    onChange={(e) => handleToggle('delay_js', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                            </label>
                        </div>
                    </div>
                </EnhancedFeatureCard>

                {/* Storage Mode Card */}
                <EnhancedFeatureCard
                    icon="database-export"
                    title="Storage Mode"
                    description="Choose whether to keep original images after conversion or delete them to save disk space."
                    enabled={settings.storageMode === 'space_saver'}
                    onToggle={(enabled) => handleToggle('storageMode', enabled ? 'space_saver' : 'safe')}
                    color="orange"
                    disabled={saving}
                >
                    <div className="space-y-4">
                        <p className="text-sm text-slate-600 mb-3">
                            Current mode: <span className="font-semibold">{settings.storageMode === 'safe' ? 'Safe Mode' : 'Space Saver Mode'}</span>
                        </p>

                        <div className="space-y-3">
                            <label className="flex items-start gap-3 cursor-pointer p-3 rounded-lg hover:bg-white transition-colors">
                                <input
                                    type="radio"
                                    name="storage_mode_detail"
                                    value="safe"
                                    className="mt-1"
                                    checked={settings.storageMode === 'safe'}
                                    onChange={() => handleToggle('storageMode', 'safe')}
                                    disabled={saving}
                                />
                                <div className="flex-1">
                                    <div className="font-semibold text-slate-900 text-sm">Safe Mode</div>
                                    <div className="text-xs text-slate-600 mt-1">Keeps original images (recommended)</div>
                                </div>
                            </label>

                            <label className="flex items-start gap-3 cursor-pointer p-3 rounded-lg hover:bg-white transition-colors">
                                <input
                                    type="radio"
                                    name="storage_mode_detail"
                                    value="space_saver"
                                    className="mt-1"
                                    checked={settings.storageMode === 'space_saver'}
                                    onChange={() => handleToggle('storageMode', 'space_saver')}
                                    disabled={saving}
                                />
                                <div className="flex-1">
                                    <div className="font-semibold text-slate-900 text-sm">Space Saver Mode</div>
                                    <div className="text-xs text-orange-600 mt-1">⚠️ Deletes originals (~70-80% space saved)</div>
                                </div>
                            </label>
                        </div>

                        <div className="pt-3 border-t border-slate-200 text-xs text-slate-500">
                            Disk space saved: {settings.storageMode === 'space_saver'
                                ? (diskSpaceSaved > 0
                                    ? `${(diskSpaceSaved / 1024 / 1024).toFixed(2)} MB saved so far`
                                    : 'No space saved yet')
                                : 'None (keeping originals)'}
                        </div>
                    </div>
                </EnhancedFeatureCard>
            </div>

            {/* Queue Stats Section */}
            <div className="mt-10">
                <QueueStats />
            </div>
        </div>
    );
};
