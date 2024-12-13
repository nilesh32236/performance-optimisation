import React, { useState } from 'react';
import { handleChange, handleSubmit } from '../lib/formUtils';
import { CheckboxOption } from '../lib/util';

const PreloadSettings = ({ options }) => {
	const [settings, setSettings] = useState({
		enablePreloadCache: options?.enablePreloadCache || false,
		excludePreloadCache: options?.excludePreloadCache || '',
		preconnect: options?.preconnect || false,
		preconnectOrigins: options?.preconnectOrigins || '',
		prefetchDNS: options?.prefetchDNS || false,
		dnsPrefetchOrigins: options?.dnsPrefetchOrigins || '',
		preloadFonts: options?.preloadFonts || false,
		preloadFontsUrls: options?.preloadFontsUrls || '',
		preloadCSS: options?.preloadCSS || false,
		preloadCSSUrls: options?.preloadCSSUrls || '',
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
			<CheckboxOption
				label="Enable Preloading Cache"
				checked={settings.enablePreloadCache}
				onChange={handleChange(setSettings)}
				name="enablePreloadCache"
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
