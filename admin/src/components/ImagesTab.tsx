import React, { useState, useEffect } from 'react';
import { Dashicon } from '@wordpress/components';

interface ImageStats {
	total_images: number;
	optimized_images: number;
	pending_images: number;
	total_savings: {
		savings_bytes: number;
		savings_percentage: number;
	};
}

interface ImageSettings {
	auto_convert_on_upload: boolean;
	webp_conversion: boolean;
	avif_conversion: boolean;
	quality: number;
	lazy_load_enabled: boolean;
	exclude_first_images: number;
	exclude_images: string;
	use_svg_placeholder: boolean;
	serve_next_gen: boolean;
	max_width: number;
}

export const ImagesTab: React.FC = () => {
	const [loading, setLoading] = useState(true);
	const [saving, setSaving] = useState(false);
	const [optimizing, setOptimizing] = useState(false);
	const [stats, setStats] = useState<ImageStats | null>(null);
	const [settings, setSettings] = useState<ImageSettings>({
		auto_convert_on_upload: true,
		webp_conversion: true,
		avif_conversion: false,
		quality: 82,
		lazy_load_enabled: true,
		exclude_first_images: 2,
		exclude_images: '',
		use_svg_placeholder: true,
		serve_next_gen: true,
		max_width: 1920,
	});
	const [notification, setNotification] = useState<{type: 'success' | 'error', message: string} | null>(null);

	useEffect(() => {
		fetchImageData();
	}, []);

	const fetchImageData = async () => {
		try {
			const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
			
			const statsResponse = await fetch(`${apiUrl}/images/stats`, {
				headers: { 'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '' },
			});
			
			if (statsResponse.ok) {
				const statsData = await statsResponse.json();
				if (statsData.success) {
					setStats(statsData.data);
				}
			}

			const settingsResponse = await fetch(`${apiUrl}/settings`, {
				headers: { 'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '' },
			});

			if (settingsResponse.ok) {
				const settingsData = await settingsResponse.json();
				if (settingsData.success && settingsData.data.settings?.image_optimization) {
					setSettings(settingsData.data.settings.image_optimization);
				}
			}
		} catch (error) {
			console.error('Failed to fetch image data:', error);
			showNotification('error', 'Failed to load image data');
		} finally {
			setLoading(false);
		}
	};

	const handleSaveSettings = async () => {
		setSaving(true);
		try {
			const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
			const response = await fetch(`${apiUrl}/settings`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '',
				},
				body: JSON.stringify({ 
					settings: {
						image_optimization: settings
					}
				}),
			});

			const data = await response.json();
			if (data.success) {
				showNotification('success', 'Settings saved successfully!');
			} else {
				showNotification('error', data.message || 'Failed to save settings');
			}
		} catch (error) {
			showNotification('error', 'An error occurred while saving settings');
		} finally {
			setSaving(false);
		}
	};

	const handleOptimizeAll = async () => {
		if (!confirm('Start optimizing all pending images? This may take a few minutes.')) return;

		setOptimizing(true);
		try {
			const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
			const response = await fetch(`${apiUrl}/images/batch-optimize`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '',
				},
				body: JSON.stringify({
					limit: 50,
					options: { quality: settings.quality },
				}),
			});

			const data = await response.json();
			if (data.success) {
				showNotification('success', data.message || 'Optimization started successfully!');
				setTimeout(() => fetchImageData(), 2000);
			} else {
				showNotification('error', data.message || 'Failed to start optimization');
			}
		} catch (error) {
			showNotification('error', 'An error occurred while optimizing images');
		} finally {
			setOptimizing(false);
		}
	};

	const showNotification = (type: 'success' | 'error', message: string) => {
		setNotification({ type, message });
		setTimeout(() => setNotification(null), 5000);
	};

	const updateSetting = <K extends keyof ImageSettings>(key: K, value: ImageSettings[K]) => {
		setSettings(prev => ({ ...prev, [key]: value }));
	};

	const formatBytes = (bytes: number) => {
		if (bytes === 0) return '0 B';
		const k = 1024;
		const sizes = ['B', 'KB', 'MB', 'GB'];
		const i = Math.floor(Math.log(bytes) / Math.log(k));
		return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
	};

	if (loading && !stats) {
		return (
			<div className="flex items-center justify-center p-12">
				<div className="text-center">
					<div className="animate-spin w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full mx-auto mb-4"></div>
					<p className="text-base text-gray-600">Loading image data...</p>
				</div>
			</div>
		);
	}

	return (
		<div className="space-y-8">
			{notification && (
				<div className={`p-4 rounded-lg border-2 ${
					notification.type === 'success' 
						? 'bg-green-50 border-green-200 text-green-800' 
						: 'bg-red-50 border-red-200 text-red-800'
				}`}>
					<p className="text-base font-semibold">{notification.message}</p>
				</div>
			)}

			<div>
				<h2 className="text-3xl font-bold text-gray-900 mb-2">Image Optimization</h2>
				<p className="text-base text-gray-600">Compress and convert images to modern formats</p>
			</div>

			{/* Image Stats */}
			<div className="grid grid-cols-1 md:grid-cols-4 gap-6">
				{[
					{ title: 'Total Images', value: stats?.total_images || 0, icon: 'format-image', color: 'blue' },
					{ title: 'Optimized', value: stats?.optimized_images || 0, icon: 'yes-alt', color: 'green' },
					{ title: 'Pending', value: stats?.pending_images || 0, icon: 'clock', color: 'orange' },
					{ title: 'Space Saved', value: stats ? formatBytes(stats.total_savings?.savings_bytes || 0) : '0 B', icon: 'database', color: 'purple' },
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
					<button 
						onClick={handleOptimizeAll}
						disabled={optimizing || (stats?.pending_images || 0) === 0}
						className="p-6 border-2 border-blue-200 bg-blue-50 rounded-xl hover:bg-blue-100 transition-colors text-left disabled:opacity-50 disabled:cursor-not-allowed"
					>
						<div className="w-12 h-12 bg-blue-500 rounded-lg flex items-center justify-center mb-4">
							<Dashicon icon={optimizing ? 'update' : 'format-image'} style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<h3 className="text-lg font-bold text-gray-900 mb-2">
							{optimizing ? 'Optimizing...' : 'Optimize All Images'}
						</h3>
						<p className="text-base text-gray-600 mb-4">Compress all unoptimized images in your media library</p>
						<span className="text-sm font-semibold text-blue-600">{stats?.pending_images || 0} images pending</span>
					</button>

					<div className="p-6 border-2 border-gray-200 bg-white rounded-xl">
						<div className="w-12 h-12 bg-green-500 rounded-lg flex items-center justify-center mb-4">
							<Dashicon icon="image-rotate" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<h3 className="text-lg font-bold text-gray-900 mb-2">Auto-Convert Enabled</h3>
						<p className="text-base text-gray-600 mb-4">New uploads automatically converted to WebP{settings.avif_conversion ? '/AVIF' : ''}</p>
						<span className="text-sm font-semibold text-green-600">Save up to 40% size</span>
					</div>
				</div>
			</div>

			{/* Image Settings */}
			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h2 className="text-2xl font-bold text-gray-900 mb-6">Image Settings</h2>
				
				{/* Format Conversion */}
				<div className="mb-8">
					<h3 className="text-lg font-semibold text-gray-900 mb-4">Format Conversion</h3>
					<div className="space-y-4">
						{[
							{ key: 'auto_convert_on_upload', title: 'Auto-Convert on Upload', desc: 'Automatically convert images when uploaded' },
							{ key: 'webp_conversion', title: 'WebP Conversion', desc: 'Create WebP versions (25-35% smaller)' },
							{ key: 'avif_conversion', title: 'AVIF Conversion', desc: 'Create AVIF versions (40-50% smaller, Chrome 85+)' },
							{ key: 'serve_next_gen', title: 'Serve Next-Gen Formats', desc: 'Automatically serve WebP/AVIF based on browser support' },
						].map((setting) => (
							<div key={setting.key} className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
								<div className="flex-1">
									<h4 className="text-base font-semibold text-gray-900 mb-1">{setting.title}</h4>
									<p className="text-sm text-gray-600">{setting.desc}</p>
								</div>
								<label className="relative inline-flex items-center cursor-pointer ml-4">
									<input 
										type="checkbox" 
										className="sr-only peer" 
										checked={settings[setting.key as keyof ImageSettings] as boolean}
										onChange={(e) => updateSetting(setting.key as keyof ImageSettings, e.target.checked as any)}
									/>
									<div className="w-14 h-8 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-6 peer-checked:after:border-white after:content-[''] after:absolute after:top-1 after:left-1 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-500"></div>
								</label>
							</div>
						))}
					</div>
				</div>

				{/* Lazy Loading */}
				<div className="mb-8">
					<h3 className="text-lg font-semibold text-gray-900 mb-4">Lazy Loading</h3>
					<div className="space-y-4">
						{[
							{ key: 'lazy_load_enabled', title: 'Enable Lazy Loading', desc: 'Load images only when they appear in viewport' },
							{ key: 'use_svg_placeholder', title: 'SVG Placeholder', desc: 'Use SVG placeholders instead of blank images' },
						].map((setting) => (
							<div key={setting.key} className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
								<div className="flex-1">
									<h4 className="text-base font-semibold text-gray-900 mb-1">{setting.title}</h4>
									<p className="text-sm text-gray-600">{setting.desc}</p>
								</div>
								<label className="relative inline-flex items-center cursor-pointer ml-4">
									<input 
										type="checkbox" 
										className="sr-only peer" 
										checked={settings[setting.key as keyof ImageSettings] as boolean}
										onChange={(e) => updateSetting(setting.key as keyof ImageSettings, e.target.checked as any)}
									/>
									<div className="w-14 h-8 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-6 peer-checked:after:border-white after:content-[''] after:absolute after:top-1 after:left-1 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-500"></div>
								</label>
							</div>
						))}

						<div className="p-4 bg-gray-50 rounded-lg">
							<label className="text-sm font-medium text-gray-700 mb-2 block">
								Exclude First N Images: {settings.exclude_first_images}
							</label>
							<input 
								type="range" 
								min="0" 
								max="10" 
								value={settings.exclude_first_images}
								onChange={(e) => updateSetting('exclude_first_images', parseInt(e.target.value))}
								className="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer" 
							/>
							<p className="text-xs text-gray-500 mt-1">Skip lazy loading for the first few images (above-the-fold)</p>
						</div>

						<div className="p-4 bg-gray-50 rounded-lg">
							<label className="text-sm font-medium text-gray-700 mb-2 block">Exclude Images (one per line)</label>
							<textarea 
								value={settings.exclude_images}
								onChange={(e) => updateSetting('exclude_images', e.target.value)}
								placeholder="logo.png&#10;header-image.jpg"
								rows={3}
								className="w-full px-4 py-2 border-2 border-gray-200 rounded-lg text-base"
							/>
							<p className="text-xs text-gray-500 mt-1">Images containing these strings won't be lazy loaded</p>
						</div>
					</div>
				</div>

				{/* Quality Settings */}
				<div className="p-4 bg-blue-50 border-2 border-blue-200 rounded-lg">
					<h4 className="text-base font-semibold text-gray-900 mb-4">Quality Settings</h4>
					<div className="space-y-4">
						<div>
							<label className="text-sm font-medium text-gray-700 mb-2 block">
								Compression Quality: {settings.quality}%
							</label>
							<input 
								type="range" 
								min="50" 
								max="100" 
								value={settings.quality}
								onChange={(e) => updateSetting('quality', parseInt(e.target.value))}
								className="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer" 
							/>
							<div className="flex justify-between text-xs text-gray-500 mt-1">
								<span>Smaller size (50%)</span>
								<span>Better quality (100%)</span>
							</div>
						</div>
						<div>
							<label className="text-sm font-medium text-gray-700 mb-2 block">Max Image Width (px)</label>
							<input 
								type="number" 
								value={settings.max_width}
								onChange={(e) => updateSetting('max_width', parseInt(e.target.value))}
								className="w-full px-4 py-2 border-2 border-gray-200 rounded-lg text-base" 
							/>
							<p className="text-xs text-gray-500 mt-1">Images wider than this will be resized</p>
						</div>
					</div>
				</div>

				<div className="mt-8 flex gap-4">
					<button 
						onClick={handleSaveSettings}
						disabled={saving}
						className="px-6 py-3 bg-blue-500 text-white text-base font-semibold rounded-lg hover:bg-blue-600 transition-colors disabled:opacity-50"
					>
						{saving ? 'Saving...' : 'Save Settings'}
					</button>
					<button 
						onClick={fetchImageData}
						className="px-6 py-3 bg-gray-200 text-gray-700 text-base font-semibold rounded-lg hover:bg-gray-300 transition-colors"
					>
						Refresh Data
					</button>
				</div>
			</div>
		</div>
	);
};
