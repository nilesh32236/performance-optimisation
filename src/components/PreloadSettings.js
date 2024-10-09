import React, { useState } from 'react';
import { handleChange, handleSubmit } from '../lib/formUtils';

const PreloadSettings = ({ options }) => {
	const [settings, setSettings] = useState({
		enablePreloadCache: options?.enablePreloadCache || false,
		excludePreloadCache: options?.excludePreloadCache || '',
		preconnect: options?.preconnect || false,
		preconnectOrigins: options?.preconnectOrigins || '',
		prefetchDNS: options?.prefetchDNS || false,
		dnsPrefetchOrigins: options?.dnsPrefetchOrigins || '',
		preloadFonts: options?.preloadFonts || false,
		preloadFontsUrls: options?.preloadFontsUrls || ''
	});

	const [isLoading, setIsLoading] = useState(false);
	const onSubmit = async (e) => {
		e.preventDefault();
		setIsLoading(true); // Start the loading state

		try {
			await handleSubmit(settings, 'preload_settings');
		} catch (error) {
			console.error('Form submission error:', error);
		} finally {
			setIsLoading(false);
		}
	}
	return (
		<form onSubmit={onSubmit} className="settings-form">
			<h2>Preload Settings</h2>

			{/* Preload Cache */}
			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="enablePreloadCache"
						checked={settings.enablePreloadCache}
						onChange={handleChange(setSettings)}
					/>
					Enable Preloading Cache
				</label>
				<p className="option-description">
					Preload the cache to improve page load times by caching key resources.
				</p>
				{settings.enablePreloadCache && (
					<textarea
						className="text-area-field"
						placeholder="Exclude specific resources from preloading"
						name="excludePreloadCache"
						value={settings.excludePreloadCache}
						onChange={handleChange(setSettings)}
					/>
				)}
			</div>

			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="preconnect"
						checked={settings.preconnect}
						onChange={handleChange(setSettings)}
					/>
					Preconnect
				</label>
				<p className="option-description">
					Add origins to preconnect, improving the speed of resource loading.
				</p>
				{settings.preconnect && (
					<textarea
						className="text-area-field"
						placeholder="Add preconnect origins, one per line (e.g., https://fonts.gstatic.com)"
						name="preconnectOrigins"
						value={settings.preconnectOrigins}
						onChange={handleChange(setSettings)}
					/>
				)}
			</div>


			{/* DNS Prefetch */}
			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="prefetchDNS"
						checked={settings.prefetchDNS}
						onChange={handleChange(setSettings)}
					/>
					Prefetch DNS
				</label>
				<p className="option-description">
					Prefetch DNS for external domains to reduce DNS lookup times.
				</p>
				{settings.prefetchDNS && (
					<textarea
						className="text-area-field"
						placeholder="Enter domains for DNS prefetching, one per line (e.g., https://example.com)"
						name="dnsPrefetchOrigins"
						value={settings.dnsPrefetchOrigins}
						onChange={handleChange(setSettings)}
					/>
				)}
			</div>

			{/* Preload Fonts */}
			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="preloadFonts"
						checked={settings.preloadFonts}
						onChange={handleChange(setSettings)}
					/>
					Preload Fonts
				</label>
				<p className="option-description">
					Preload fonts to ensure faster loading and rendering of text.
				</p>
				{settings.preloadFonts && (
					<textarea
						className="text-area-field"
						placeholder={`Enter fonts for preloading, one per line (e.g., https://example.com/fonts/font.woff2)\n/your-theme/fonts/font.woff2`}
						name="preloadFontsUrls"
						value={settings.preloadFontsUrls}
						onChange={handleChange(setSettings)}
					/>
				)}
			</div>

			<button type="submit" className="submit-button" disabled={isLoading}>
				{isLoading ? 'Saving...' : 'Save Settings'}
			</button>
		</form>
	);
};

export default PreloadSettings;
