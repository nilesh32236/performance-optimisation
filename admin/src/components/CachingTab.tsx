import React, { useState, useEffect } from 'react';
import { Dashicon } from '@wordpress/components';

interface CacheStats {
	page_cache: {
		enabled: boolean;
		files: number;
		size: string;
		hit_rate: number;
	};
	browser_cache: {
		enabled: boolean;
		rules_count: number;
		htaccess_writable: boolean;
	};
}

interface CacheSettings {
	page_cache_enabled: boolean;
	browser_cache_enabled: boolean;
	cache_preload_enabled: boolean;
	cache_compression: boolean;
	cache_mobile_separate: boolean;
	cache_exclusions?: {
		urls?: string[];
		cookies?: string[];
		user_roles?: string[];
		query_strings?: string[];
		user_agents?: string[];
		post_types?: string[];
	};
}

export const CachingTab: React.FC = () => {
	const [loading, setLoading] = useState(true);
	const [saving, setSaving] = useState(false);
	const [stats, setStats] = useState<CacheStats | null>(null);
	const [settings, setSettings] = useState<CacheSettings>({
		page_cache_enabled: true,
		browser_cache_enabled: false,
		cache_preload_enabled: false,
		cache_compression: true,
		cache_mobile_separate: false,
		cache_exclusions: {
			urls: [],
			cookies: ['wordpress_logged_in_', 'wp-postpass_', 'comment_author_'],
			user_roles: [],
			query_strings: [],
			user_agents: [],
			post_types: [],
		},
	});
	const [notification, setNotification] = useState<{ type: 'success' | 'error', message: string } | null>(null);

	useEffect(() => {
		fetchCacheData();
	}, []);

	const fetchCacheData = async () => {
		try {
			const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
			const statsResponse = await fetch(`${apiUrl}/cache/stats`, {
				headers: { 'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '' },
			});

			if (statsResponse.ok) {
				const statsData = await statsResponse.json();
				setStats(statsData.data);
			}

			const settingsResponse = await fetch(`${apiUrl}/settings`, {
				headers: { 'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '' },
			});

			if (settingsResponse.ok) {
				const settingsData = await settingsResponse.json();
				if (settingsData.success && settingsData.data.settings?.cache_settings) {
					setSettings(settingsData.data.settings.cache_settings);
				}
			}
		} catch (error) {
			console.error('Failed to fetch cache data:', error);
			showNotification('error', 'Failed to load cache data');
		} finally {
			setLoading(false);
		}
	};

	const handleClearCache = async (type: string) => {
		if (!confirm(`Are you sure you want to clear ${type} cache?`)) return;

		setLoading(true);
		try {
			const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';
			const response = await fetch(`${apiUrl}/cache/clear`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '',
				},
				body: JSON.stringify({ type: type.toLowerCase() }),
			});

			const data = await response.json();
			if (data.success) {
				showNotification('success', data.message);
				await fetchCacheData();
			} else {
				showNotification('error', data.message || 'Failed to clear cache');
			}
		} catch (error) {
			showNotification('error', 'An error occurred while clearing cache');
		} finally {
			setLoading(false);
		}
	};

	const handleSaveSettings = async () => {
		setSaving(true);
		try {
			const apiUrl = (window as any).wppoAdmin?.apiUrl || '/wp-json/performance-optimisation/v1';

			// First, get the current settings
			const currentSettingsResponse = await fetch(`${apiUrl}/settings`, {
				headers: {
					'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '',
					'Cache-Control': 'no-cache'
				},
			});

			let currentSettings = {};
			if (currentSettingsResponse.ok) {
				const data = await currentSettingsResponse.json();
				if (data.success && data.data.settings) {
					currentSettings = data.data.settings;
				}
			}

			// Merge the current settings with our new cache settings
			const updatedSettings = {
				...currentSettings,
				cache_settings: settings
			};

			// Send the merged settings
			const response = await fetch(`${apiUrl}/settings`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': (window as any).wppoAdmin?.nonce || '',
				},
				body: JSON.stringify({
					settings: updatedSettings,
					merge: true  // Ensure we're merging with existing settings
				}),
			});

			const data = await response.json();
			if (data.success) {
				showNotification('success', 'Settings saved successfully!');
				// Refresh the data to ensure UI is in sync
				await fetchCacheData();
			} else {
				showNotification('error', data.message || 'Failed to save settings');
			}
		} catch (error) {
			console.error('Save settings error:', error);
			showNotification('error', 'An error occurred while saving settings');
		} finally {
			setSaving(false);
		}
	};

	const showNotification = (type: 'success' | 'error', message: string) => {
		setNotification({ type, message });
		setTimeout(() => setNotification(null), 5000);
	};

	const updateSetting = (key: keyof CacheSettings, value: boolean) => {
		setSettings(prev => ({ ...prev, [key]: value }));
	};

	if (loading && !stats) {
		return (
			<div className="flex items-center justify-center p-12">
				<div className="text-center">
					<div className="animate-spin w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full mx-auto mb-4"></div>
					<p className="text-base text-gray-600">Loading cache data...</p>
				</div>
			</div>
		);
	}

	return (
		<div className="space-y-8">
			{notification && (
				<div className={`p-4 rounded-lg border-2 ${notification.type === 'success'
						? 'bg-green-50 border-green-200 text-green-800'
						: 'bg-red-50 border-red-200 text-red-800'
					}`}>
					<p className="text-base font-semibold">{notification.message}</p>
				</div>
			)}

			<div>
				<h2 className="text-3xl font-bold text-gray-900 mb-2">Cache Management</h2>
				<p className="text-base text-gray-600">Manage your site's caching system to improve performance</p>
			</div>

			<div className="grid grid-cols-1 md:grid-cols-3 gap-6">
				<div className="bg-white rounded-xl border-2 border-gray-200 p-6">
					<div className="flex items-center justify-between mb-4">
						<div className="w-12 h-12 bg-blue-500 rounded-lg flex items-center justify-center">
							<Dashicon icon="admin-page" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<span className={`px-3 py-1 text-sm font-semibold rounded-full ${stats?.page_cache.enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'
							}`}>
							{stats?.page_cache.enabled ? 'Active' : 'Inactive'}
						</span>
					</div>
					<h3 className="text-lg font-bold text-gray-900 mb-2">Page Cache</h3>
					<div className="space-y-2 mb-4">
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Files Cached</span>
							<span className="text-base font-bold text-gray-900">{stats?.page_cache.files || 0}</span>
						</div>
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Cache Size</span>
							<span className="text-base font-bold text-gray-900">{stats?.page_cache.size || '0 B'}</span>
						</div>
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Hit Rate</span>
							<span className="text-base font-bold text-blue-600">{stats?.page_cache.hit_rate || 0}%</span>
						</div>
					</div>
					<button
						onClick={() => handleClearCache('page')}
						disabled={loading}
						className="w-full px-4 py-3 bg-blue-500 text-white text-base font-semibold rounded-lg hover:bg-blue-600 transition-colors disabled:opacity-50"
					>
						{loading ? 'Clearing...' : 'Clear Page Cache'}
					</button>
				</div>

				<div className="bg-white rounded-xl border-2 border-gray-200 p-6 opacity-60">
					<div className="flex items-center justify-between mb-4">
						<div className="w-12 h-12 bg-purple-500 rounded-lg flex items-center justify-center">
							<Dashicon icon="database" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<span className="px-3 py-1 bg-gray-100 text-gray-600 text-sm font-semibold rounded-full">Coming Soon</span>
					</div>
					<h3 className="text-lg font-bold text-gray-900 mb-2">Object Cache</h3>
					<div className="space-y-2 mb-4">
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Backend</span>
							<span className="text-base font-bold text-gray-900">None</span>
						</div>
					</div>
					<button disabled className="w-full px-4 py-3 bg-gray-300 text-gray-500 text-base font-semibold rounded-lg cursor-not-allowed">
						Not Available
					</button>
				</div>

				<div className="bg-white rounded-xl border-2 border-gray-200 p-6">
					<div className="flex items-center justify-between mb-4">
						<div className="w-12 h-12 bg-green-500 rounded-lg flex items-center justify-center">
							<Dashicon icon="admin-site" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<span className={`px-3 py-1 text-sm font-semibold rounded-full ${stats?.browser_cache.enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'
							}`}>
							{stats?.browser_cache.enabled ? 'Active' : 'Inactive'}
						</span>
					</div>
					<h3 className="text-lg font-bold text-gray-900 mb-2">Browser Cache</h3>
					<div className="space-y-2 mb-4">
						<div className="flex justify-between">
							<span className="text-base text-gray-600">Cache Rules</span>
							<span className="text-base font-bold text-gray-900">{stats?.browser_cache.rules_count || 0}</span>
						</div>
						<div className="flex justify-between">
							<span className="text-base text-gray-600">.htaccess</span>
							<span className={`text-base font-bold ${stats?.browser_cache.htaccess_writable ? 'text-green-600' : 'text-red-600'}`}>
								{stats?.browser_cache.htaccess_writable ? 'Writable' : 'Not Writable'}
							</span>
						</div>
						<div className="flex justify-between">
							<span className="text-base text-gray-600">File Types</span>
							<span className="text-base font-bold text-gray-900">6 Categories</span>
						</div>
					</div>
					<button
						onClick={() => fetchCacheData()}
						disabled={loading}
						className="w-full px-4 py-3 bg-green-500 text-white text-base font-semibold rounded-lg hover:bg-green-600 transition-colors disabled:opacity-50"
					>
						{loading ? 'Refreshing...' : 'Refresh Status'}
					</button>
				</div>
			</div>

			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h2 className="text-2xl font-bold text-gray-900 mb-6">Cache Settings</h2>
				<div className="space-y-6">
					{[
						{ key: 'page_cache_enabled', title: 'Enable Page Caching', desc: 'Cache full HTML pages for faster loading' },
						{ key: 'browser_cache_enabled', title: 'Enable Browser Caching', desc: 'Set cache headers for static assets (CSS, JS, images)' },
						{ key: 'cache_preload_enabled', title: 'Cache Preloading', desc: 'Automatically generate cache for important pages' },
						{ key: 'cache_compression', title: 'GZIP Compression', desc: 'Compress cached files to save bandwidth' },
						{ key: 'cache_mobile_separate', title: 'Mobile Cache', desc: 'Separate cache for mobile devices' },
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
									checked={settings[setting.key as keyof CacheSettings]}
									onChange={(e) => updateSetting(setting.key as keyof CacheSettings, e.target.checked)}
								/>
								<div className="w-14 h-8 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-6 peer-checked:after:border-white after:content-[''] after:absolute after:top-1 after:left-1 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-500"></div>
							</label>
						</div>
					))}
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
						onClick={fetchCacheData}
						className="px-6 py-3 bg-gray-200 text-gray-700 text-base font-semibold rounded-lg hover:bg-gray-300 transition-colors"
					>
						Refresh Data
					</button>
				</div>
			</div>

			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h2 className="text-2xl font-bold text-gray-900 mb-2">Cache Exclusions</h2>
				<p className="text-base text-gray-600 mb-6">Specify URLs, cookies, user roles, and other conditions to exclude from caching</p>

				<div className="space-y-6">
					<div>
						<label className="block text-base font-semibold text-gray-900 mb-2">
							Never Cache URLs (one per line)
						</label>
						<textarea
							rows={4}
							className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
							placeholder="/cart/&#10;/checkout/&#10;/my-account/*&#10;/wp-admin/*"
							value={settings.cache_exclusions?.urls?.join('\n') || ''}
							onChange={(e) => {
								const urls = e.target.value.split('\n').filter(u => u.trim());
								updateSetting('cache_exclusions', { ...settings.cache_exclusions, urls });
							}}
						/>
						<p className="text-sm text-gray-600 mt-2">Use * as wildcard. Example: /products/*</p>
					</div>

					<div>
						<label className="block text-base font-semibold text-gray-900 mb-2">
							Never Cache Cookies (one per line)
						</label>
						<textarea
							rows={3}
							className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
							placeholder="wordpress_logged_in_&#10;wp-postpass_&#10;comment_author_&#10;woocommerce_cart_hash"
							value={settings.cache_exclusions?.cookies?.join('\n') || ''}
							onChange={(e) => {
								const cookies = e.target.value.split('\n').filter(c => c.trim());
								updateSetting('cache_exclusions', { ...settings.cache_exclusions, cookies });
							}}
						/>
						<p className="text-sm text-gray-600 mt-2">Don't cache when these cookies are present</p>
					</div>

					<div className="grid grid-cols-1 md:grid-cols-2 gap-6">
						<div>
							<label className="block text-base font-semibold text-gray-900 mb-2">
								Exclude User Roles (one per line)
							</label>
							<textarea
								rows={3}
								className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
								placeholder="administrator&#10;editor&#10;author"
								value={settings.cache_exclusions?.user_roles?.join('\n') || ''}
								onChange={(e) => {
									const roles = e.target.value.split('\n').filter(r => r.trim());
									updateSetting('cache_exclusions', { ...settings.cache_exclusions, user_roles: roles });
								}}
							/>
							<p className="text-sm text-gray-600 mt-2">Don't cache for these user roles</p>
						</div>

						<div>
							<label className="block text-base font-semibold text-gray-900 mb-2">
								Exclude Query Strings (one per line)
							</label>
							<textarea
								rows={3}
								className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
								placeholder="utm_source&#10;utm_campaign&#10;fbclid&#10;gclid"
								value={settings.cache_exclusions?.query_strings?.join('\n') || ''}
								onChange={(e) => {
									const params = e.target.value.split('\n').filter(p => p.trim());
									updateSetting('cache_exclusions', { ...settings.cache_exclusions, query_strings: params });
								}}
							/>
							<p className="text-sm text-gray-600 mt-2">Don't cache URLs with these parameters</p>
						</div>
					</div>

					<div className="grid grid-cols-1 md:grid-cols-2 gap-6">
						<div>
							<label className="block text-base font-semibold text-gray-900 mb-2">
								Exclude User Agents (one per line)
							</label>
							<textarea
								rows={3}
								className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
								placeholder="Mobile&#10;Bot&#10;Googlebot"
								value={settings.cache_exclusions?.user_agents?.join('\n') || ''}
								onChange={(e) => {
									const agents = e.target.value.split('\n').filter(a => a.trim());
									updateSetting('cache_exclusions', { ...settings.cache_exclusions, user_agents: agents });
								}}
							/>
							<p className="text-sm text-gray-600 mt-2">Don't cache for these user agents</p>
						</div>

						<div>
							<label className="block text-base font-semibold text-gray-900 mb-2">
								Exclude Post Types (one per line)
							</label>
							<textarea
								rows={3}
								className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
								placeholder="product&#10;event&#10;download"
								value={settings.cache_exclusions?.post_types?.join('\n') || ''}
								onChange={(e) => {
									const types = e.target.value.split('\n').filter(t => t.trim());
									updateSetting('cache_exclusions', { ...settings.cache_exclusions, post_types: types });
								}}
							/>
							<p className="text-sm text-gray-600 mt-2">Don't cache these post types</p>
						</div>
					</div>
				</div>
			</div>

			{stats?.browser_cache.enabled && (
				<div className="bg-gradient-to-r from-green-50 to-blue-50 rounded-xl border-2 border-green-200 p-8">
					<div className="flex items-start gap-4">
						<div className="w-12 h-12 bg-green-500 rounded-lg flex items-center justify-center flex-shrink-0">
							<Dashicon icon="yes-alt" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<div className="flex-1">
							<h3 className="text-xl font-bold text-gray-900 mb-2">Browser Cache Active</h3>
							<p className="text-base text-gray-700 mb-4">
								Browser caching is enabled and optimizing your static assets. The following file types are being cached:
							</p>
							<div className="grid grid-cols-2 md:grid-cols-3 gap-3">
								{[
									{ type: 'Images', duration: '1 year', files: 'jpg, png, gif, webp, svg' },
									{ type: 'Fonts', duration: '1 year', files: 'woff, woff2, ttf, otf' },
									{ type: 'CSS', duration: '1 month', files: 'css' },
									{ type: 'JavaScript', duration: '1 month', files: 'js' },
									{ type: 'Media', duration: '1 year', files: 'mp4, mp3, webm' },
									{ type: 'Documents', duration: '1 week', files: 'pdf, doc, xls' },
								].map((item) => (
									<div key={item.type} className="bg-white rounded-lg p-3 border border-green-200">
										<div className="font-semibold text-gray-900 text-sm mb-1">{item.type}</div>
										<div className="text-xs text-green-600 font-medium mb-1">{item.duration}</div>
										<div className="text-xs text-gray-600">{item.files}</div>
									</div>
								))}
							</div>
							<div className="mt-4 p-4 bg-white rounded-lg border border-green-200">
								<p className="text-sm text-gray-700">
									<strong>Performance Impact:</strong> Browser caching reduces server load by 80-90% and improves page load times by 50-70% for returning visitors.
								</p>
							</div>
						</div>
					</div>
				</div>
			)}
		</div>
	);
};
