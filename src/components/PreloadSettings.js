import React, { useState } from 'react';
import { CheckboxOption, handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';

const PreloadSettings = ({ options = {} }) => {
	const defaultSettings = {
		enablePreloadCache: false,
		excludePreloadCache: '',
		preconnect: false,
		preconnectOrigins: '',
		prefetchDNS: false,
		dnsPrefetchOrigins: '',
		preloadFonts: false,
		preloadFontsUrls: '',
		preloadCSS: false,
		preloadCSSUrls: '',
		...options
	}

	const [settings, setSettings] = useState(defaultSettings);
	const [isLoading, setIsLoading] = useState(false);

	const handleSubmit = async (e) => {
		e.preventDefault();
		setIsLoading(true); // Start the loading state

		try {
			await apiCall('update_settings', { tab: 'preload_settings', settings });
		} catch (error) {
			console.error('Form submission error:', error);
		} finally {
			setIsLoading(false);
		}
	}
	return (
		<form onSubmit={handleSubmit} className="settings-form">
			<h2>Preload Settings</h2>

			{/* Preload Cache */}
			<CheckboxOption
				label="Enable Preloading Cache"
				checked={settings.enablePreloadCache}
				onChange={handleChange(setSettings)}
				name="enablePreloadCache"
				textareaName='excludePreloadCache'
				textareaPlaceholder="Exclude specific resources from preloading"
				textareaValue={settings.excludePreloadCache}
				onTextareaChange={handleChange(setSettings)}
				description="Preload the cache to improve page load times by caching key resources."
			/>

			<CheckboxOption
				label='Preconnect'
				checked={settings.preconnect}
				onChange={handleChange(setSettings)}
				name='preconnect'
				textareaName='preconnectOrigins'
				textareaPlaceholder='Add preconnect origins, one per line (e.g., https://fonts.gstatic.com)'
				textareaValue={settings.preconnectOrigins}
				onTextareaChange={handleChange(setSettings)}
				description='Add origins to preconnect, improving the speed of resource loading.'
			/>

			{/* DNS Prefetch */}
			<CheckboxOption
				label='Prefetch DNS'
				checked={settings.prefetchDNS}
				onChange={handleChange(setSettings)}
				name='prefetchDNS'
				textareaName='dnsPrefetchOrigins'
				textareaPlaceholder='Enter domains for DNS prefetching, one per line (e.g., https://example.com)'
				textareaValue={settings.dnsPrefetchOrigins}
				onTextareaChange={handleChange(setSettings)}
				description='Prefetch DNS for external domains to reduce DNS lookup times.'
			/>

			{/* Preload Fonts */}
			<CheckboxOption
				label='Preload Fonts'
				checked={settings.preloadFonts}
				onChange={handleChange(setSettings)}
				name='preloadFonts'
				textareaName='preloadFontsUrls'
				textareaPlaceholder="Enter fonts for preloading, one per line (e.g., https://example.com/fonts/font.woff2)\n/your-theme/fonts/font.woff2"
				textareaValue={settings.preloadFontsUrls}
				onTextareaChange={handleChange(setSettings)}
				description='Preload fonts to ensure faster loading and rendering of text.'
			/>

			{/* Preload CSS */}
			<CheckboxOption
				label='Preload CSS'
				checked={settings.preloadCSS}
				onChange={handleChange(setSettings)}
				name='preloadCSS'
				textareaName='preloadCSSUrls'
				textareaPlaceholder="Enter CSS for preloading, one per line (e.g., https://example.com/style.css)\n/your-theme/css/style.css"
				textareaValue={settings.preloadCSSUrls}
				onTextareaChange={handleChange(setSettings)}
				description='Preload CSS to ensure faster rendering and style application'
			/>

			<button type="submit" className="submit-button" disabled={isLoading}>
				{isLoading ? 'Saving...' : 'Save Settings'}
			</button>
		</form>
	);
};

export default PreloadSettings;
