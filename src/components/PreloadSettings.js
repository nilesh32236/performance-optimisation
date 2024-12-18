import React, { useState } from 'react';
import { CheckboxOption, handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';

const PreloadSettings = ({ options = {} }) => {
	const translations = wppoSettings.translations;

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
			console.error(translations.formSubmissionError, error);
		} finally {
			setIsLoading(false);
		}
	}
	return (
		<form onSubmit={handleSubmit} className="settings-form">
			<h2>{translations.preloadSettings}</h2>

			{/* Preload Cache */}
			<CheckboxOption
				label={translations.enablePreloadCache}
				checked={settings.enablePreloadCache}
				onChange={handleChange(setSettings)}
				name="enablePreloadCache"
				textareaName='excludePreloadCache'
				textareaPlaceholder={translations.excludePreloadCache}
				textareaValue={settings.excludePreloadCache}
				onTextareaChange={handleChange(setSettings)}
				description={translations.enablePreloadCacheDesc}
			/>

			<CheckboxOption
				label={translations.preconnect}
				checked={settings.preconnect}
				onChange={handleChange(setSettings)}
				name='preconnect'
				textareaName='preconnectOrigins'
				textareaPlaceholder={translations.preconnectOrigins}
				textareaValue={settings.preconnectOrigins}
				onTextareaChange={handleChange(setSettings)}
				description={translations.preconnectDesc}
			/>

			{/* DNS Prefetch */}
			<CheckboxOption
				label={translations.prefetchDNS}
				checked={settings.prefetchDNS}
				onChange={handleChange(setSettings)}
				name='prefetchDNS'
				textareaName='dnsPrefetchOrigins'
				textareaPlaceholder={translations.dnsPrefetchOrigins}
				textareaValue={settings.dnsPrefetchOrigins}
				onTextareaChange={handleChange(setSettings)}
				description={translations.prefetchDNSDesc}
			/>

			{/* Preload Fonts */}
			<CheckboxOption
				label={translations.preloadFonts}
				checked={settings.preloadFonts}
				onChange={handleChange(setSettings)}
				name='preloadFonts'
				textareaName='preloadFontsUrls'
				textareaPlaceholder={translations.preloadFontsUrls}
				textareaValue={settings.preloadFontsUrls}
				onTextareaChange={handleChange(setSettings)}
				description={translations.preloadFontsDesc}
			/>

			{/* Preload CSS */}
			<CheckboxOption
				label={translations.preloadCSS}
				checked={settings.preloadCSS}
				onChange={handleChange(setSettings)}
				name='preloadCSS'
				textareaName='preloadCSSUrls'
				textareaPlaceholder={translations.preloadCSSUrls}
				textareaValue={settings.preloadCSSUrls}
				onTextareaChange={handleChange(setSettings)}
				description={translations.preloadCSSDesc}
			/>

			<button type="submit" className="submit-button" disabled={isLoading}>
				{isLoading ? translations.saving : translations.saveSettings}
			</button>
		</form>
	);
};

export default PreloadSettings;
