import React, { useState } from 'react';
import { CheckboxOption } from '../lib/util';

const FileOptimization = ({ options }) => {
	const [settings, setSettings] = useState({
		minifyJS: options?.minifyJS || false,
		excludeJS: options?.excludeJS || '',
		minifyCSS: options?.minifyCSS || false,
		excludeCSS: options?.excludeCSS || '',
		combineCSS: options?.combineCSS || false,
		excludeCombineCSS: options?.excludeCombineCSS || '',
		removeWooCSSJS: options?.removeWooCSSJS || false,
		excludeUrlToKeepJSCSS: options?.excludeUrlToKeepJSCSS || "shop/(.*)\nproduct/(.*)\nmy-account/(.*)\ncart/(.*)\ncheckout/(.*)",
		removeCssJsHandle: options?.removeCssJsHandle || "style: woocommerce-layout\nstyle: woocommerce-general\nstyle: woocommerce-smallscreen\nstyle: wc-blocks-style\nscript: woocommerce\nscript: wc-cart-fragments\nscript: wc-add-to-cart\nscript: jquery-blockui\nscript: wc-order-attribution\nscript: sourcebuster-js",
		minifyHTML: options?.minifyHTML || false,
		deferJS: options?.deferJS || false,
		excludeDeferJS: options?.excludeDeferJS || '',
		delayJS: options?.delayJS || false,
		excludeDelayJS: options?.excludeDelayJS || '',
	});
	const [isLoading, setIsLoading] = useState(false);

	const handleChange = (e) => {
		const { name, value, type, checked } = e.target;
		setSettings((prevState) => ({
			...prevState,
			[name]: type === 'checkbox' ? checked : value,
		}));
	};

	const handleSubmit = async (e) => {
		e.preventDefault();
		setIsLoading(true);

		try {
			// Submit settings (mock function for now)
			console.log('Form Submitted:', settings);
			// await apiCall(settings, 'file_optimisation'); // Replace with real API call
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
				onChange={handleChange}
				name="minifyJS"
				textareaPlaceholder="Exclude specific JavaScript files"
				textareaValue={settings.excludeJS}
				onTextareaChange={handleChange}
			/>

			<CheckboxOption
				label="Minify CSS"
				checked={settings.minifyCSS}
				onChange={handleChange}
				name="minifyCSS"
				textareaPlaceholder="Exclude specific CSS files"
				textareaValue={settings.excludeCSS}
				onTextareaChange={handleChange}
			/>

			<CheckboxOption
				label="Combine CSS"
				checked={settings.combineCSS}
				onChange={handleChange}
				name="combineCSS"
				textareaPlaceholder="Exclude CSS files to combine"
				textareaValue={settings.excludeCombineCSS}
				onTextareaChange={handleChange}
			/>

			<CheckboxOption
				label="Remove woocommerce css and js from other page"
				checked={settings.removeWooCSSJS}
				onChange={handleChange}
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
						onChange={handleChange}
					/>
					<textarea
						className="text-area-field"
						placeholder="Enter handle which script and style you want to remove"
						name="removeCssJsHandle"
						value={settings.removeCssJsHandle}
						onChange={handleChange}
					/>
				</div>
			)}

			<CheckboxOption
				label="Minify HTML"
				checked={settings.minifyHTML}
				onChange={handleChange}
				name="minifyHTML"
			/>

			<CheckboxOption
				label="Defer Loading JavaScript"
				checked={settings.deferJS}
				onChange={handleChange}
				name="deferJS"
				textareaPlaceholder="Exclude specific JavaScript files"
				textareaValue={settings.excludeDeferJS}
				onTextareaChange={handleChange}
			/>

			<CheckboxOption
				label="Delay Loading JavaScript"
				checked={settings.delayJS}
				onChange={handleChange}
				name="delayJS"
				textareaPlaceholder="Exclude specific JavaScript files"
				textareaValue={settings.excludeDelayJS}
				onTextareaChange={handleChange}
			/>

			<button type="submit" className="submit-button" disabled={isLoading}>
				{isLoading ? 'Saving...' : 'Save Settings'}
			</button>
		</form>
	);
};

export default FileOptimization;
