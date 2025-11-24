import React, { useState, useEffect } from 'react';
import { Dashicon, Spinner } from '@wordpress/components';

interface SimpleSettings {
    caching: boolean;
    images: boolean;
    code: boolean;
}

export const SettingsView: React.FC = () => {
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [settings, setSettings] = useState<SimpleSettings>({
        caching: false,
        images: false,
        code: false,
    });
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
                    // Map complex settings to simple toggles
                    const cacheSettings = data.data.settings.cache_settings || {};
                    const imageSettings = data.data.settings.image_optimization || {};
                    const optSettings = data.data.settings.optimization || {};

                    setSettings({
                        caching: cacheSettings.page_cache_enabled && cacheSettings.browser_cache_enabled,
                        images: imageSettings.auto_convert_on_upload && imageSettings.lazy_load_enabled,
                        code: optSettings.minify_css && optSettings.minify_js,
                    });
                }
            }
        } catch (error) {
            console.error('Failed to fetch settings:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleToggle = async (key: keyof SimpleSettings, value: boolean) => {
        setSettings(prev => ({ ...prev, [key]: value }));
        setSaving(true);

        // Prepare complex settings based on simple toggles
        const newSettings: any = {};

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
                lazy_load_enabled: value,
            };
        } else if (key === 'code') {
            newSettings.optimization = {
                minify_css: value,
                minify_js: value,
                minify_html: value,
            };
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
                setNotification({ type: 'success', message: 'Optimization settings updated!' });
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
        <div className="max-w-6xl mx-auto space-y-10 pb-12">
            {/* Hero Status Section */}
            <div className="bg-gradient-to-r from-slate-900 to-slate-800 rounded-2xl p-8 text-white shadow-xl relative overflow-hidden">
                <div className="absolute top-0 right-0 w-64 h-64 bg-blue-500 rounded-full mix-blend-overlay filter blur-3xl opacity-20 -mr-16 -mt-16"></div>
                <div className="relative z-10 flex flex-col md:flex-row items-center justify-between gap-8">
                    <div className="text-center md:text-left">
                        <h2 className="text-3xl font-bold mb-2">System Status</h2>
                        <p className="text-slate-300 text-lg">
                            Your site is currently running on <span className="text-emerald-400 font-semibold">Simple Mode</span>.
                            <br />
                            Configure the essentials below for maximum performance.
                        </p>
                    </div>
                    <div className="flex items-center gap-8 bg-slate-800/50 p-6 rounded-xl border border-slate-700 backdrop-blur-sm">
                        <div className="text-center">
                            <div className="text-3xl font-bold text-emerald-400 mb-1">98%</div>
                            <div className="text-xs text-slate-400 uppercase tracking-wider font-semibold">Health</div>
                        </div>
                        <div className="w-px h-12 bg-slate-700"></div>
                        <div className="text-center">
                            <div className="text-3xl font-bold text-blue-400 mb-1">
                                {Object.values(settings).filter(Boolean).length}/3
                            </div>
                            <div className="text-xs text-slate-400 uppercase tracking-wider font-semibold">Active</div>
                        </div>
                    </div>
                </div>
            </div>

            {notification && (
                <div className={`fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 animate-fade-in-up flex items-center gap-3 ${notification.type === 'success' ? 'bg-emerald-500 text-white' : 'bg-red-500 text-white'
                    }`}>
                    {/* @ts-ignore */}
                    <Dashicon icon={notification.type === 'success' ? 'yes' : 'no'} />
                    <span className="font-medium">{notification.message}</span>
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                {/* Caching Card */}
                <div className={`group relative overflow-hidden rounded-2xl transition-all duration-300 ${settings.caching
                        ? 'bg-white ring-2 ring-blue-500 shadow-blue-500/20 shadow-xl'
                        : 'bg-white border border-slate-200 hover:border-blue-300 hover:shadow-lg'
                    }`}>
                    <div className="p-8">
                        <div className={`w-16 h-16 rounded-2xl flex items-center justify-center mb-6 transition-all duration-300 ${settings.caching
                                ? 'bg-blue-500 text-white shadow-lg shadow-blue-500/30 scale-110'
                                : 'bg-slate-100 text-slate-400 group-hover:bg-blue-50 group-hover:text-blue-500'
                            }`}>
                            {/* @ts-ignore */}
                            <Dashicon icon="database" style={{ fontSize: '32px', width: '32px', height: '32px' }} />
                        </div>
                        <h3 className="text-xl font-bold text-slate-900 mb-3">Caching System</h3>
                        <p className="text-slate-600 mb-8 min-h-[3rem] leading-relaxed">
                            Accelerate page loads by serving static HTML copies instead of processing PHP for every visit.
                        </p>

                        <div className="flex items-center justify-between pt-6 border-t border-slate-100">
                            <span className={`text-sm font-semibold transition-colors ${settings.caching ? 'text-blue-600' : 'text-slate-400'
                                }`}>
                                {settings.caching ? 'Active' : 'Inactive'}
                            </span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.caching}
                                    onChange={(e) => handleToggle('caching', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className="w-14 h-8 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-100 rounded-full peer peer-checked:after:translate-x-6 peer-checked:after:border-white after:content-[''] after:absolute after:top-1 after:left-1 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-500"></div>
                            </label>
                        </div>
                    </div>
                </div>

                {/* Images Card */}
                <div className={`group relative overflow-hidden rounded-2xl transition-all duration-300 ${settings.images
                        ? 'bg-white ring-2 ring-purple-500 shadow-purple-500/20 shadow-xl'
                        : 'bg-white border border-slate-200 hover:border-purple-300 hover:shadow-lg'
                    }`}>
                    <div className="p-8">
                        <div className={`w-16 h-16 rounded-2xl flex items-center justify-center mb-6 transition-all duration-300 ${settings.images
                                ? 'bg-purple-500 text-white shadow-lg shadow-purple-500/30 scale-110'
                                : 'bg-slate-100 text-slate-400 group-hover:bg-purple-50 group-hover:text-purple-500'
                            }`}>
                            {/* @ts-ignore */}
                            <Dashicon icon="format-image" style={{ fontSize: '32px', width: '32px', height: '32px' }} />
                        </div>
                        <h3 className="text-xl font-bold text-slate-900 mb-3">Image Optimization</h3>
                        <p className="text-slate-600 mb-8 min-h-[3rem] leading-relaxed">
                            Automatically compress uploads and lazy load images to save bandwidth and speed up rendering.
                        </p>

                        <div className="flex items-center justify-between pt-6 border-t border-slate-100">
                            <span className={`text-sm font-semibold transition-colors ${settings.images ? 'text-purple-600' : 'text-slate-400'
                                }`}>
                                {settings.images ? 'Active' : 'Inactive'}
                            </span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.images}
                                    onChange={(e) => handleToggle('images', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className="w-14 h-8 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-100 rounded-full peer peer-checked:after:translate-x-6 peer-checked:after:border-white after:content-[''] after:absolute after:top-1 after:left-1 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-purple-500"></div>
                            </label>
                        </div>
                    </div>
                </div>

                {/* Code Card */}
                <div className={`group relative overflow-hidden rounded-2xl transition-all duration-300 ${settings.code
                        ? 'bg-white ring-2 ring-emerald-500 shadow-emerald-500/20 shadow-xl'
                        : 'bg-white border border-slate-200 hover:border-emerald-300 hover:shadow-lg'
                    }`}>
                    <div className="p-8">
                        <div className={`w-16 h-16 rounded-2xl flex items-center justify-center mb-6 transition-all duration-300 ${settings.code
                                ? 'bg-emerald-500 text-white shadow-lg shadow-emerald-500/30 scale-110'
                                : 'bg-slate-100 text-slate-400 group-hover:bg-emerald-50 group-hover:text-emerald-500'
                            }`}>
                            {/* @ts-ignore */}
                            <Dashicon icon="editor-code" style={{ fontSize: '32px', width: '32px', height: '32px' }} />
                        </div>
                        <h3 className="text-xl font-bold text-slate-900 mb-3">Code Minification</h3>
                        <p className="text-slate-600 mb-8 min-h-[3rem] leading-relaxed">
                            Minify CSS and JavaScript files to reduce payload size and improve parsing speed.
                        </p>

                        <div className="flex items-center justify-between pt-6 border-t border-slate-100">
                            <span className={`text-sm font-semibold transition-colors ${settings.code ? 'text-emerald-600' : 'text-slate-400'
                                }`}>
                                {settings.code ? 'Active' : 'Inactive'}
                            </span>
                            <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings.code}
                                    onChange={(e) => handleToggle('code', e.target.checked)}
                                    disabled={saving}
                                />
                                <div className="w-14 h-8 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-emerald-100 rounded-full peer peer-checked:after:translate-x-6 peer-checked:after:border-white after:content-[''] after:absolute after:top-1 after:left-1 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-emerald-500"></div>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div className="flex justify-center pt-8">
                <div className="inline-flex items-center gap-2 px-4 py-2 bg-slate-100 text-slate-600 rounded-lg text-sm">
                    <span className="w-2 h-2 rounded-full bg-slate-400"></span>
                    Need granular control? Switch to <span className="font-semibold text-slate-900">Advanced Mode</span> above.
                </div>
            </div>
        </div>
    );
};
