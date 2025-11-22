import React from 'react';
import { Dashicon } from '@wordpress/components';

export const ImagesTab: React.FC = () => {
	return (
		<div className="space-y-8">
			<div>
				<h2 className="text-3xl font-bold text-gray-900 mb-2">Image Optimization</h2>
				<p className="text-base text-gray-600">Compress and convert images to modern formats</p>
			</div>

			{/* Image Stats */}
			<div className="grid grid-cols-1 md:grid-cols-4 gap-6">
				{[
					{ title: 'Total Images', value: '348', icon: 'format-image', color: 'blue' },
					{ title: 'Optimized', value: '248', icon: 'yes-alt', color: 'green' },
					{ title: 'Pending', value: '100', icon: 'clock', color: 'orange' },
					{ title: 'Space Saved', value: '12.4 MB', icon: 'database', color: 'purple' },
				].map((stat, index) => (
					<div key={index} className="bg-white rounded-xl border-2 border-gray-200 p-6">
						<div className={`w-12 h-12 bg-${stat.color}-500 rounded-lg flex items-center justify-center mb-4`}>
							<Dashicon icon={stat.icon as any} style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<h3 className="text-base font-semibold text-gray-600 mb-1">{stat.title}</h3>
						<p className="text-3xl font-bold text-gray-900">{stat.value}</p>
					</div>
				))}
			</div>

			{/* Bulk Actions */}
			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h2 className="text-2xl font-bold text-gray-900 mb-6">Bulk Actions</h2>
				<div className="grid grid-cols-1 md:grid-cols-2 gap-6">
					<button className="p-6 border-2 border-blue-200 bg-blue-50 rounded-xl hover:bg-blue-100 transition-colors text-left">
						<div className="w-12 h-12 bg-blue-500 rounded-lg flex items-center justify-center mb-4">
							<Dashicon icon="format-image" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<h3 className="text-lg font-bold text-gray-900 mb-2">Optimize All Images</h3>
						<p className="text-base text-gray-600 mb-4">Compress all unoptimized images in your media library</p>
						<span className="text-sm font-semibold text-blue-600">100 images pending</span>
					</button>

					<button className="p-6 border-2 border-gray-200 bg-white rounded-xl hover:bg-gray-50 transition-colors text-left">
						<div className="w-12 h-12 bg-green-500 rounded-lg flex items-center justify-center mb-4">
							<Dashicon icon="image-rotate" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<h3 className="text-lg font-bold text-gray-900 mb-2">Convert to WebP</h3>
						<p className="text-base text-gray-600 mb-4">Convert images to modern WebP format</p>
						<span className="text-sm font-semibold text-green-600">Save up to 30% size</span>
					</button>
				</div>
			</div>

			{/* Image Settings */}
			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h2 className="text-2xl font-bold text-gray-900 mb-6">Image Settings</h2>
				<div className="space-y-6">
					{[
						{ title: 'Enable Lazy Loading', desc: 'Load images only when they appear in viewport', enabled: true },
						{ title: 'Auto-Optimize on Upload', desc: 'Automatically optimize images when uploaded', enabled: true },
						{ title: 'Convert to WebP', desc: 'Create WebP versions of images', enabled: true },
						{ title: 'Resize Large Images', desc: 'Automatically resize images larger than max dimensions', enabled: true },
						{ title: 'Remove EXIF Data', desc: 'Strip metadata from images to reduce size', enabled: true },
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

				<div className="mt-6 p-4 bg-blue-50 border-2 border-blue-200 rounded-lg">
					<h4 className="text-base font-semibold text-gray-900 mb-2">Quality Settings</h4>
					<div className="space-y-4">
						<div>
							<label className="text-sm font-medium text-gray-700 mb-2 block">Compression Quality: 85%</label>
							<input type="range" min="50" max="100" defaultValue="85" className="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer" />
							<div className="flex justify-between text-xs text-gray-500 mt-1">
								<span>Smaller size</span>
								<span>Better quality</span>
							</div>
						</div>
						<div>
							<label className="text-sm font-medium text-gray-700 mb-2 block">Max Image Width: 2000px</label>
							<input type="number" defaultValue="2000" className="w-full px-4 py-2 border-2 border-gray-200 rounded-lg text-base" />
						</div>
					</div>
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
