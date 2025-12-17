import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { 
    faDatabase, faImages, faCode, faRocket, faHdd, faHeart, 
    faTrashAlt, faFont, faCheckCircle, faExclamationTriangle,
    faCircleCheck, faCircleExclamation, faCircleXmark,
    faBroom, faMagicWandSparkles, faLightbulb, faArrowRight
} from '@fortawesome/free-solid-svg-icons';
import { FeatureCard } from '../components/FeatureCard';
import { ToggleSwitch } from '../components/ToggleSwitch';

interface Settings {
    caching: boolean;
    images: boolean;
    code: boolean;
    storageMode: 'safe' | 'space_saver';
    minify_css: boolean;
    minify_js: boolean;
    minify_html: boolean;
    defer_js: boolean;
    delay_js: boolean;
    lazy_load: boolean;
    cleanup_revisions: boolean;
    cleanup_spam: boolean;
    cleanup_trash: boolean;
    optimize_tables: boolean;
    display_swap: boolean;
    heartbeat_enabled: boolean;
}

// Core Web Vitals metrics (mock data)
const metrics = [
    { label: 'LCP', fullName: 'Largest Contentful Paint', val: '2.4s', status: 'good', score: 92 },
    { label: 'INP', fullName: 'Interaction to Next Paint', val: '180ms', status: 'needs-improvement', score: 74 },
    { label: 'CLS', fullName: 'Cumulative Layout Shift', val: '0.02', status: 'good', score: 98 },
    { label: 'TBT', fullName: 'Total Blocking Time', val: '120ms', status: 'good', score: 95 },
];

const getStatusStyles = (status: string) => {
    switch (status) {
        case 'good': 
            return { 
                bg: 'bg-emerald-100', 
                darkBg: 'dark:bg-emerald-900/30', 
                text: 'text-emerald-600', 
                darkText: 'dark:text-emerald-400', 
                bar: 'bg-emerald-500', 
                icon: faCircleCheck,
                label: 'Good'
            };
        case 'needs-improvement': 
            return { 
                bg: 'bg-amber-100', 
                darkBg: 'dark:bg-amber-900/30', 
                text: 'text-amber-600', 
                darkText: 'dark:text-amber-400', 
                bar: 'bg-amber-500', 
                icon: faCircleExclamation,
                label: 'Needs Improvement'
            };
        case 'poor': 
            return { 
                bg: 'bg-red-100', 
                darkBg: 'dark:bg-red-900/30', 
                text: 'text-red-600', 
                darkText: 'dark:text-red-400', 
                bar: 'bg-red-500', 
                icon: faCircleXmark,
                label: 'Poor'
            };
        default: 
            return { 
                bg: 'bg-gray-100', 
                darkBg: 'dark:bg-gray-700', 
                text: 'text-gray-600', 
                darkText: 'dark:text-gray-400', 
                bar: 'bg-gray-400', 
                icon: faCircleCheck,
                label: 'Unknown'
            };
    }
};

