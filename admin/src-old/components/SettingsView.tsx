import React, { useState, useEffect } from 'react';
import { Dashicon, Spinner } from '@wordpress/components';
import { EnhancedFeatureCard } from './EnhancedFeatureCard';
import { ExclusionSettings } from './ExclusionSettings';
import { CacheExclusionSettings } from './CacheExclusionSettings';
import { PreloadTab } from './PreloadTab';
import { HeartbeatSettings } from './HeartbeatSettings';
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
    // Exclusions
    exclude_css: string[];
    exclude_js: string[];
    exclude_css_files: string[];
    exclude_js_files: string[];
    // Cache Exclusions
    cache_exclusions: {
        urls: string[];
        cookies: string[];
        user_agents: string[];
    };
    // Database
    cleanup_revisions: boolean;
    cleanup_spam: boolean;
    cleanup_trash: boolean;
    optimize_tables: boolean;
    // Fonts
    preload_fonts: string[];
    display_swap: boolean;
    // Resource Hints
    dns_prefetch: string[];
    preconnect: string[];
    preload_images: string[];
    // Heartbeat
    heartbeat_control: {
        enabled: boolean;
        locations: {
            dashboard: number;
            post_edit: number;
            frontend: number;
        };
    };
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
        exclude_css: [],
        exclude_js: [],
        exclude_css_files: [],
        exclude_js_files: [],
        cache_exclusions: {
            urls: [],
            cookies: [],
            user_agents: [],
        },
        cleanup_revisions: false,
        cleanup_spam: false,
        cleanup_trash: false,
        optimize_tables: false,
        preload_fonts: [],
        display_swap: false,
        dns_prefetch: [],
        preconnect: [],
        preload_images: [],
        heartbeat_control: {
            enabled: false,
            locations: {
                dashboard: 60,
                post_edit: 15,
                frontend: 60,
            },
        },
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
                    const fileOptimisation = data.data.settings.file_optimisation || {};

                    setSettings({
                        caching: cacheSettings.page_cache_enabled,
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
                        // Exclusions
                        exclude_css: minificationSettings.exclude_css || [],
                        exclude_js: minificationSettings.exclude_js || [],
                        exclude_css_files: fileOptimisation.exclude_css_files || minificationSettings.exclude_css_files || [],
                        exclude_js_files: fileOptimisation.exclude_js_files || minificationSettings.exclude_js_files || [],
                        // Cache Exclusions
                        cache_exclusions: {
                            urls: cacheSettings.cache_exclusions?.urls || [],
                            cookies: cacheSettings.cache_exclusions?.cookies || [],
                            user_agents: cacheSettings.cache_exclusions?.user_agents || [],
                        },
                        // Database
                        cleanup_revisions: data.data.settings.database?.cleanup_revisions || false,
                        cleanup_spam: data.data.settings.database?.cleanup_spam || false,
                        cleanup_trash: data.data.settings.database?.cleanup_trash || false,
                        optimize_tables: data.data.settings.database?.optimize_tables || false,
                        // Fonts
                        preload_fonts: data.data.settings.preloading?.preload_fonts || [],
                        display_swap: data.data.settings.fonts?.display_swap || false,
                        // Resource Hints
                        dns_prefetch: data.data.settings.preloading?.dns_prefetch || [],
                        preconnect: data.data.settings.preloading?.preconnect || [],
                        preload_images: data.data.settings.preloading?.preload_images || [],
                        // Heartbeat
                        heartbeat_control: {
                            enabled: data.data.settings.heartbeat_control?.enabled || false,
                            locations: {
                                dashboard: data.data.settings.heartbeat_control?.locations?.dashboard || 60,
                                post_edit: data.data.settings.heartbeat_control?.locations?.post_edit || 15,
                                frontend: data.data.settings.heartbeat_control?.locations?.frontend || 60,
                            },
                        },
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

    const handleToggle = async (key: keyof Settings, value: boolean | string | string[]) => {
        // Store the previous state for rollback if needed
        const previousSettings = { ...settings };

        setSaving(true);

        // Update state optimistically
        let newState = { ...settings, [key]: value };

        // Handle special cases where one toggle affects multiple settings
        if (key === 'code') {
            newState = {
                ...newState,
                minify_css: value as boolean,
                minify_js: value as boolean,
                minify_html: value as boolean
            };
        }

        setSettings(newState);

        // Construct the full settings object based on current state + new value
        const currentSettings = newState;
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
                exclude_css: currentSettings.exclude_css,
                exclude_js: currentSettings.exclude_js,
                exclude_css_files: currentSettings.exclude_css_files,
                exclude_js_files: currentSettings.exclude_js_files,
            };
        } else if (key === 'storageMode') {
            newSettings.images = { storage_mode: value };
        } else {
            // Handle granular updates
            if (['minify_css', 'minify_js', 'minify_html'].includes(key)) {
                newSettings.minification = {
                    minify_css: key === 'minify_css' ? value : currentSettings.minify_css,
                    minify_js: key === 'minify_js' ? value : currentSettings.minify_js,
                    minify_html: key === 'minify_html' ? value : currentSettings.minify_html,
                    exclude_css: currentSettings.exclude_css,
                    exclude_js: currentSettings.exclude_js,
                    exclude_css_files: currentSettings.exclude_css_files,
                    exclude_js_files: currentSettings.exclude_js_files,
                };
                // Update the main code toggle based on granular settings
                const anyMinificationEnabled =
                    (key === 'minify_css' ? value : currentSettings.minify_css) ||
                    (key === 'minify_js' ? value : currentSettings.minify_js) ||
                    (key === 'minify_html' ? value : currentSettings.minify_html);
                setSettings(prev => ({ ...prev, code: anyMinificationEnabled as boolean }));
            } else if (['defer_js', 'delay_js'].includes(key)) {
                newSettings.advanced = {
                    defer_js: key === 'defer_js' ? value : currentSettings.defer_js,
                    delay_js: key === 'delay_js' ? value : currentSettings.delay_js,
                };
            } else if (key === 'lazy_load') {
                newSettings.image_optimization = {
                    lazy_load_enabled: value,
                    auto_convert_on_upload: currentSettings.images,
                    webp_conversion: currentSettings.images,
                };
            } else if (['exclude_css', 'exclude_js', 'exclude_css_files', 'exclude_js_files'].includes(key)) {
                newSettings.minification = {
                    minify_css: currentSettings.minify_css,
                    minify_js: currentSettings.minify_js,
                    minify_html: currentSettings.minify_html,
                    exclude_css: key === 'exclude_css' ? value : currentSettings.exclude_css,
                    exclude_js: key === 'exclude_js' ? value : currentSettings.exclude_js,
                    exclude_css_files: key === 'exclude_css_files' ? value : currentSettings.exclude_css_files,
                    exclude_js_files: key === 'exclude_js_files' ? value : currentSettings.exclude_js_files,
                };
            } else if (key === 'cache_exclusions') {
                newSettings.cache_settings = {
                    page_cache_enabled: currentSettings.caching,
                    browser_cache_enabled: currentSettings.caching,
                    cache_preload_enabled: currentSettings.caching,
                    cache_compression: currentSettings.caching,
                    cache_exclusions: value,
                };
            } else if (['cleanup_revisions', 'cleanup_spam', 'cleanup_trash', 'optimize_tables'].includes(key)) {
                newSettings.database = {
                    cleanup_revisions: key === 'cleanup_revisions' ? value : currentSettings.cleanup_revisions,
                    cleanup_spam: key === 'cleanup_spam' ? value : currentSettings.cleanup_spam,
                    cleanup_trash: key === 'cleanup_trash' ? value : currentSettings.cleanup_trash,
                    optimize_tables: key === 'optimize_tables' ? value : currentSettings.optimize_tables,
                };
            } else if (key === 'display_swap') {
                newSettings.fonts = {
                    display_swap: value,
                };
            } else if (key === 'preload_fonts') {
                newSettings.preloading = {
                    preload_fonts: value,
                    dns_prefetch: currentSettings.dns_prefetch,
                    preconnect: currentSettings.preconnect,
                    preload_images: currentSettings.preload_images,
                    preload_critical_css: false,
                };
            } else if (['dns_prefetch', 'preconnect', 'preload_images'].includes(key)) {
                newSettings.preloading = {
                    preload_fonts: currentSettings.preload_fonts,
                    dns_prefetch: key === 'dns_prefetch' ? value : currentSettings.dns_prefetch,
                    preconnect: key === 'preconnect' ? value : currentSettings.preconnect,
                    preload_images: key === 'preload_images' ? value : currentSettings.preload_images,
                    preload_critical_css: false,
                };
            } else if (key === 'heartbeat_control') {
                newSettings.heartbeat_control = value;
            }
        }

        // Debug: Log what we're sending
        console.log('Saving settings:', { key, value, newSettings });

        // Validate that we have settings to save
        if (Object.keys(newSettings).length === 0) {
            console.warn(`No API settings mapping found for key: ${key}. Skipping API call.`);
            setSaving(false);
            return;
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
            console.log('API Response:', data);

            if (data.success) {
                setNotification({ type: 'success', message: 'Settings updated!' });
                setTimeout(() => setNotification(null), 3000);
            } else {
                // Rollback state on failure
                setSettings(previousSettings);
                const errorMsg = data.message || data.data?.message || 'Failed to save settings';
                setNotification({ type: 'error', message: errorMsg });
                setTimeout(() => setNotification(null), 5000);
                console.error('Settings save failed:', data);
            }
        } catch (error) {
            // Rollback state on error
            setSettings(previousSettings);
            setNotification({ type: 'error', message: 'Error saving settings' });
            setTimeout(() => setNotification(null), 5000);
            console.error('Settings save error:', error);
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return <div className="flex justify-center items-center p-12"><Spinner /></div>;
    }

    const activeFeatures = [
        settings.caching,
        settings.images,
        settings.code,
        settings.minify_css,
        settings.minify_js,
        settings.minify_html,
        settings.defer_js,
        settings.delay_js,
        settings.lazy_load,
        settings.cleanup_revisions,
        settings.cleanup_spam,
        settings.cleanup_trash,
        settings.optimize_tables,
        settings.display_swap,
        settings.heartbeat_control.enabled
    ].filter(Boolean).length;

    return (
        <div className="max-w-7xl mx-auto space-y-8 pb-12">
            {/* Notification */}
            {notification && (
                <div className={`fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 animate-fade-in-up flex items-center gap-3 ${notification.type === 'success' ? 'bg-emerald-500 text-white' : 'bg-red-500 text-white'
                    }`}>
                    {/* @ts-ignore */}
                    <Dashicon icon={notification.type === 'success' ? 'yes' : 'no'} />
                    <span className="font-medium">{notification.message}</span>
                </div>
            )}

            {/* Hero Status Section */}
            <div className="bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 rounded-3xl p-8 text-white shadow-2xl relative overflow-hidden">
                {/* Background decorations */}
                <div className="absolute top-0 right-0 w-96 h-96 bg-blue-500 rounded-full mix-blend-overlay filter blur-3xl opacity-20 -mr-32 -mt-32"></div>
                <div className="absolute bottom-0 left-0 w-96 h-96 bg-purple-500 rounded-full mix-blend-overlay filter blur-3xl opacity-10 -ml-32 -mb-32"></div>

                <div className="relative z-10">
                    <div className="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-8 mb-8">
                        <div className="flex-1">
                            <h1 className="text-4xl font-bold mb-3 text-white">Performance Optimization</h1>
                            <p className="text-slate-300 text-lg leading-relaxed max-w-2xl">
                                Configure your optimization settings below. Enable features with a simple toggle, or expand advanced options for fine-tuning.
                            </p>
                        </div>

                        <div className="bg-white/10 backdrop-blur-md p-6 rounded-2xl border border-white/20 shadow-xl">
                            <div className="text-center">
                                <div className="text-5xl font-bold text-emerald-400 mb-2">
                                    {activeFeatures}<span className="text-2xl text-slate-400">/11</span>
                                </div>
                                <div className="text-sm text-slate-300 uppercase tracking-wider font-semibold">Features Active</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Feature Cards Grid */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
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

                {/* Cache Exclusions Card */}
                {settings.caching && (
                    <EnhancedFeatureCard
                        icon="hidden"
                        title="Cache Exclusions"
                        description="Exclude specific URLs, cookies, or user agents from being cached."
                        enabled={true}
                        onToggle={() => { }}
                        color="blue"
                        disabled={saving}
                    >
                        <CacheExclusionSettings
                            settings={settings.cache_exclusions}
                            onChange={(key, value) => {
                                const newExclusions = { ...settings.cache_exclusions, [key]: value };
                                handleToggle('cache_exclusions', newExclusions as any);
                            }}
                            disabled={saving}
                        />
                    </EnhancedFeatureCard>
                )}

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
                                <div className={`relative w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-purple-300 rounded-full peer transition-colors duration-200 ${settings.lazy_load ? 'bg-purple-600' : ''}`}>
                                    <div className={`absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-4 w-4 transition-transform duration-200 ${settings.lazy_load ? 'translate-x-4' : 'translate-x-0'}`}></div>
                                </div>
                            </label>
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
                                <div className={`relative w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer transition-colors duration-200 ${settings.minify_css ? 'bg-emerald-600' : ''}`}>
                                    <div className={`absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-4 w-4 transition-transform duration-200 ${settings.minify_css ? 'translate-x-4' : 'translate-x-0'}`}></div>
                                </div>
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
                                <div className={`relative w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer transition-colors duration-200 ${settings.minify_js ? 'bg-emerald-600' : ''}`}>
                                    <div className={`absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-4 w-4 transition-transform duration-200 ${settings.minify_js ? 'translate-x-4' : 'translate-x-0'}`}></div>
                                </div>
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
                                <div className={`relative w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer transition-colors duration-200 ${settings.minify_html ? 'bg-emerald-600' : ''}`}>
                                    <div className={`absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-4 w-4 transition-transform duration-200 ${settings.minify_html ? 'translate-x-4' : 'translate-x-0'}`}></div>
                                </div>
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
                    onToggle={async (enabled) => {
                        await handleToggle('defer_js', enabled);
                        if (!enabled) {
                            await handleToggle('delay_js', false);
                        }
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
                                <div className={`relative w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer transition-colors duration-200 ${settings.defer_js ? 'bg-indigo-600' : ''}`}>
                                    <div className={`absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-4 w-4 transition-transform duration-200 ${settings.defer_js ? 'translate-x-4' : 'translate-x-0'}`}></div>
                                </div>
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
                                <div className={`relative w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer transition-colors duration-200 ${settings.delay_js ? 'bg-indigo-600' : ''}`}>
                                    <div className={`absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-4 w-4 transition-transform duration-200 ${settings.delay_js ? 'translate-x-4' : 'translate-x-0'}`}></div>
                                </div>
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

                {/* Database Optimization Card */}
                <EnhancedFeatureCard
                    icon="database-view"
                    title="Database Optimization"
                    description="Clean up revisions, spam, and trash to reduce database size and improve query performance."
                    enabled={settings.cleanup_revisions || settings.cleanup_spam || settings.cleanup_trash || settings.optimize_tables}
                    onToggle={async (enabled) => {
                        await Promise.all([
                            handleToggle('cleanup_revisions', enabled),
                            handleToggle('cleanup_spam', enabled),
                            handleToggle('cleanup_trash', enabled),
                            handleToggle('optimize_tables', enabled)
                        ]);
                    }}
                    color="pink"
                    disabled={saving}
                >
                    <div className="space-y-3 text-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Cleanup Revisions</span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.cleanup_revisions}
                                    onChange={(e) => handleToggle('cleanup_revisions', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className={`relative w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-pink-300 rounded-full peer transition-colors duration-200 ${settings.cleanup_revisions ? 'bg-pink-600' : ''}`}>
                                    <div className={`absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-4 w-4 transition-transform duration-200 ${settings.cleanup_revisions ? 'translate-x-4' : 'translate-x-0'}`}></div>
                                </div>
                            </label>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Cleanup Spam Comments</span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.cleanup_spam}
                                    onChange={(e) => handleToggle('cleanup_spam', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className={`relative w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-pink-300 rounded-full peer transition-colors duration-200 ${settings.cleanup_spam ? 'bg-pink-600' : ''}`}>
                                    <div className={`absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-4 w-4 transition-transform duration-200 ${settings.cleanup_spam ? 'translate-x-4' : 'translate-x-0'}`}></div>
                                </div>
                            </label>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Cleanup Trash</span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.cleanup_trash}
                                    onChange={(e) => handleToggle('cleanup_trash', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className={`relative w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-pink-300 rounded-full peer transition-colors duration-200 ${settings.cleanup_trash ? 'bg-pink-600' : ''}`}>
                                    <div className={`absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-4 w-4 transition-transform duration-200 ${settings.cleanup_trash ? 'translate-x-4' : 'translate-x-0'}`}></div>
                                </div>
                            </label>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-slate-700">Optimize Tables</span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.optimize_tables}
                                    onChange={(e) => handleToggle('optimize_tables', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className={`relative w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-pink-300 rounded-full peer transition-colors duration-200 ${settings.optimize_tables ? 'bg-pink-600' : ''}`}>
                                    <div className={`absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-4 w-4 transition-transform duration-200 ${settings.optimize_tables ? 'translate-x-4' : 'translate-x-0'}`}></div>
                                </div>
                            </label>
                        </div>
                    </div>
                </EnhancedFeatureCard>

                {/* Heartbeat Control Card */}
                <div className="col-span-1 lg:col-span-2">
                    <EnhancedFeatureCard
                        icon="heart"
                        title="Heartbeat Control"
                        description="Limit or disable the WordPress Heartbeat API to reduce server load."
                        enabled={settings.heartbeat_control.enabled}
                        onToggle={(enabled) => handleToggle('heartbeat_control', { ...settings.heartbeat_control, enabled })}
                        color="red"
                        disabled={saving}
                    >
                        <HeartbeatSettings
                            settings={settings.heartbeat_control}
                            onChange={(key, value) => {
                                const newHeartbeat = { ...settings.heartbeat_control, [key]: value };
                                handleToggle('heartbeat_control', newHeartbeat);
                            }}
                            disabled={saving}
                        />
                    </EnhancedFeatureCard>
                </div>

                {/* Preload & Resource Hints */}
                <div className="col-span-1 lg:col-span-2">
                    <EnhancedFeatureCard
                        icon="networking"
                        title="Preload & Resource Hints"
                        description="Optimize resource loading with font preloading, DNS prefetch, and preconnect."
                        enabled={settings.preload_fonts.length > 0 || settings.dns_prefetch.length > 0}
                        onToggle={() => { }}
                        color="indigo"
                        disabled={saving}
                    >
                        <PreloadTab
                            settings={{
                                preload_fonts: settings.preload_fonts,
                                preload_images: settings.preload_images,
                                dns_prefetch: settings.dns_prefetch,
                                preconnect: settings.preconnect,
                                display_swap: settings.display_swap,
                            }}
                            onChange={(key, value) => handleToggle(key as keyof Settings, value)}
                            disabled={saving}
                        />
                    </EnhancedFeatureCard>
                </div>

                {/* Exclusion Settings Card */}
                <div className="col-span-1 lg:col-span-2">
                    <EnhancedFeatureCard
                        icon="dismiss"
                        title="Exclusion Rules"
                        description="Exclude specific CSS/JS files or handles from optimization. Supports wildcards (*) and regex."
                        enabled={true}
                        onToggle={() => { }}
                        color="red"
                        disabled={saving}
                    >
                        <ExclusionSettings
                            settings={{
                                exclude_css: settings.exclude_css,
                                exclude_js: settings.exclude_js,
                                exclude_css_files: settings.exclude_css_files,
                                exclude_js_files: settings.exclude_js_files,
                            }}
                            onChange={(key, value) => handleToggle(key as keyof Settings, value)}
                            disabled={saving}
                        />
                    </EnhancedFeatureCard>
                </div>
            </div>

            {/* Queue Stats Section */}
            <div className="mt-8">
                <QueueStats />
            </div>
        </div>
    );
};
