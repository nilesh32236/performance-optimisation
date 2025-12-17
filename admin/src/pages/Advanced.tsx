import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDatabase, faShieldAlt, faTrash, faBroom } from '@fortawesome/free-solid-svg-icons';

const Advanced: React.FC = () => {
    const [loading, setLoading] = useState(false);

    const handleAction = () => {
        setLoading(true);
        setTimeout(() => setLoading(false), 2000);
    };

    return (
         <div className="space-y-8 p-6 max-w-7xl mx-auto">
             <div>
                <h2 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">Advanced Settings</h2>
                <p className="text-base text-gray-700 dark:text-gray-200">Fine-tune WordPress performance, database, and security.</p>
            </div>

            {/* Database */}
            <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-8">
                 <div className="flex items-center gap-3 mb-6">
                    <div className="p-2 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg">
                         <FontAwesomeIcon icon={faDatabase} />
                    </div>
                    <h2 className="text-xl font-bold text-gray-900 dark:text-white">Database Optimization</h2>
                </div>
                
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    {[
                        { title: 'Post Revisions', count: '1,234 items', color: 'blue' },
                        { title: 'Spam Comments', count: '456 items', color: 'orange' },
                        { title: 'Trashed Posts', count: '23 items', color: 'red' },
                        { title: 'Expired Transients', count: '890 items', color: 'gray' },
                    ].map((item, idx) => (
                        <div key={idx} className="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900/50 rounded-lg border border-gray-100 dark:border-gray-700">
                             <div>
                                <h4 className="font-medium text-gray-900 dark:text-white">{item.title}</h4>
                                <span className="text-sm text-gray-600 dark:text-gray-300">{item.count}</span>
                             </div>
                             <button className="px-3 py-1.5 text-xs font-medium bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 transition-colors">
                                Clean
                             </button>
                        </div>
                    ))}
                </div>
                <button
                    onClick={handleAction}
                    disabled={loading}
                    className="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center justify-center gap-2"
                >
                    <FontAwesomeIcon icon={faBroom} className={loading ? 'animate-bounce' : ''} />
                    {loading ? 'Optimizing Database...' : 'Optimize All Database Tables'}
                </button>
            </div>

            {/* Security */}
             <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-8">
                <div className="flex items-center gap-3 mb-6">
                    <div className="p-2 bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 rounded-lg">
                         <FontAwesomeIcon icon={faShieldAlt} />
                    </div>
                    <h2 className="text-xl font-bold text-gray-900 dark:text-white">Security Tweaks</h2>
                </div>
                <div className="space-y-4">
                     {[
                        { title: 'Disable XML-RPC', desc: 'Prevent remote attacks via XML-RPC.', enabled: true },
                        { title: 'Hide WP Version', desc: 'Remove WordPress version from source code.', enabled: true },
                        { title: 'Disable File Editing', desc: 'Prevent file editing from WP Admin.', enabled: true },
                    ].map((tweak, idx) => (
                         <div key={idx} className="flex items-center justify-between p-4 border border-gray-100 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                             <div>
                                <h4 className="font-medium text-gray-900 dark:text-white">{tweak.title}</h4>
                                <p className="text-sm text-gray-600 dark:text-gray-300">{tweak.desc}</p>
                             </div>
                              <label className="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" className="sr-only peer" defaultChecked={tweak.enabled} />
                                <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                            </label>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
};

export default Advanced;
