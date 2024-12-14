import React, { useState } from 'react';
import { CheckboxOption, handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';

const FileOptimization = ({ options = {} }) => {
	const defaultSettings = {
		minifyJS: false,
		excludeJS: '',
		minifyCSS: false,
		excludeCSS: '',
		combineCSS: false,
		excludeCombineCSS: '',
		removeWooCSSJS: false,
		excludeUrlToKeepJSCSS: "shop/(.*)\nproduct/(.*)\nmy-account/(.*)\ncart/(.*)\ncheckout/(.*)",
		removeCssJsHandle: "style: woocommerce-layout\nstyle: woocommerce-general\nstyle: woocommerce-smallscreen\nstyle: wc-blocks-style\nscript: woocommerce\nscript: wc-cart-fragments\nscript: wc-add-to-cart\nscript: jquery-blockui\nscript: wc-order-attribution\nscript: sourcebuster-js",
		minifyHTML: false,
		deferJS: false,
		excludeDeferJS: '',
		delayJS: false,
		excludeDelayJS: '',
		...options,
	}

	const [settings, setSettings] = useState(defaultSettings);
	const [isLoading, setIsLoading] = useState(false);

	const handleSubmit = async (e) => {
		e.preventDefault();
		setIsLoading(true);

		try {
			// Submit settings (mock function for now)
			console.log('Form Submitted:', settings);
			await apiCall('update_settings', { tab: 'file_optimisation', settings });
		} catch (error) {
			console.error('Form submission error:', error);
		} finally {
			setIsLoading(false);
		}
	};

	return (
		<form onSubmit={handleSubmit} className="settings-form">
			<h2>File Optimization Settings</h2>

			<CheckboxOption
				label="Minify JavaScript"
				checked={settings.minifyJS}
				onChange={handleChange(setSettings)}
				name="minifyJS"
				textareaName='excludeJS'
				textareaPlaceholder="Exclude specific JavaScript files"
				textareaValue={settings.excludeJS}
				onTextareaChange={handleChange(setSettings)}
			/>

			<CheckboxOption
				label="Minify CSS"
				checked={settings.minifyCSS}
				onChange={handleChange(setSettings)}
				name="minifyCSS"
				textareaName='excludeCSS'
				textareaPlaceholder="Exclude specific CSS files"
				textareaValue={settings.excludeCSS}
				onTextareaChange={handleChange(setSettings)}
			/>

			<CheckboxOption
				label="Combine CSS"
				checked={settings.combineCSS}
				onChange={handleChange(setSettings)}
				name="combineCSS"
				textareaName='excludeCombineCSS'
				textareaPlaceholder="Exclude CSS files to combine"
				textareaValue={settings.excludeCombineCSS}
				onTextareaChange={handleChange(setSettings)}
			/>

			<CheckboxOption
				label="Remove woocommerce css and js from other page"
				checked={settings.removeWooCSSJS}
				onChange={handleChange(setSettings)}
				name="removeWooCSSJS"
			/>

			{/* Show these text areas only if removeWooCSSJS is checked */}
			{settings.removeWooCSSJS && (
				<div className='checkbox-option'>
					<textarea
						className="text-area-field"
						placeholder="Exclude Url to keep woocommerce css and js"
						name="excludeUrlToKeepJSCSS"
						value={settings.excludeUrlToKeepJSCSS}
						onChange={handleChange(setSettings)}
					/>
					<textarea
						className="text-area-field"
						placeholder="Enter handle which script and style you want to remove"
						name="removeCssJsHandle"
						value={settings.removeCssJsHandle}
						onChange={handleChange(setSettings)}
					/>
				</div>
			)}

			<CheckboxOption
				label="Minify HTML"
				checked={settings.minifyHTML}
				onChange={handleChange(setSettings)}
				name="minifyHTML"
			/>

			<CheckboxOption
				label="Defer Loading JavaScript"
				checked={settings.deferJS}
				onChange={handleChange(setSettings)}
				name="deferJS"
				textareaName='excludeDeferJS'
				textareaPlaceholder="Exclude specific JavaScript files"
				textareaValue={settings.excludeDeferJS}
				onTextareaChange={handleChange(setSettings)}
			/>

			<CheckboxOption
				label="Delay Loading JavaScript"
				checked={settings.delayJS}
				onChange={handleChange(setSettings)}
				name="delayJS"
				textareaName='excludeDelayJS'
				textareaPlaceholder="Exclude specific JavaScript files"
				textareaValue={settings.excludeDelayJS}
				onTextareaChange={handleChange(setSettings)}
			/>

			<button type="submit" className="submit-button" disabled={isLoading}>
				{isLoading ? 'Saving...' : 'Save Settings'}
			</button>
		</form>
	);
};

export default FileOptimization;
