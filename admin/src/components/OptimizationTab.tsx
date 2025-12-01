import React, { useState } from 'react';
import { Dashicon } from '@wordpress/components';

export const OptimizationTab: React.FC = () => {
	const [isLoading, setIsLoading] = useState(false);
	const [error, setError] = useState<string | null>(null);

	const handleSave = async () => {
		if (!confirm('Save optimization settings? This will apply changes immediately.')) return;
		
		try {
			setIsLoading(true);
			setError(null);
			// Save logic here
			await new Promise(resolve => setTimeout(resolve, 1000)); // Placeholder
		} catch (err) {
			setError(err instanceof Error ? err.message : 'Failed to save settings');
		} finally {
			setIsLoading(false);
		}
	};

	const handleReset = async () => {
		if (!confirm('Reset to default settings? This cannot be undone.')) return;
		
		try {
			setIsLoading(true);
			setError(null);
			// Reset logic here
			await new Promise(resolve => setTimeout(resolve, 1000)); // Placeholder
		} catch (err) {
			setError(err instanceof Error ? err.message : 'Failed to reset settings');
		} finally {
			setIsLoading(false);
		}
	};

	return (
		<div className="space-y-8">
			{error && (
				<div className="bg-red-50 border-2 border-red-200 rounded-lg p-4">
					<p className="text-red-800">{error}</p>
				</div>
			)}
			<div>
				<h2 className="text-3xl font-bold text-gray-900 mb-2">File Optimization</h2>
				<p className="text-base text-gray-600">Minify and optimize CSS, JavaScript, and HTML files</p>
			</div>

			{/* Optimization Stats */}
			<div className="grid grid-cols-1 md:grid-cols-4 gap-6">
				{[
					{ title: 'CSS Files', value: '24', saved: '156 KB', icon: 'media-code', color: 'blue' },
					{ title: 'JS Files', value: '18', saved: '342 KB', icon: 'media-code', color: 'green' },
					{ title: 'HTML Pages', value: '156', saved: '89 KB', icon: 'media-document', color: 'purple' },
					{ title: 'Total Saved', value: '587 KB', saved: '45% reduction', icon: 'chart-line', color: 'orange' },
				].map((stat, index) => (
					<div key={index} className="bg-white rounded-xl border-2 border-gray-200 p-6">
						<div className={`w-12 h-12 bg-${stat.color}-500 rounded-lg flex items-center justify-center mb-4`}>
							<Dashicon icon={stat.icon as any} style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<h3 className="text-base font-semibold text-gray-600 mb-1">{stat.title}</h3>
						<p className="text-3xl font-bold text-gray-900 mb-1">{stat.value}</p>
						<p className="text-sm text-gray-500">{stat.saved}</p>
					</div>
				))}
			</div>

			{/* Optimization Options */}
			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h2 className="text-2xl font-bold text-gray-900 mb-6">Optimization Options</h2>
				<div className="space-y-6">
					{[
						{ title: 'Minify CSS Files', desc: 'Remove whitespace and comments from CSS files', enabled: true },
						{ title: 'Minify JavaScript', desc: 'Compress JavaScript files for faster loading', enabled: true },
						{ title: 'Minify HTML', desc: 'Remove unnecessary characters from HTML output', enabled: true },
						{ title: 'Combine CSS Files', desc: 'Merge multiple CSS files into one', enabled: false },
						{ title: 'Combine JavaScript Files', desc: 'Merge multiple JS files into one', enabled: false },
						{ title: 'Defer JavaScript Loading', desc: 'Load JavaScript after page content', enabled: true },
						{ title: 'Remove Query Strings', desc: 'Remove version parameters from static resources', enabled: true },
						{ title: 'Disable Emojis', desc: 'Remove WordPress emoji scripts', enabled: true },
					].map((option, index) => (
						<div key={index} className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
							<div className="flex-1">
								<h4 className="text-base font-semibold text-gray-900 mb-1">{option.title}</h4>
								<p className="text-sm text-gray-600">{option.desc}</p>
							</div>
							<label className="relative inline-flex items-center cursor-pointer ml-4">
								<input type="checkbox" className="sr-only peer" defaultChecked={option.enabled} />
								<div className="w-14 h-8 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-6 peer-checked:after:border-white after:content-[''] after:absolute after:top-1 after:left-1 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-500"></div>
							</label>
						</div>
					))}
				</div>
				<div className="mt-8 flex gap-4">
					<button 
						onClick={handleSave}
						disabled={isLoading}
						className="px-6 py-3 bg-blue-500 text-white text-base font-semibold rounded-lg hover:bg-blue-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
					>
						{isLoading ? 'Saving...' : 'Save Settings'}
					</button>
					<button 
						onClick={handleReset}
						disabled={isLoading}
						className="px-6 py-3 bg-gray-200 text-gray-700 text-base font-semibold rounded-lg hover:bg-gray-300 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
					>
						{isLoading ? 'Resetting...' : 'Reset to Defaults'}
					</button>
				</div>
			</div>

			{/* File Exclusions */}
			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h2 className="text-2xl font-bold text-gray-900 mb-2">File Exclusions</h2>
				<p className="text-base text-gray-600 mb-6">Exclude specific CSS and JavaScript files from minification and combining</p>
				
				<div className="space-y-6">
					<div>
						<label className="block text-base font-semibold text-gray-900 mb-2">
							Exclude CSS Files (one per line)
						</label>
						<textarea
							rows={5}
							className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
							placeholder="/wp-content/themes/mytheme/style.css&#10;/wp-content/plugins/contact-form-7/&#10;admin-bar.min.css&#10;/wp-includes/css/dist/"
						/>
						<p className="text-sm text-gray-600 mt-2">
							Enter file paths or patterns. Supports partial matching. Example: <code className="bg-gray-100 px-2 py-1 rounded">admin-bar</code> will exclude all files containing "admin-bar"
						</p>
					</div>

					<div>
						<label className="block text-base font-semibold text-gray-900 mb-2">
							Exclude JavaScript Files (one per line)
						</label>
						<textarea
							rows={5}
							className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
							placeholder="/wp-includes/js/jquery/jquery.js&#10;/wp-includes/js/dist/&#10;/wp-content/plugins/woocommerce/&#10;google-analytics"
						/>
						<p className="text-sm text-gray-600 mt-2">
							Common exclusions: jQuery core, WordPress blocks, analytics scripts, third-party plugins
						</p>
					</div>

					<div className="bg-blue-50 border-2 border-blue-200 rounded-lg p-4">
						<div className="flex items-start gap-3">
							<Dashicon icon="info" style={{ fontSize: '20px', color: '#3b82f6', marginTop: '2px' }} />
							<div>
								<h4 className="text-base font-semibold text-blue-900 mb-1">Pro Tip</h4>
								<p className="text-sm text-blue-800">
									If a plugin or theme breaks after enabling minification, add its file paths here. 
									You can exclude entire directories by using partial paths like <code className="bg-blue-100 px-2 py-1 rounded">/wp-content/plugins/plugin-name/</code>
								</p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	);
};
