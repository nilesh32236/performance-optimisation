import React, { useState } from 'react';
import { Dashicon } from '@wordpress/components';

export const CachingTab: React.FC = () => {
	const [loading, setLoading] = useState(false);

	const handleClearCache = (type: string) => {
		setLoading(true);
		setTimeout(() => {
			alert(`${type} cache cleared!`);
			setLoading(false);
		}, 500);
	};

	return (
		<div className="space-y-8">
			{/* Header */}
			<div>
				<h2 className="text-3xl font-bold text-gray-900 mb-2">Cache Management</h2>
				<p className="text-base text-gray-600">Manage your site's caching system to improve performance</p>
			</div>

			{/* Cache Stats */}
			<div className="grid grid-cols-1 md:grid-cols-3 gap-6">
				<div className="bg-white rounded-xl border-2 border-gray-200 p-6">
					<div className="flex items-center justify-between mb-4">
						<div className="w-12 h-12 bg-blue-500 rounded-lg flex items-center justify-center">
							<Dashicon icon="admin-page" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<span className="px-3 py-1 bg-green-100 text-green-700 text-sm font-semibold rounded-full">Active</span>
					</div>
					<h3 className="text-lg font-bold text-gray-900 mb-2">Page Cache</h3>
					<div className="space-y-2 mb-4">
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Files Cached</span>
							<span className="text-base font-bold text-gray-900">1,234</span>
						</div>
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Cache Size</span>
							<span className="text-base font-bold text-gray-900">45.2 MB</span>
						</div>
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Hit Rate</span>
							<span className="text-base font-bold text-blue-600">92%</span>
						</div>
					</div>
					<button
						onClick={() => handleClearCache('Page')}
						disabled={loading}
						className="w-full px-4 py-3 bg-blue-500 text-white text-base font-semibold rounded-lg hover:bg-blue-600 transition-colors disabled:opacity-50"
					>
						Clear Page Cache
					</button>
				</div>

				<div className="bg-white rounded-xl border-2 border-gray-200 p-6">
					<div className="flex items-center justify-between mb-4">
						<div className="w-12 h-12 bg-purple-500 rounded-lg flex items-center justify-center">
							<Dashicon icon="database" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<span className="px-3 py-1 bg-green-100 text-green-700 text-sm font-semibold rounded-full">Active</span>
					</div>
					<h3 className="text-lg font-bold text-gray-900 mb-2">Object Cache</h3>
					<div className="space-y-2 mb-4">
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Objects Cached</span>
							<span className="text-base font-bold text-gray-900">5,678</span>
						</div>
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Backend</span>
							<span className="text-base font-bold text-gray-900">Redis</span>
						</div>
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Hit Rate</span>
							<span className="text-base font-bold text-purple-600">88%</span>
						</div>
					</div>
					<button
						onClick={() => handleClearCache('Object')}
						disabled={loading}
						className="w-full px-4 py-3 bg-purple-500 text-white text-base font-semibold rounded-lg hover:bg-purple-600 transition-colors disabled:opacity-50"
					>
						Clear Object Cache
					</button>
				</div>

				<div className="bg-white rounded-xl border-2 border-gray-200 p-6">
					<div className="flex items-center justify-between mb-4">
						<div className="w-12 h-12 bg-green-500 rounded-lg flex items-center justify-center">
							<Dashicon icon="admin-site" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<span className="px-3 py-1 bg-green-100 text-green-700 text-sm font-semibold rounded-full">Active</span>
					</div>
					<h3 className="text-lg font-bold text-gray-900 mb-2">Browser Cache</h3>
					<div className="space-y-2 mb-4">
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Max Age</span>
							<span className="text-base font-bold text-gray-900">30 days</span>
						</div>
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Resources</span>
							<span className="text-base font-bold text-gray-900">CSS, JS, Images</span>
						</div>
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Status</span>
							<span className="text-base font-bold text-green-600">Enabled</span>
						</div>
					</div>
					<button
						onClick={() => handleClearCache('Browser')}
						disabled={loading}
						className="w-full px-4 py-3 bg-green-500 text-white text-base font-semibold rounded-lg hover:bg-green-600 transition-colors disabled:opacity-50"
					>
						Configure Headers
					</button>
				</div>
			</div>

			{/* Cache Settings */}
			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h2 className="text-2xl font-bold text-gray-900 mb-6">Cache Settings</h2>
				<div className="space-y-6">
					{[
						{ title: 'Enable Page Caching', desc: 'Cache full HTML pages for faster loading', enabled: true },
						{ title: 'Enable Object Caching', desc: 'Cache database queries and PHP objects', enabled: true },
						{ title: 'Enable Browser Caching', desc: 'Set cache headers for static resources', enabled: true },
						{ title: 'Cache Preloading', desc: 'Automatically generate cache for important pages', enabled: false },
						{ title: 'GZIP Compression', desc: 'Compress cached files to save bandwidth', enabled: true },
						{ title: 'Mobile Cache', desc: 'Separate cache for mobile devices', enabled: false },
					].map((setting, index) => (
						<div key={index} className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
							<div className="flex-1">
								<h4 className="text-base font-semibold text-gray-900 mb-1">{setting.title}</h4>
								<p className="text-sm text-gray-600">{setting.desc}</p>
							</div>
							<label className="relative inline-flex items-center cursor-pointer ml-4">
								<input type="checkbox" className="sr-only peer" defaultChecked={setting.enabled} />
								<div className="w-14 h-8 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-6 peer-checked:after:border-white after:content-[''] after:absolute after:top-1 after:left-1 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-500"></div>
							</label>
						</div>
					))}
				</div>
				<div className="mt-8 flex gap-4">
					<button className="px-6 py-3 bg-blue-500 text-white text-base font-semibold rounded-lg hover:bg-blue-600 transition-colors">
						Save Settings
					</button>
					<button className="px-6 py-3 bg-gray-200 text-gray-700 text-base font-semibold rounded-lg hover:bg-gray-300 transition-colors">
						Reset to Defaults
					</button>
				</div>
			</div>
		</div>
	);
};
