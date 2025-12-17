import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faGaugeHigh, faUniversalAccess, faShieldHalved, faSearch,
    faClock, faImage, faFileCode, faArrowRotateRight, faCheckCircle,
    faExclamationTriangle, faTimesCircle, faChartBar, faServer
} from '@fortawesome/free-solid-svg-icons';
import SuggestionsPanel from '../components/SuggestionsPanel';
import HistoryChart from '../components/HistoryChart';
import SystemInfoTab from '../components/SystemInfoTab';

interface MetricData {
    value: number;
    display: string;
    score: number;
}

interface PageSpeedData {
    success: boolean;
    scores?: {
        performance: number;
        accessibility: number;
        best_practices: number;
        seo: number;
    };
    metrics?: {
        fcp: MetricData;
        lcp: MetricData;
        cls: MetricData;
        tbt: MetricData;
        si: MetricData;
    };
}

interface AssetsData {
    success: boolean;
    summary?: {
        css_count: number;
        css_total_size: number;
        js_count: number;
        js_total_size: number;
        image_count: number;
        image_total_size: number;
        total_assets: number;
        total_size: number;
    };
}

const getScoreColor = (score: number): { bg: string; text: string; border: string } => {
    if (score >= 90) return { bg: 'bg-emerald-100 dark:bg-emerald-900/30', text: 'text-emerald-600 dark:text-emerald-400', border: 'border-emerald-500' };
    if (score >= 50) return { bg: 'bg-amber-100 dark:bg-amber-900/30', text: 'text-amber-600 dark:text-amber-400', border: 'border-amber-500' };
    return { bg: 'bg-red-100 dark:bg-red-900/30', text: 'text-red-600 dark:text-red-400', border: 'border-red-500' };
};

const getScoreIcon = (score: number) => {
    if (score >= 90) return faCheckCircle;
    if (score >= 50) return faExclamationTriangle;
    return faTimesCircle;
};

const formatBytes = (bytes: number): string => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

