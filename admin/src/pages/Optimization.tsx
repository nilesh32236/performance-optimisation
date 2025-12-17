import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCode, faFileCode, faChartLine, faSave, faUndo } from '@fortawesome/free-solid-svg-icons';

const Optimization: React.FC = () => {
    const [loading, setLoading] = useState(false);
    const [settings, setSettings] = useState({
        minify_css: true,
        minify_js: true,
        minify_html: true,
        combine_css: false,
        combine_js: false,
        defer_js: true,
        remove_query_strings: true,
        disable_emojis: true
    });

    const updateSetting = (key: string, value: boolean) => {
        setSettings(prev => ({ ...prev, [key]: value }));
    };

    const handleSave = () => {
        setLoading(true);
        setTimeout(() => setLoading(false), 1000);
    };

    return (
        <div className="space-y-8 p-6 max-w-7xl mx-auto">
             <div>
                <h2 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">File Optimization</h2>
                <p className="text-base text-gray-600 dark:text-gray-400">Minify and optimize CSS, JavaScript, and HTML files.</p>
            </div>

            {/* Stats Cards */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
                {[
                    { title: 'CSS Files', value: '24', sub: '156 KB', icon: faFileCode, color: 'text-blue-600 dark:text-blue-400', bg: 'bg-blue-100 dark:bg-blue-900/30' },
                    { title: 'JS Files', value: '18', sub: '342 KB', icon: faCode, color: 'text-green-600 dark:text-green-400', bg: 'bg-green-100 dark:bg-green-900/30' },
                    { title: 'HTML Pages', value: '156', sub: '89 KB', icon: faFileCode, color: 'text-purple-600 dark:text-purple-400', bg: 'bg-purple-100 dark:bg-purple-900/30' },
                    { title: 'Total Saved', value: '45%', sub: '587 KB', icon: faChartLine, color: 'text-orange-600 dark:text-orange-400', bg: 'bg-orange-100 dark:bg-orange-900/30' },
                ].map((stat, idx) => (
                    <div key={idx} className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                         <div className={`w-12 h-12 ${stat.bg} ${stat.color} rounded-lg flex items-center justify-center mb-4`}>
                            <FontAwesomeIcon icon={stat.icon} size="lg" />
                        </div>
                        <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400">{stat.title}</h3>
                        <div className="flex items-baseline gap-2 mt-1">
                             <span className="text-2xl font-bold text-gray-900 dark:text-white">{stat.value}</span>
                             <span className="text-xs text-gray-400">{stat.sub}</span>
                        </div>
                    </div>
                ))}
            </div>

            {/* Options */}
            <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div className="p-6 border-b border-gray-200 dark:border-gray-700">
                     <h2 className="text-xl font-bold text-gray-900 dark:text-white">Optimization Settings</h2>
                </div>
                 <div className="p-6 space-y-6">
                    {[
                        { key: 'minify_css', title: 'Minify CSS Files', desc: 'Remove whitespace and comments from CSS files.' },
                        { key: 'minify_js', title: 'Minify JavaScript', desc: 'Compress JavaScript files for faster loading.' },
                        { key: 'minify_html', title: 'Minify HTML', desc: 'Remove unnecessary characters from HTML output.' },
                        { key: 'combine_css', title: 'Combine CSS Files', desc: 'Merge multiple CSS files into one.' },
                        { key: 'combine_js', title: 'Combine JavaScript Files', desc: 'Merge multiple JS files into one.' },
                        { key: 'defer_js', title: 'Defer JavaScript Loading', desc: 'Load JavaScript after page content.' },
                        { key: 'remove_query_strings', title: 'Remove Query Strings', desc: 'Remove version parameters from static resources.' },
                        { key: 'disable_emojis', title: 'Disable Emojis', desc: 'Remove WordPress emoji scripts.' },
                    ].map((option) => (
                         <div key={option.key} className="flex items-center justify-between">
                             <div className="flex-1 pr-4">
                                <h4 className="text-base font-medium text-gray-900 dark:text-white mb-1">{option.title}</h4>
                                <p className="text-sm text-gray-500 dark:text-gray-400">{option.desc}</p>
                            </div>
                           <label className="relative inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={settings[option.key as keyof typeof settings]}
                                    onChange={(e) => updateSetting(option.key, e.target.checked)}
                                />
                                <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 dark:peer-focus:ring-primary-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-primary-600"></div>
                            </label>
                        </div>
                    ))}
                 </div>
                 <div className="p-6 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-3">
                     <button
                        onClick={handleSave}
                        disabled={loading}
                        className="flex items-center gap-2 px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg shadow-sm transition-colors disabled:opacity-70"
                    >
                        <FontAwesomeIcon icon={faSave} />
                        {loading ? 'Saving...' : 'Save Settings'}
                    </button>
                    <button
                         className="flex items-center gap-2 px-6 py-2.5 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                    >
                        <FontAwesomeIcon icon={faUndo} />
                        Reset
                    </button>
                </div>
            </div>
        </div>
    );
};

export default Optimization;
