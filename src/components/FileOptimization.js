import React, { useState } from 'react';
import { CheckboxOption, handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';

const FileOptimization = ({ options = {} }) => {
	const translations = wppoSettings.translations;

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
			console.log(translations.formSubmitted, settings);
			await apiCall('update_settings', { tab: 'file_optimisation', settings });
		} catch (error) {
			console.error(translations.formSubmissionError, error);
		} finally {
			setIsLoading(false);
		}
	};

	return (
		<form onSubmit={handleSubmit} className="settings-form">
			<h2>{translations.fileOptimizationSettings}</h2>

			<CheckboxOption
				label={translations.minifyJS}
				checked={settings.minifyJS}
				onChange={handleChange(setSettings)}
				name='minifyJS'
				textareaName='excludeJS'
				textareaPlaceholder={translations.excludeJSFiles}
				textareaValue={settings.excludeJS}
				onTextareaChange={handleChange(setSettings)}
			/>

			<CheckboxOption
				label={translations.minifyCSS}
				checked={settings.minifyCSS}
				onChange={handleChange(setSettings)}
				name="minifyCSS"
				textareaName='excludeCSS'
				textareaPlaceholder={translations.excludeCSSFiles}
				textareaValue={settings.excludeCSS}
				onTextareaChange={handleChange(setSettings)}
			/>

			<CheckboxOption
				label={translations.combineCSS}
				checked={settings.combineCSS}
				onChange={handleChange(setSettings)}
				name="combineCSS"
				textareaName='excludeCombineCSS'
				textareaPlaceholder={translations.excludeCombineCSS}
				textareaValue={settings.excludeCombineCSS}
				onTextareaChange={handleChange(setSettings)}
			/>

			<CheckboxOption
				label={translations.removeWooCSSJS}
				checked={settings.removeWooCSSJS}
				onChange={handleChange(setSettings)}
				name="removeWooCSSJS"
			/>

			{/* Show these text areas only if removeWooCSSJS is checked */}
			{settings.removeWooCSSJS && (
				<div className='checkbox-option'>
					<textarea
						className="text-area-field"
						placeholder={translations.excludeUrlToKeepJSCSS}
						name="excludeUrlToKeepJSCSS"
						value={settings.excludeUrlToKeepJSCSS}
						onChange={handleChange(setSettings)}
					/>
					<textarea
						className="text-area-field"
						placeholder={translations.removeCssJsHandle}
						name="removeCssJsHandle"
						value={settings.removeCssJsHandle}
						onChange={handleChange(setSettings)}
					/>
				</div>
			)}

			<CheckboxOption
				label={translations.minifyHTML}
				checked={settings.minifyHTML}
				onChange={handleChange(setSettings)}
				name="minifyHTML"
			/>

			<CheckboxOption
				label={translations.deferJS}
				checked={settings.deferJS}
				onChange={handleChange(setSettings)}
				name="deferJS"
				textareaName='excludeDeferJS'
				textareaPlaceholder={translations.excludeDeferJS}
				textareaValue={settings.excludeDeferJS}
				onTextareaChange={handleChange(setSettings)}
			/>

			<CheckboxOption
				label={translations.delayJS}
				checked={settings.delayJS}
				onChange={handleChange(setSettings)}
				name="delayJS"
				textareaName='excludeDelayJS'
				textareaPlaceholder={translations.excludeDelayJS}
				textareaValue={settings.excludeDelayJS}
				onTextareaChange={handleChange(setSettings)}
			/>

			<button type="submit" className="submit-button" disabled={isLoading}>
				{isLoading ? translations.saving : translations.saveSettings}
			</button>
		</form>
	);
};

export default FileOptimization;