const Monitor: React.FC = () => {
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [mobileData, setMobileData] = useState<PageSpeedData | null>(null);
    const [desktopData, setDesktopData] = useState<PageSpeedData | null>(null);
    const [assetsData, setAssetsData] = useState<AssetsData | null>(null);
    const [activeDevice, setActiveDevice] = useState<'mobile' | 'desktop'>('mobile');
    const [error, setError] = useState<string | null>(null);

    const [url, setUrl] = useState('');
    const [analyzingUrl, setAnalyzingUrl] = useState('');

    useEffect(() => {
        fetchData(false);
    }, []);

    const fetchData = async (refresh: boolean, targetUrl?: string) => {
        const fetchUrl = targetUrl || analyzingUrl;
        
        if (refresh) {
            setRefreshing(true);
        } else {
            // Check cache
            const cacheKey = `wppo_monitor_${fetchUrl || 'home'}`;
            const cachedTime = localStorage.getItem(`${cacheKey}_time`);
            const cachedMobile = localStorage.getItem(`${cacheKey}_mobile`);
            const cachedDesktop = localStorage.getItem(`${cacheKey}_desktop`);
            const cachedAssets = localStorage.getItem(`${cacheKey}_assets`);

            if (cachedTime && cachedMobile && cachedDesktop && cachedAssets) {
                const age = Date.now() - parseInt(cachedTime, 10);
                // Use cache if less than 5 minutes old
                if (age < 5 * 60 * 1000) {
                    try {
                        setMobileData(JSON.parse(cachedMobile));
                        setDesktopData(JSON.parse(cachedDesktop));
                        setAssetsData(JSON.parse(cachedAssets));
                        setLoading(false);
                        return;
                    } catch (e) {
                        // Ignore parse error and fetch fresh
                    }
                }
            }
            setLoading(true);
        }
        
        setError(null);

        try {
            const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
            const nonce = (window as any).wppoAdmin?.nonce || '';
            
            const queryParams = new URLSearchParams();
            if (refresh) queryParams.append('refresh', 'true');
            if (fetchUrl) queryParams.append('url', fetchUrl);
            const queryString = queryParams.toString() ? `?${queryParams.toString()}` : '';

            // Fetch PageSpeed data.
            const psResponse = await fetch(`${apiUrl}/monitor/pagespeed${queryString}`, {
                headers: { 'X-WP-Nonce': nonce },
            });

            if (psResponse.ok) {
                const psData = await psResponse.json();
                if (psData.success && psData.data) {
                    setMobileData(psData.data.mobile);
                    setDesktopData(psData.data.desktop);
                    
                    // Update cache
                    const cacheKey = `wppo_monitor_${fetchUrl || 'home'}`;
                    localStorage.setItem(`${cacheKey}_mobile`, JSON.stringify(psData.data.mobile));
                    localStorage.setItem(`${cacheKey}_desktop`, JSON.stringify(psData.data.desktop));
                }
            }

            // Fetch Assets data.
            const assetsResponse = await fetch(`${apiUrl}/monitor/assets${queryString}`, {
                headers: { 'X-WP-Nonce': nonce },
            });

            if (assetsResponse.ok) {
                const aData = await assetsResponse.json();
                if (aData.success) {
                    setAssetsData(aData.data);
                    
                    // Update cache
                    const cacheKey = `wppo_monitor_${fetchUrl || 'home'}`;
                    localStorage.setItem(`${cacheKey}_assets`, JSON.stringify(aData.data));
                }
            }
            
            // Update cache timestamp
            const cacheKey = `wppo_monitor_${fetchUrl || 'home'}`;
            localStorage.setItem(`${cacheKey}_time`, Date.now().toString());

        } catch (err) {
            setError('Failed to fetch performance data. Please try again.');
            console.error('Monitor fetch error:', err);
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    };

    const handleAnalyze = (e: React.FormEvent) => {
        e.preventDefault();
        setAnalyzingUrl(url);
        fetchData(true, url);
    };

    const currentData = activeDevice === 'mobile' ? mobileData : desktopData;

    if (loading) {
        return (
            <div className="p-8 flex flex-col items-center justify-center min-h-[400px]">
                <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-4"></div>
                <p className="text-gray-600 dark:text-gray-300">Analyzing {analyzingUrl || 'your site'} performance...</p>
                <p className="text-xs text-gray-400 mt-2">This may take up to 60 seconds</p>
            </div>
        );
    }

    return (
        <div className="p-8 max-w-7xl mx-auto space-y-8">
            {/* Header */}
            <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Performance Monitor</h1>
                    <p className="text-gray-600 dark:text-gray-300 mt-1">Real-time Lighthouse scores powered by Google PageSpeed API</p>
                </div>
                <div className="flex flex-col sm:flex-row items-center gap-3 w-full md:w-auto">
                    {/* URL Input Form */}
                    <form onSubmit={handleAnalyze} className="flex w-full sm:w-auto gap-2">
                        <input
                            type="url"
                            value={url}
                            onChange={(e) => setUrl(e.target.value)}
                            placeholder="Enter URL to analyze..."
                            className="bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg px-4 py-2 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none w-full sm:w-64"
                        />
                        <button
                            type="submit"
                            disabled={loading || !url}
                            className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap"
                        >
                            Analyze
                        </button>
                    </form>

                    {/* Device Toggle */}
                    <div className="flex bg-gray-100 dark:bg-gray-800 rounded-lg p-1">
                        <button
                            onClick={() => setActiveDevice('mobile')}
                            className={`px-4 py-2 text-sm font-medium rounded-md transition-colors ${activeDevice === 'mobile' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm' : 'text-gray-600 dark:text-gray-400'}`}
                        >
                            Mobile
                        </button>
                        <button
                            onClick={() => setActiveDevice('desktop')}
                            className={`px-4 py-2 text-sm font-medium rounded-md transition-colors ${activeDevice === 'desktop' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm' : 'text-gray-600 dark:text-gray-400'}`}
                        >
                            Desktop
                        </button>
                    </div>
                </div>
            </div>

            {error && (
                <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 text-red-600 dark:text-red-400">
                    {error}
                </div>
            )}

            {/* Lighthouse Scores */}
            {currentData?.scores && (
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    {[
                        { key: 'performance', label: 'Performance', icon: faGaugeHigh },
                        { key: 'accessibility', label: 'Accessibility', icon: faUniversalAccess },
                        { key: 'best_practices', label: 'Best Practices', icon: faShieldHalved },
                        { key: 'seo', label: 'SEO', icon: faSearch },
                    ].map(({ key, label, icon }) => {
                        const score = (currentData.scores as any)[key] || 0;
                        const colors = getScoreColor(score);
                        return (
                            <div key={key} className={`bg-white dark:bg-gray-800 rounded-2xl p-6 border-l-4 ${colors.border} shadow-sm`}>
                                <div className="flex items-center justify-between mb-3">
                                    <FontAwesomeIcon icon={icon} className={`text-xl ${colors.text}`} />
                                    <FontAwesomeIcon icon={getScoreIcon(score)} className={colors.text} />
                                </div>
                                <div className={`text-4xl font-bold ${colors.text} tabular-nums`}>{score}</div>
                                <div className="text-sm text-gray-600 dark:text-gray-300 mt-1">{label}</div>
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Core Web Vitals */}
            {currentData?.metrics && (
                <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <h2 className="text-xl font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2">
                        <FontAwesomeIcon icon={faChartBar} className="text-blue-500" />
                        Core Web Vitals
                    </h2>
                    <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
                        {[
                            { key: 'fcp', label: 'First Contentful Paint', abbr: 'FCP' },
                            { key: 'lcp', label: 'Largest Contentful Paint', abbr: 'LCP' },
                            { key: 'cls', label: 'Cumulative Layout Shift', abbr: 'CLS' },
                            { key: 'tbt', label: 'Total Blocking Time', abbr: 'TBT' },
                            { key: 'si', label: 'Speed Index', abbr: 'SI' },
                        ].map(({ key, label, abbr }) => {
                            const metric = (currentData.metrics as any)[key];
                            if (!metric) return null;
                            const colors = getScoreColor(metric.score);
                            return (
                                <div key={key} className={`${colors.bg} rounded-xl p-4`}>
                                    <div className="flex items-center justify-between mb-2">
                                        <span className="text-xs font-bold text-gray-600 dark:text-gray-300 uppercase">{abbr}</span>
                                        <span className={`text-xs font-medium ${colors.text}`}>{metric.score}%</span>
                                    </div>
                                    <div className={`text-2xl font-bold ${colors.text} tabular-nums`}>{metric.display}</div>
                                    <div className="text-xs text-gray-600 dark:text-gray-300 mt-1 truncate" title={label}>{label}</div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}

            {/* Asset Analysis */}
            {assetsData?.summary && (
                <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <h2 className="text-xl font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2">
                        <FontAwesomeIcon icon={faServer} className="text-purple-500" />
                        Asset Analysis
                    </h2>
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-4">
                            <div className="flex items-center gap-2 mb-2">
                                <FontAwesomeIcon icon={faFileCode} className="text-blue-500" />
                                <span className="text-sm font-medium text-gray-700 dark:text-gray-300">CSS Files</span>
                            </div>
                            <div className="text-2xl font-bold text-blue-600 dark:text-blue-400">{assetsData.summary.css_count}</div>
                            <div className="text-xs text-gray-500">{formatBytes(assetsData.summary.css_total_size)}</div>
                        </div>
                        <div className="bg-yellow-50 dark:bg-yellow-900/20 rounded-xl p-4">
                            <div className="flex items-center gap-2 mb-2">
                                <FontAwesomeIcon icon={faFileCode} className="text-yellow-500" />
                                <span className="text-sm font-medium text-gray-700 dark:text-gray-300">JS Files</span>
                            </div>
                            <div className="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{assetsData.summary.js_count}</div>
                            <div className="text-xs text-gray-500">{formatBytes(assetsData.summary.js_total_size)}</div>
                        </div>
                        <div className="bg-purple-50 dark:bg-purple-900/20 rounded-xl p-4">
                            <div className="flex items-center gap-2 mb-2">
                                <FontAwesomeIcon icon={faImage} className="text-purple-500" />
                                <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Images</span>
                            </div>
                            <div className="text-2xl font-bold text-purple-600 dark:text-purple-400">{assetsData.summary.image_count}</div>
                            <div className="text-xs text-gray-500">{formatBytes(assetsData.summary.image_total_size)}</div>
                        </div>
                        <div className="bg-gray-100 dark:bg-gray-700/50 rounded-xl p-4">
                            <div className="flex items-center gap-2 mb-2">
                                <FontAwesomeIcon icon={faClock} className="text-gray-500" />
                                <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Total Size</span>
                            </div>
                            <div className="text-2xl font-bold text-gray-700 dark:text-gray-300">{formatBytes(assetsData.summary.total_size)}</div>
                            <div className="text-xs text-gray-500">{assetsData.summary.total_assets} assets</div>
                        </div>
                    </div>
                </div>
            )}

            {/* No Data State */}
            {!currentData?.scores && !error && (
                <div className="bg-gray-50 dark:bg-gray-800/50 rounded-2xl p-12 text-center">
                    <FontAwesomeIcon icon={faGaugeHigh} className="text-4xl text-gray-400 mb-4" />
                    <h3 className="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-2">No Performance Data</h3>
                    <p className="text-gray-600 dark:text-gray-300 mb-4">Click "Refresh" to analyze your site's performance.</p>
                    <button
                        onClick={() => fetchData(true)}
                        disabled={refreshing}
                        className="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors"
                    >
                        Run Analysis
                    </button>
                </div>
            )}

            {/* Performance Suggestions */}
            <SuggestionsPanel />

            {/* Historical Performance Charts */}
            <HistoryChart />

            {/* System Information */}
            <SystemInfoTab />
        </div>
    );
};

export default Monitor;
