import React from 'react';
import { Dashicon } from '@wordpress/components';

export const AdvancedTab: React.FC = () => {
	return (
		<div className="space-y-8">
			<div>
				<h2 className="text-3xl font-bold text-gray-900 mb-2">Advanced Settings</h2>
				<p className="text-base text-gray-600">Fine-tune WordPress performance and security</p>
			</div>

			{/* WordPress Optimizations */}
			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h2 className="text-2xl font-bold text-gray-900 mb-6">WordPress Optimizations</h2>
				<div className="space-y-6">
					{[
						{ title: 'Disable Emojis', desc: 'Remove WordPress emoji scripts and styles', enabled: true, impact: 'Low' },
						{ title: 'Disable Embeds', desc: 'Remove oEmbed functionality if not needed', enabled: true, impact: 'Low' },
						{ title: 'Disable XML-RPC', desc: 'Disable XML-RPC for better security', enabled: true, impact: 'Medium' },
						{ title: 'Remove Query Strings', desc: 'Remove version parameters from static resources', enabled: true, impact: 'Low' },
						{ title: 'Disable Heartbeat API', desc: 'Reduce server load by limiting heartbeat', enabled: false, impact: 'Medium' },
						{ title: 'Limit Post Revisions', desc: 'Limit the number of post revisions stored', enabled: true, impact: 'Low' },
					].map((option, index) => (
						<div key={index} className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
							<div className="flex-1">
								<div className="flex items-center gap-3 mb-1">
									<h4 className="text-base font-semibold text-gray-900">{option.title}</h4>
									<span className={`px-2 py-1 text-xs font-semibold rounded ${
										option.impact === 'High' ? 'bg-red-100 text-red-700' :
										option.impact === 'Medium' ? 'bg-orange-100 text-orange-700' :
										'bg-blue-100 text-blue-700'
									}`}>
										{option.impact} Impact
									</span>
								</div>
								<p className="text-sm text-gray-600">{option.desc}</p>
							</div>
							<label className="relative inline-flex items-center cursor-pointer ml-4">
								<input type="checkbox" className="sr-only peer" defaultChecked={option.enabled} />
								<div className="w-14 h-8 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-6 peer-checked:after:border-white after:content-[''] after:absolute after:top-1 after:left-1 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-500"></div>
							</label>
						</div>
					))}
				</div>
			</div>

			{/* Database Optimization */}
			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h2 className="text-2xl font-bold text-gray-900 mb-6">Database Optimization</h2>
				<div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
					{[
						{ title: 'Post Revisions', value: '1,234', action: 'Clean', color: 'blue' },
						{ title: 'Auto Drafts', value: '89', action: 'Clean', color: 'green' },
						{ title: 'Spam Comments', value: '456', action: 'Clean', color: 'orange' },
						{ title: 'Trashed Items', value: '23', action: 'Clean', color: 'red' },
					].map((item, index) => (
						<div key={index} className="p-4 bg-gray-50 rounded-lg flex items-center justify-between">
							<div>
								<h4 className="text-base font-semibold text-gray-900 mb-1">{item.title}</h4>
								<p className="text-2xl font-bold text-gray-900">{item.value} items</p>
							</div>
							<button className={`px-4 py-2 bg-${item.color}-500 text-white text-sm font-semibold rounded-lg hover:bg-${item.color}-600 transition-colors`}>
								{item.action}
							</button>
						</div>
					))}
				</div>
				<button className="w-full px-6 py-3 bg-blue-500 text-white text-base font-semibold rounded-lg hover:bg-blue-600 transition-colors">
					Optimize Database Tables
				</button>
			</div>

			{/* Security Settings */}
			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h2 className="text-2xl font-bold text-gray-900 mb-6">Security Enhancements</h2>
				<div className="space-y-6">
					{[
						{ title: 'Hide WordPress Version', desc: 'Remove version number from HTML and feeds', enabled: true },
						{ title: 'Disable File Editing', desc: 'Prevent editing theme and plugin files from admin', enabled: true },
						{ title: 'Disable Directory Browsing', desc: 'Prevent listing of directory contents', enabled: true },
						{ title: 'Add Security Headers', desc: 'Add X-Frame-Options and other security headers', enabled: true },
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
			</div>

			{/* Save Button */}
			<div className="flex gap-4">
				<button className="px-6 py-3 bg-blue-500 text-white text-base font-semibold rounded-lg hover:bg-blue-600 transition-colors">
					Save All Settings
				</button>
				<button className="px-6 py-3 bg-gray-200 text-gray-700 text-base font-semibold rounded-lg hover:bg-gray-300 transition-colors">
					Reset to Defaults
				</button>
			</div>
		</div>
	);
};