const Dashboard = () => {
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [notification, setNotification] = useState<{ type: 'success' | 'error'; message: string } | null>(null);
    const [activeView, setActiveView] = useState<'metrics' | 'settings'>('metrics');
    const [settings, setSettings] = useState<Settings>({
        caching: false, images: false, code: false, storageMode: 'safe',
        minify_css: false, minify_js: false, minify_html: false,
        defer_js: false, delay_js: false, lazy_load: false,
        cleanup_revisions: false, cleanup_spam: false, cleanup_trash: false,
        optimize_tables: false, display_swap: false, heartbeat_enabled: false,
    });

    useEffect(() => { fetchSettings(); }, []);

    const fetchSettings = async () => {
        try {
            const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
            const response = await fetch(`${apiUrl}/settings`, {
                headers: { 'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '' },
            });
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.data.settings) {
                    const s = data.data.settings;
                    const cache = s.cache_settings || {};
                    const img = s.image_optimization || {};
                    const minify = s.minification || {};
                    const adv = s.advanced || {};
                    const db = s.database || {};
                    setSettings({
                        caching: cache.page_cache_enabled || false,
                        images: img.auto_convert_on_upload || false,
                        code: minify.minify_css || minify.minify_js || minify.minify_html || false,
                        storageMode: img.storage_mode || s.images?.storage_mode || 'safe',
                        minify_css: minify.minify_css || false,
                        minify_js: minify.minify_js || false,
                        minify_html: minify.minify_html || false,
                        defer_js: adv.defer_js || false,
                        delay_js: adv.delay_js || false,
                        lazy_load: img.lazy_load_enabled || false,
                        cleanup_revisions: db.cleanup_revisions || false,
                        cleanup_spam: db.cleanup_spam || false,
                        cleanup_trash: db.cleanup_trash || false,
                        optimize_tables: db.optimize_tables || false,
                        display_swap: s.fonts?.display_swap || false,
                        heartbeat_enabled: s.heartbeat_control?.enabled || false,
                    });
                }
            }
        } catch (error) { console.error('Failed to fetch settings:', error); }
        finally { setLoading(false); }
    };

    const handleToggle = async (key: keyof Settings, value: boolean | string) => {
        const previousSettings = { ...settings };
        setSaving(true);
        let newState = { ...settings, [key]: value };
        if (key === 'code') {
            newState = { ...newState, minify_css: value as boolean, minify_js: value as boolean, minify_html: value as boolean };
        }
        setSettings(newState);

        const newSettings: any = {};
        if (key === 'caching') newSettings.cache_settings = { page_cache_enabled: value, browser_cache_enabled: value };
        else if (key === 'images') newSettings.image_optimization = { auto_convert_on_upload: value, webp_conversion: value, lazy_load_enabled: newState.lazy_load };
        else if (key === 'code') newSettings.minification = { minify_css: value, minify_js: value, minify_html: value };
        else if (key === 'storageMode') newSettings.images = { storage_mode: value };
        else if (['minify_css', 'minify_js', 'minify_html'].includes(key)) newSettings.minification = { minify_css: newState.minify_css, minify_js: newState.minify_js, minify_html: newState.minify_html };
        else if (['defer_js', 'delay_js'].includes(key)) newSettings.advanced = { defer_js: newState.defer_js, delay_js: newState.delay_js };
        else if (key === 'lazy_load') newSettings.image_optimization = { lazy_load_enabled: value, auto_convert_on_upload: newState.images, webp_conversion: newState.images };
        else if (['cleanup_revisions', 'cleanup_spam', 'cleanup_trash', 'optimize_tables'].includes(key)) newSettings.database = { cleanup_revisions: newState.cleanup_revisions, cleanup_spam: newState.cleanup_spam, cleanup_trash: newState.cleanup_trash, optimize_tables: newState.optimize_tables };
        else if (key === 'display_swap') newSettings.fonts = { display_swap: value };
        else if (key === 'heartbeat_enabled') newSettings.heartbeat_control = { enabled: value };

        if (Object.keys(newSettings).length === 0) { setSaving(false); return; }

        try {
            const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
            const response = await fetch(`${apiUrl}/settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '' },
                body: JSON.stringify({ settings: newSettings }),
            });
            const data = await response.json();
            if (data.success) showNotification('success', 'Settings updated!');
            else { setSettings(previousSettings); showNotification('error', data.message || 'Failed to save'); }
        } catch { setSettings(previousSettings); showNotification('error', 'Error saving settings'); }
        finally { setSaving(false); }
    };

    const showNotification = (type: 'success' | 'error', message: string) => {
        setNotification({ type, message });
        setTimeout(() => setNotification(null), 3000);
    };

    if (loading) {
        return (
            <div className="flex items-center justify-center h-96">
                <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
            </div>
        );
    }

    const activeCount = [settings.caching, settings.images, settings.code, settings.defer_js, settings.lazy_load, settings.heartbeat_enabled, settings.cleanup_revisions].filter(Boolean).length;

    return (
        <div className="p-6 lg:p-8 max-w-7xl mx-auto space-y-6">
            {/* Notification */}
            {notification && (
                <div className={`fixed top-4 right-4 z-50 px-4 py-3 rounded-lg shadow-lg flex items-center gap-2 ${notification.type === 'success' ? 'bg-emerald-500 text-white' : 'bg-red-500 text-white'}`}>
                    <FontAwesomeIcon icon={notification.type === 'success' ? faCheckCircle : faExclamationTriangle} />
                    <span className="font-medium">{notification.message}</span>
                </div>
            )}

            {/* Header with View Toggle */}
            <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 className="text-2xl lg:text-3xl font-bold text-gray-900 dark:text-white">
                        Performance Dashboard
                    </h1>
                    <p className="text-gray-700 dark:text-gray-200 mt-1">
                        Monitor performance and manage optimization settings
                    </p>
                </div>
                {/* View Toggle */}
                <div className="flex bg-white dark:bg-gray-800 rounded-lg p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                    <button
                        onClick={() => setActiveView('metrics')}
                        className={`px-4 py-2 text-sm font-medium rounded-md transition-all ${
                            activeView === 'metrics' 
                                ? 'bg-blue-600 text-white shadow-sm' 
                                : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'
                        }`}
                    >
                        Metrics
                    </button>
                    <button
                        onClick={() => setActiveView('settings')}
                        className={`px-4 py-2 text-sm font-medium rounded-md transition-all ${
                            activeView === 'settings' 
                                ? 'bg-blue-600 text-white shadow-sm' 
                                : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'
                        }`}
                    >
                        Settings
                    </button>
                </div>
            </div>

            {/* ============== METRICS VIEW ============== */}
            {activeView === 'metrics' && (
                <>
                    {/* KPI Cards */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        {metrics.map((m, i) => {
                            const styles = getStatusStyles(m.status);
                            return (
                                <div 
                                    key={i} 
                                    className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 hover:shadow-md transition-shadow"
                                >
                                    {/* Header with icon */}
                                    <div className="flex justify-between items-start mb-3">
                                        <div>
                                            <span className={`text-xs font-bold uppercase tracking-wide ${styles.text} ${styles.darkText}`}>
                                                {m.label}
                                            </span>
                                            <p className="text-xs text-gray-600 dark:text-gray-300 mt-0.5">
                                                {m.fullName}
                                            </p>
                                        </div>
                                        <div className={`p-2 rounded-lg ${styles.bg} ${styles.darkBg}`}>
                                            <FontAwesomeIcon icon={styles.icon} className={`${styles.text} ${styles.darkText}`} />
                                        </div>
                                    </div>
                                    
                                    {/* Value */}
                                    <p className="text-3xl font-bold text-gray-900 dark:text-white tabular-nums mb-3">
                                        {m.val}
                                    </p>
                                    
                                    {/* Progress bar */}
                                    <div className="space-y-1.5">
                                        <div className="w-full bg-gray-200 dark:bg-gray-700 h-2 rounded-full overflow-hidden">
                                            <div 
                                                className={`h-full rounded-full transition-all duration-500 ${styles.bar}`} 
                                                style={{ width: `${m.score}%` }}
                                            />
                                        </div>
                                        <div className="flex justify-between text-xs">
                                            <span className={`font-medium ${styles.text} ${styles.darkText}`}>
                                                {styles.label}
                                            </span>
                                            <span className="text-gray-600 dark:text-gray-300 tabular-nums">
                                                {m.score}/100
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {/* Chart + Quick Actions */}
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        {/* Chart */}
                        <div className="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                            <div className="flex items-center justify-between mb-6">
                                <div>
                                    <h2 className="text-lg font-bold text-gray-900 dark:text-white">
                                        Performance Score
                                    </h2>
                                    <p className="text-sm text-gray-600 dark:text-gray-300">
                                        Last 14 days trend
                                    </p>
                                </div>
                                <select className="bg-gray-100 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm px-3 py-2 text-gray-700 dark:text-white">
                                    <option>Last 14 Days</option>
                                    <option>Last 30 Days</option>
                                </select>
                            </div>
                            
                            {/* Chart area */}
                            <div className="h-64 flex items-end justify-between gap-1 px-2 pb-4 border-b border-l border-gray-200 dark:border-gray-700">
                                {[45, 50, 48, 55, 60, 65, 70, 72, 68, 75, 80, 85, 88, 92].map((h, i) => (
                                    <div key={i} className="w-full relative group cursor-pointer" style={{ height: '100%' }}>
                                        <div 
                                            className="bg-gradient-to-t from-blue-600 to-blue-400 rounded-t w-full absolute bottom-0 transition-all duration-300 group-hover:from-blue-500 group-hover:to-blue-300" 
                                            style={{ height: `${h}%` }}
                                        />
                                        <div className="absolute -top-8 left-1/2 -translate-x-1/2 bg-gray-900 text-white text-xs px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap z-10">
                                            Score: {h}
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="flex justify-between text-xs text-gray-600 dark:text-gray-300 mt-3 px-2">
                                <span>Nov 30</span><span>Dec 7</span><span>Dec 13</span>
                            </div>
                        </div>

                        {/* Quick Actions */}
                        <div className="space-y-4">
                            <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                                <h2 className="text-lg font-bold text-gray-900 dark:text-white mb-4">
                                    Quick Actions
                                </h2>
                                <div className="space-y-3">
                                    <button className="w-full text-left px-4 py-3 bg-gray-50 dark:bg-gray-700/50 hover:bg-blue-50 dark:hover:bg-blue-900/30 border border-gray-200 dark:border-gray-600 hover:border-blue-300 dark:hover:border-blue-700 rounded-xl transition-all group">
                                        <div className="flex items-center gap-3">
                                            <div className="w-10 h-10 bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400 rounded-lg flex items-center justify-center">
                                                <FontAwesomeIcon icon={faBroom} />
                                            </div>
                                            <div>
                                                <span className="font-semibold text-gray-900 dark:text-white block">Clear All Cache</span>
                                                <span className="text-xs text-gray-600 dark:text-gray-300">Purge page and object cache</span>
                                            </div>
                                        </div>
                                    </button>
                                    <button className="w-full text-left px-4 py-3 bg-gray-50 dark:bg-gray-700/50 hover:bg-purple-50 dark:hover:bg-purple-900/30 border border-gray-200 dark:border-gray-600 hover:border-purple-300 dark:hover:border-purple-700 rounded-xl transition-all group">
                                        <div className="flex items-center gap-3">
                                            <div className="w-10 h-10 bg-purple-100 dark:bg-purple-900/50 text-purple-600 dark:text-purple-400 rounded-lg flex items-center justify-center">
                                                <FontAwesomeIcon icon={faMagicWandSparkles} />
                                            </div>
                                            <div>
                                                <span className="font-semibold text-gray-900 dark:text-white block">Regenerate Critical CSS</span>
                                                <span className="text-xs text-gray-600 dark:text-gray-300">Optimize render-blocking resources</span>
                                            </div>
                                        </div>
                                    </button>
                                </div>
                            </div>

                            {/* Pro Tip */}
                            <div className="bg-gradient-to-br from-indigo-600 via-blue-600 to-blue-700 rounded-xl shadow-lg p-5 text-white relative overflow-hidden">
                                <div className="relative z-10">
                                    <div className="flex items-center gap-2 mb-2">
                                        <FontAwesomeIcon icon={faLightbulb} className="text-yellow-300" />
                                        <h3 className="font-bold">Pro Tip</h3>
                                    </div>
                                    <p className="text-blue-100 text-sm mb-3 leading-relaxed">
                                        Enable "Defer JS" to improve your INP score by up to 25%.
                                    </p>
                                    <button 
                                        onClick={() => setActiveView('settings')} 
                                        className="text-sm font-semibold bg-white/20 hover:bg-white/30 px-4 py-2 rounded-lg transition-colors flex items-center gap-2"
                                    >
                                        Configure <FontAwesomeIcon icon={faArrowRight} size="xs" />
                                    </button>
                                </div>
                                <div className="absolute -bottom-10 -right-10 w-32 h-32 bg-white/10 rounded-full blur-2xl" />
                            </div>
                        </div>
                    </div>
                </>
            )}

            {/* ============== SETTINGS VIEW ============== */}
            {activeView === 'settings' && (
                <>
                    {/* Hero Header */}
                    <div className="bg-gradient-to-br from-gray-800 via-gray-900 to-gray-800 rounded-2xl p-6 lg:p-8 text-white shadow-xl relative overflow-hidden">
                        <div className="absolute top-0 right-0 w-64 h-64 bg-blue-500 rounded-full mix-blend-overlay filter blur-3xl opacity-20 -mr-20 -mt-20" />
                        <div className="absolute bottom-0 left-0 w-64 h-64 bg-purple-500 rounded-full mix-blend-overlay filter blur-3xl opacity-10 -ml-20 -mb-20" />
                        <div className="relative z-10 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4">
                            <div>
                                <h2 className="text-xl lg:text-2xl font-bold mb-1">Optimization Settings</h2>
                                <p className="text-gray-300 text-sm lg:text-base">Enable features with a toggle or expand for advanced options.</p>
                            </div>
                            <div className="bg-white/10 backdrop-blur-md px-5 py-3 rounded-xl border border-white/20">
                                <div className="text-center">
                                    <div className="text-3xl font-bold text-emerald-400">{activeCount}<span className="text-lg text-gray-400">/7</span></div>
                                    <div className="text-xs text-gray-300 uppercase tracking-wider font-semibold mt-0.5">Features Active</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Feature Cards Grid */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6">
                        <FeatureCard icon={faDatabase} title="Caching System" description="Serve static HTML copies instead of processing PHP." enabled={settings.caching} onToggle={(v) => handleToggle('caching', v)} color="blue" disabled={saving}>
                            <div className="space-y-3 text-sm">
                                <div className="flex justify-between"><span>Page Cache</span><span className="text-blue-600 dark:text-blue-400 font-medium">Enabled</span></div>
                                <div className="flex justify-between"><span>Browser Cache</span><span className="text-blue-600 dark:text-blue-400 font-medium">Enabled</span></div>
                            </div>
                        </FeatureCard>

                        <FeatureCard icon={faImages} title="Image Optimization" description="Compress and convert to WebP/AVIF automatically." enabled={settings.images} onToggle={(v) => handleToggle('images', v)} color="purple" disabled={saving}>
                            <div className="space-y-3 text-sm">
                                <div className="flex justify-between items-center"><span>Lazy Loading</span><ToggleSwitch checked={settings.lazy_load} onChange={(v) => handleToggle('lazy_load', v)} color="purple" disabled={saving} /></div>
                            </div>
                        </FeatureCard>

                        <FeatureCard icon={faCode} title="Code Minification" description="Minify CSS, JS, and HTML to reduce payload." enabled={settings.code} onToggle={(v) => handleToggle('code', v)} color="emerald" disabled={saving}>
                            <div className="space-y-3 text-sm">
                                <div className="flex justify-between items-center"><span>CSS</span><ToggleSwitch checked={settings.minify_css} onChange={(v) => handleToggle('minify_css', v)} color="emerald" disabled={saving} /></div>
                                <div className="flex justify-between items-center"><span>JS</span><ToggleSwitch checked={settings.minify_js} onChange={(v) => handleToggle('minify_js', v)} color="emerald" disabled={saving} /></div>
                                <div className="flex justify-between items-center"><span>HTML</span><ToggleSwitch checked={settings.minify_html} onChange={(v) => handleToggle('minify_html', v)} color="emerald" disabled={saving} /></div>
                            </div>
                        </FeatureCard>

                        <FeatureCard icon={faRocket} title="Asset Optimization" description="Optimize script loading for better Core Web Vitals." enabled={settings.defer_js || settings.delay_js} onToggle={(v) => handleToggle('defer_js', v)} color="indigo" disabled={saving}>
                            <div className="space-y-3 text-sm">
                                <div className="flex justify-between items-center"><span>Defer JS</span><ToggleSwitch checked={settings.defer_js} onChange={(v) => handleToggle('defer_js', v)} color="indigo" disabled={saving} /></div>
                                <div className="flex justify-between items-center"><span>Delay JS</span><ToggleSwitch checked={settings.delay_js} onChange={(v) => handleToggle('delay_js', v)} color="indigo" disabled={saving} /></div>
                            </div>
                        </FeatureCard>

                        <FeatureCard icon={faHdd} title="Storage Mode" description="Choose to keep or delete original images." enabled={settings.storageMode === 'space_saver'} onToggle={(v) => handleToggle('storageMode', v ? 'space_saver' : 'safe')} color="orange" disabled={saving}>
                            <div className="space-y-2">
                                <label className="flex items-center gap-3 cursor-pointer p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <input type="radio" checked={settings.storageMode === 'safe'} onChange={() => handleToggle('storageMode', 'safe')} disabled={saving} />
                                    <div><div className="font-medium text-sm">Safe Mode</div><div className="text-xs text-gray-600 dark:text-gray-300">Keeps originals</div></div>
                                </label>
                                <label className="flex items-center gap-3 cursor-pointer p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <input type="radio" checked={settings.storageMode === 'space_saver'} onChange={() => handleToggle('storageMode', 'space_saver')} disabled={saving} />
                                    <div><div className="font-medium text-sm">Space Saver</div><div className="text-xs text-orange-600 dark:text-orange-400">Deletes originals</div></div>
                                </label>
                            </div>
                        </FeatureCard>

                        <FeatureCard icon={faTrashAlt} title="Database Optimization" description="Clean up revisions, spam, and trash." enabled={settings.cleanup_revisions || settings.cleanup_spam} onToggle={async (v) => { await handleToggle('cleanup_revisions', v); await handleToggle('cleanup_spam', v); }} color="pink" disabled={saving}>
                            <div className="space-y-3 text-sm">
                                <div className="flex justify-between items-center"><span>Revisions</span><ToggleSwitch checked={settings.cleanup_revisions} onChange={(v) => handleToggle('cleanup_revisions', v)} color="pink" disabled={saving} /></div>
                                <div className="flex justify-between items-center"><span>Spam</span><ToggleSwitch checked={settings.cleanup_spam} onChange={(v) => handleToggle('cleanup_spam', v)} color="pink" disabled={saving} /></div>
                                <div className="flex justify-between items-center"><span>Trash</span><ToggleSwitch checked={settings.cleanup_trash} onChange={(v) => handleToggle('cleanup_trash', v)} color="pink" disabled={saving} /></div>
                            </div>
                        </FeatureCard>

                        <div className="lg:col-span-2">
                            <FeatureCard icon={faHeart} title="Heartbeat Control" description="Limit or disable WordPress Heartbeat API to reduce server load." enabled={settings.heartbeat_enabled} onToggle={(v) => handleToggle('heartbeat_enabled', v)} color="red" disabled={saving} />
                        </div>

                        <div className="lg:col-span-2">
                            <FeatureCard icon={faFont} title="Font Optimization" description="Use font-display swap to prevent invisible text during load." enabled={settings.display_swap} onToggle={(v) => handleToggle('display_swap', v)} color="cyan" disabled={saving} />
                        </div>
                    </div>
                </>
            )}
        </div>
    );
};

export default Dashboard;
