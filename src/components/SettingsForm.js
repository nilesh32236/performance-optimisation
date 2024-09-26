// src/components/SettingsForm.js
import React, { useState } from 'react';
import CheckboxOption from './CheckboxOption';

const SettingsForm = ({ onSaveSettings, defaultSettings }) => {
	const [settings, setSettings] = useState(defaultSettings || {
		deferJS: false,
		delayJS: false,
		minifyJS: false,
		minifyCSS: false,
		preloadCache: false,
		minifyHTML: false,
		inlineJSandCSS: false,
		lazyLoadImages: false,
		optimizeImages: false,
		convertToWebP: false,
	});

	const handleChange = (e) => {
		const { name, checked } = e.target;
		setSettings((prevState) => ({
			...prevState,
			[name]: checked,
		}));
	};

	const handleSubmit = (e) => {
		e.preventDefault();
		onSaveSettings(settings);
	};

	return (
		<form onSubmit={handleSubmit} className="settings-form">
			<div className="settings-sections">
				<div className="settings-section">
					<h2>General Settings</h2>
					<CheckboxOption
						label="Defer JavaScript Loading"
						description="Defer loading of JavaScript to improve the loading time of your pages."
						name="deferJS"
						checked={settings.deferJS}
						onChange={handleChange}
					/>

					<CheckboxOption
						label="Delay JavaScript Execution"
						description="Delay JavaScript execution until user interaction."
						name="delayJS"
						checked={settings.delayJS}
						onChange={handleChange}
					/>

					<CheckboxOption
						label="Minify JavaScript"
						description="Minify JavaScript files to reduce the size and improve performance."
						name="minifyJS"
						checked={settings.minifyJS}
						onChange={handleChange}
					/>

					<CheckboxOption
						label="Minify CSS"
						description="Minify CSS files to improve loading times."
						name="minifyCSS"
						checked={settings.minifyCSS}
						onChange={handleChange}
					/>

					<CheckboxOption
						label="Preload Cache"
						description="Preload the cache to improve page load times."
						name="preloadCache"
						checked={settings.preloadCache}
						onChange={handleChange}
					/>
				</div>

				<div className="settings-section">
					<h2>Cache Settings</h2>

					<CheckboxOption
						label="Minify HTML"
						description="Minify HTML files and inline JavaScript/CSS to optimize page size."
						name="minifyHTML"
						checked={settings.minifyHTML}
						onChange={handleChange}
					/>

					<CheckboxOption
						label="Lazy Load Images"
						description="Lazy load images to improve the initial load speed."
						name="lazyLoadImages"
						checked={settings.lazyLoadImages}
						onChange={handleChange}
					/>

					<CheckboxOption
						label="Optimize Images"
						description="Automatically optimize images on your site."
						name="optimizeImages"
						checked={settings.optimizeImages}
						onChange={handleChange}
					/>

					<CheckboxOption
						label="Convert Images to WebP"
						description="Convert images to WebP format to reduce image size while maintaining quality."
						name="convertToWebP"
						checked={settings.convertToWebP}
						onChange={handleChange}
					/>

					<button type="submit" className="submit-button">Save Settings</button>
					<button
						type="button"
						className="clear-cache-btn"
						onClick={() => {
							fetch(qtpoSettings.apiUrl + '/performance-optimisation/v1/clear-cache', {
								method: 'POST',
								headers: {
									'Content-Type': 'application/json',
									'X-WP-Nonce': qtpoSettings.nonce,
								}
							})
								.then(response => response.json())
								.then(data => {
									if (data.success) {
										alert('Cache cleared successfully!');
									} else {
										alert('Failed to clear cache.');
									}
								});
						}}
					>
						Clear Cache
					</button>
				</div>
			</div>
		</form>
	);
};

export default SettingsForm;
