import React, { useState } from 'react';
import { handleChange, handleSubmit } from '../lib/formUtils';

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
	const onSubmit = async (e) => {
		e.preventDefault();
		setIsLoading(true); // Start the loading state

		try {
			await handleSubmit(settings, 'file_optimisation');
		} catch (error) {
			console.error('Form submission error:', error);
		} finally {
			setIsLoading(false);
		}
	}
	return (
		<form onSubmit={onSubmit} className="settings-form">
			<h2>File Optimization</h2>

			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="minifyJS"
						checked={settings.minifyJS}
						onChange={handleChange(setSettings)}
					/>
					Minify JavaScript
				</label>
				{settings.minifyJS && (
					<textarea
						className="text-area-field"
						placeholder="Exclude specific JavaScript files"
						name="excludeJS"
						value={settings.excludeJS}
						onChange={handleChange(setSettings)}
					/>
				)}
			</div>

			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="minifyCSS"
						checked={settings.minifyCSS}
						onChange={handleChange(setSettings)}
					/>
					Minify CSS
				</label>
				{settings.minifyCSS && (
					<textarea
						className="text-area-field"
						placeholder="Exclude specific CSS files"
						name="excludeCSS"
						value={settings.excludeCSS}
						onChange={handleChange(setSettings)}
					/>
				)}
			</div>

			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="combineCSS"
						checked={settings.combineCSS}
						onChange={handleChange(setSettings)}
					/>
					Combine CSS
				</label>
				{settings.combineCSS && (
					<textarea
						className="text-area-field"
						placeholder="Exclude CSS files to combine"
						name="excludeCombineCSS"
						value={settings.excludeCombineCSS}
						onChange={handleChange(setSettings)}
					/>
				)}
			</div>

			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="removeWooCSSJS"
						checked={settings.removeWooCSSJS}
						onChange={handleChange(setSettings)}
					/>
					Remove woocommerce css and js from other page
				</label>
				{settings.removeWooCSSJS && (
					<div>
						<textarea
							className="text-area-field"
							placeholder="Exclude Url to keep woocommerce css and js"
							name="excludeUrlToKeepJSCSS"
							value={settings.excludeUrlToKeepJSCSS}
							onChange={handleChange(setSettings)}
						/>
						<textarea
							className="text-area-field"
							placeholder="Enter handal which script and style you want to remove"
							name="removeCssJsHandle"
							value={settings.removeCssJsHandle}
							onChange={handleChange(setSettings)}
						/>
					</div>
				)}
			</div>

			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="minifyHTML"
						checked={settings.minifyHTML}
						onChange={handleChange(setSettings)}
					/>
					Minify HTML
				</label>
			</div>

			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="deferJS"
						checked={settings.deferJS}
						onChange={handleChange(setSettings)}
					/>
					Defer Loading JavaScript
				</label>
				{settings.deferJS && (
					<textarea
						className="text-area-field"
						placeholder="Exclude specific JavaScript files"
						name="excludeDeferJS"
						value={settings.excludeDeferJS}
						onChange={handleChange(setSettings)}
					/>
				)}
			</div>

			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="delayJS"
						checked={settings.delayJS}
						onChange={handleChange(setSettings)}
					/>
					Delay Loading JavaScript
				</label>
				{settings.delayJS && (
					<textarea
						className="text-area-field"
						placeholder="Exclude specific JavaScript files"
						name="excludeDelayJS"
						value={settings.excludeDelayJS}
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
export default FileOptimization;
