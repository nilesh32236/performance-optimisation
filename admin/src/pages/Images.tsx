import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faImage, faBolt, faClock, faDatabase, faMagic, faSave } from '@fortawesome/free-solid-svg-icons';

const Images: React.FC = () => {
    const [loading, setLoading] = useState(false);
    const [optimizing, setOptimizing] = useState(false);
    const [settings, setSettings] = useState({
        auto_convert: true,
        webp_conversion: true,
        avif_conversion: false,
        lazy_load: true,
        quality: 82,
    });

    const updateSetting = (key: string, value: any) => {
        setSettings(prev => ({ ...prev, [key]: value }));
    };

    const handleOptimize = () => {
        setOptimizing(true);
        setTimeout(() => setOptimizing(false), 3000);
    };

    return (
        <div className="space-y-8 p-6 max-w-7xl mx-auto">
             <div>
                <h2 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">Image Optimization</h2>
                <p className="text-base text-gray-600 dark:text-gray-400">Compress and convert images to modern formats.</p>
            </div>

            {/* Stats */}
             <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
                {[
                    { title: 'Total Images', value: '1,245', icon: faImage, color: 'text-blue-600 dark:text-blue-400', bg: 'bg-blue-100 dark:bg-blue-900/30' },
                    { title: 'Optimized', value: '840', icon: faBolt, color: 'text-green-600 dark:text-green-400', bg: 'bg-green-100 dark:bg-green-900/30' },
                    { title: 'Pending', value: '405', icon: faClock, color: 'text-orange-600 dark:text-orange-400', bg: 'bg-orange-100 dark:bg-orange-900/30' },
                    { title: 'Space Saved', value: '450 MB', icon: faDatabase, color: 'text-purple-600 dark:text-purple-400', bg: 'bg-purple-100 dark:bg-purple-900/30' },
                ].map((stat, idx) => (
                    <div key={idx} className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                         <div className={`w-12 h-12 ${stat.bg} ${stat.color} rounded-lg flex items-center justify-center mb-4`}>
                            <FontAwesomeIcon icon={stat.icon} size="lg" />
                        </div>
                        <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400">{stat.title}</h3>
                        <p className="text-2xl font-bold text-gray-900 dark:text-white mt-1">{stat.value}</p>
                    </div>
                ))}
            </div>

            {/* Bulk Action */}
            <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-8">
                <div className="flex flex-col md:flex-row items-center justify-between gap-6">
                    <div>
                        <h3 className="text-xl font-bold text-gray-900 dark:text-white mb-2">Bulk Optimization</h3>
                        <p className="text-gray-600 dark:text-gray-400">Compress all unoptimized images in your media library. This might take a while.</p>
                    </div>
                    <button
                        onClick={handleOptimize}
                        disabled={optimizing}
                        className="px-8 py-4 bg-primary-600 hover:bg-primary-700 text-white font-bold rounded-xl shadow-md transition-all flex items-center gap-3 disabled:opacity-75"
                    >
                         <FontAwesomeIcon icon={faMagic} className={optimizing ? 'animate-spin' : ''} />
                         {optimizing ? 'Optimizing...' : 'Optimize All Images'}
                    </button>
                </div>
            </div>

            {/* Settings */}
             <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div className="p-6 border-b border-gray-200 dark:border-gray-700">
                     <h2 className="text-xl font-bold text-gray-900 dark:text-white">Image Settings</h2>
                </div>
                 <div className="p-6 space-y-8">
                    {/* Toggles */}
                    <div className="space-y-6">
                        {[
                            { key: 'auto_convert', title: 'Auto-Convert on Upload', desc: 'Automatically compress images when uploaded.' },
                            { key: 'webp_conversion', title: 'WebP Conversion', desc: 'Create WebP versions (25-30% smaller).' },
                            { key: 'avif_conversion', title: 'AVIF Conversion', desc: 'Create AVIF versions (40-50% smaller).' },
                             { key: 'lazy_load', title: 'Lazy Load Images', desc: 'Only load images when they enter the viewport.' },
                        ].map((setting) => (
                             <div key={setting.key} className="flex items-center justify-between">
                                 <div>
                                    <h4 className="text-base font-medium text-gray-900 dark:text-white mb-1">{setting.title}</h4>
                                    <p className="text-sm text-gray-500 dark:text-gray-400">{setting.desc}</p>
                                </div>
                               <label className="relative inline-flex items-center cursor-pointer">
                                    <input
                                        type="checkbox"
                                        className="sr-only peer"
                                        checked={settings[setting.key as keyof typeof settings] as boolean}
                                        onChange={(e) => updateSetting(setting.key, e.target.checked)}
                                    />
                                    <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 dark:peer-focus:ring-primary-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-primary-600"></div>
                                </label>
                            </div>
                        ))}
                    </div>

                    {/* Quality Slider */}
                    <div className="p-6 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                        <div className="flex justify-between items-center mb-4">
                            <label className="font-semibold text-gray-900 dark:text-white">Compression Quality</label>
                            <span className="px-3 py-1 bg-white dark:bg-gray-700 rounded-md font-mono text-primary-600 dark:text-primary-400 border border-gray-200 dark:border-gray-600">
                                {settings.quality}%
                            </span>
                        </div>
                        <input
                            type="range"
                            min="50"
                            max="100"
                            value={settings.quality}
                            onChange={(e) => updateSetting('quality', parseInt(e.target.value))}
                            className="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700 accent-primary-600"
                        />
                         <div className="flex justify-between text-xs text-gray-500 dark:text-gray-400 mt-2">
                             <span>Smaller Files</span>
                             <span>Better Quality</span>
                        </div>
                    </div>
                </div>
                 <div className="p-6 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-3">
                     <button
                        className="flex items-center gap-2 px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg shadow-sm transition-colors"
                    >
                        <FontAwesomeIcon icon={faSave} />
                        Save Settings
                    </button>
                </div>
            </div>
        </div>
    );
};

export default Images;
