// src/components/FileOptimization/FileOptimization.js

import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faFileCode, faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';

const FileOptimization = ({
	settings, // The whole settings object from App.js
	onUpdateSettings, // Function to update settings: (tabKey, settingKey, value)
	translations,
	// isLoading, // If needed for a save button specific to this tab
}) => {
	// Destructure the specific settings for this tab for easier access
	const fileOptSettings = settings.file_optimisation || {};

	const handleChange = (settingKey, value, type = 'checkbox') => {
		let processedValue = value;
		if (type === 'checkbox') {
			processedValue = !!value; // Ensure boolean
		}
		onUpdateSettings('file_optimisation', settingKey, processedValue);
	};

	return (
		<div className="wppo-settings-form wppo-file-optimization-settings">
			<h2 className="wppo-section-title">
				<FontAwesomeIcon icon={faFileCode} style={{ marginRight: '10px' }} />
				{translations.fileOptimization || 'File Optimization'}
			</h2>
			<p className="wppo-section-description">
				{translations.fileOptimizationDesc || 'Configure minification and loading strategies for your CSS, JavaScript, and HTML files.'}
			</p>

			{/* Minify JS */}
			<div className="wppo-form-section">
				<div className="wppo-field-group wppo-checkbox-option">
					<input
						type="checkbox"
						id="minifyJS"
						checked={fileOptSettings.minifyJS || false}
						onChange={(e) => handleChange('minifyJS', e.target.checked)}
					/>
					<label htmlFor="minifyJS">{translations.minifyJS || 'Minify JavaScript Files'}</label>
				</div>
				{fileOptSettings.minifyJS && (
					<div className="wppo-sub-fields">
						<label htmlFor="excludeJS" className="wppo-label">{translations.excludeJSFiles || 'Exclude JavaScript Files/Keywords from Minification:'}</label>
						<textarea
							id="excludeJS"
							className="wppo-text-area-field"
							value={fileOptSettings.excludeJS || ''}
							onChange={(e) => handleChange('excludeJS', e.target.value, 'textarea')}
							rows="3"
							placeholder={translations.excludeJSHelpText || "e.g., jquery.js, /wp-includes/js/, specific-plugin.js"}
						/>
						<p className="wppo-option-description">{translations.excludeJSDesc || 'Enter parts of filenames, paths, or keywords (one per line). Handles listed here will also be excluded.'}</p>
					</div>
				)}
				<div className="wppo-field-group wppo-checkbox-option">
					<input
						type="checkbox"
						id="minifyInlineJS"
						checked={fileOptSettings.minifyInlineJS || false}
						onChange={(e) => handleChange('minifyInlineJS', e.target.checked)}
					/>
					<label htmlFor="minifyInlineJS">{translations.minifyInlineJS || 'Minify Inline JavaScript'}</label>
				</div>
			</div>

			{/* Minify CSS */}
			<div className="wppo-form-section">
				<div className="wppo-field-group wppo-checkbox-option">
					<input
						type="checkbox"
						id="minifyCSS"
						checked={fileOptSettings.minifyCSS || false}
						onChange={(e) => handleChange('minifyCSS', e.target.checked)}
					/>
					<label htmlFor="minifyCSS">{translations.minifyCSS || 'Minify CSS Files'}</label>
				</div>
				{fileOptSettings.minifyCSS && (
					<div className="wppo-sub-fields">
						<label htmlFor="excludeCSS" className="wppo-label">{translations.excludeCSSFiles || 'Exclude CSS Files/Keywords from Minification:'}</label>
						<textarea
							id="excludeCSS"
							className="wppo-text-area-field"
							value={fileOptSettings.excludeCSS || ''}
							onChange={(e) => handleChange('excludeCSS', e.target.value, 'textarea')}
							rows="3"
							placeholder={translations.excludeCSSHelpText || "e.g., admin-bar.css, /plugins/some-plugin/style.css"}
						/>
						<p className="wppo-option-description">{translations.excludeCSSDesc || 'Enter parts of filenames, paths, or keywords (one per line). Handles listed here will also be excluded.'}</p>
					</div>
				)}
				<div className="wppo-field-group wppo-checkbox-option">
					<input
						type="checkbox"
						id="minifyInlineCSS"
						checked={fileOptSettings.minifyInlineCSS || false}
						onChange={(e) => handleChange('minifyInlineCSS', e.target.checked)}
					/>
					<label htmlFor="minifyInlineCSS">{translations.minifyInlineCSS || 'Minify Inline CSS'}</label>
				</div>
			</div>

			{/* Combine CSS */}
			<div className="wppo-form-section">
				<div className="wppo-field-group wppo-checkbox-option">
					<input
						type="checkbox"
						id="combineCSS"
						checked={fileOptSettings.combineCSS || false}
						onChange={(e) => handleChange('combineCSS', e.target.checked)}
					/>
					<label htmlFor="combineCSS">{translations.combineCSS || 'Combine CSS Files'}</label>
				</div>
				{fileOptSettings.combineCSS && (
					<div className="wppo-sub-fields">
						<label htmlFor="excludeCombineCSS" className="wppo-label">{translations.excludeCombineCSS || 'Exclude CSS Files/Keywords from Combination:'}</label>
						<textarea
							id="excludeCombineCSS"
							className="wppo-text-area-field"
							value={fileOptSettings.excludeCombineCSS || ''}
							onChange={(e) => handleChange('excludeCombineCSS', e.target.value, 'textarea')}
							rows="3"
							placeholder={translations.excludeCombineCSSHelpText || "e.g., critical.css, /themes/my-theme/specific.css"}
						/>
						<p className="wppo-option-description">{translations.excludeCombineCSSDesc || 'Enter parts of filenames, paths, or keywords (one per line). These files will be loaded individually.'}</p>
					</div>
				)}
			</div>

			{/* Minify HTML */}
			<div className="wppo-form-section">
				<div className="wppo-field-group wppo-checkbox-option">
					<input
						type="checkbox"
						id="minifyHTML"
						checked={fileOptSettings.minifyHTML || false}
						onChange={(e) => handleChange('minifyHTML', e.target.checked)}
					/>
					<label htmlFor="minifyHTML">{translations.minifyHTML || 'Minify HTML Output'}</label>
				</div>
				<p className="wppo-option-description" style={{ marginLeft: '25px', marginTop: '-10px' }}>
					{translations.minifyHTMLDesc || 'Removes unnecessary whitespace and comments from HTML to reduce page size.'}
				</p>
			</div>

			{/* Defer JS */}
			<div className="wppo-form-section">
				<div className="wppo-field-group wppo-checkbox-option">
					<input
						type="checkbox"
						id="deferJS"
						checked={fileOptSettings.deferJS || false}
						onChange={(e) => handleChange('deferJS', e.target.checked)}
					/>
					<label htmlFor="deferJS">{translations.deferJS || 'Defer Non-Essential JavaScript'}</label>
				</div>
				{fileOptSettings.deferJS && (
					<div className="wppo-sub-fields">
						<label htmlFor="excludeDeferJS" className="wppo-label">{translations.excludeDeferJS || 'Exclude JavaScript Files/Keywords from Deferring:'}</label>
						<textarea
							id="excludeDeferJS"
							className="wppo-text-area-field"
							value={fileOptSettings.excludeDeferJS || ''}
							onChange={(e) => handleChange('excludeDeferJS', e.target.value, 'textarea')}
							rows="3"
							placeholder={translations.excludeDeferJSHelpText || "e.g., jquery.js, analytics.js"}
						/>
						<p className="wppo-option-description">{translations.excludeDeferJSDesc || 'Scripts critical for initial page render should be excluded.'}</p>
					</div>
				)}
			</div>

			{/* Delay JS */}
			<div className="wppo-form-section">
				<div className="wppo-field-group wppo-checkbox-option">
					<input
						type="checkbox"
						id="delayJS"
						checked={fileOptSettings.delayJS || false}
						onChange={(e) => handleChange('delayJS', e.target.checked)}
					/>
					<label htmlFor="delayJS">{translations.delayJS || 'Delay JavaScript Execution'}</label>
				</div>
				{fileOptSettings.delayJS && (
					<div className="wppo-sub-fields">
						<label htmlFor="excludeDelayJS" className="wppo-label">{translations.excludeDelayJS || 'Exclude JavaScript Files/Keywords from Delaying:'}</label>
						<textarea
							id="excludeDelayJS"
							className="wppo-text-area-field"
							value={fileOptSettings.excludeDelayJS || ''}
							onChange={(e) => handleChange('excludeDelayJS', e.target.value, 'textarea')}
							rows="3"
							placeholder={translations.excludeDelayJSHelpText || "e.g., crucial-script.js"}
						/>
						<p className="wppo-option-description">{translations.excludeDelayJSDesc || 'Delays execution until user interaction. Exclude scripts needed immediately.'}</p>
						<p className="wppo-option-description wppo-warning-text">
							<FontAwesomeIcon icon={faExclamationTriangle} /> {translations.delayJSWarning || 'Warning: Delaying JavaScript can affect site functionality if not configured carefully. Test thoroughly.'}
						</p>
					</div>
				)}
			</div>

			{/* WooCommerce Optimizations */}
			<div className="wppo-form-section">
				<div className="wppo-field-group wppo-checkbox-option">
					<input
						type="checkbox"
						id="removeWooCSSJS"
						checked={fileOptSettings.removeWooCSSJS || false}
						onChange={(e) => handleChange('removeWooCSSJS', e.target.checked)}
					/>
					<label htmlFor="removeWooCSSJS">{translations.removeWooCSSJS || 'Remove WooCommerce Assets on Non-Woo Pages'}</label>
				</div>
				{fileOptSettings.removeWooCSSJS && (
					<div className="wppo-sub-fields">
						<label htmlFor="excludeUrlToKeepJSCSS" className="wppo-label">{translations.excludeUrlToKeepJSCSS || 'Exclude URLs Where WooCommerce Assets Should Be Kept:'}</label>
						<textarea
							id="excludeUrlToKeepJSCSS"
							className="wppo-text-area-field"
							value={fileOptSettings.excludeUrlToKeepJSCSS || ''}
							onChange={(e) => handleChange('excludeUrlToKeepJSCSS', e.target.value, 'textarea')}
							rows="3"
							placeholder={translations.excludeUrlToKeepJSCSSHelpText || "e.g., /my-custom-shop-page/, /product-showcase/(.*)"}
						/>
						<p className="wppo-option-description">{translations.excludeUrlToKeepJSCSSDesc || 'Enter URL paths (one per line). Use (.*) as a wildcard for subpaths.'}</p>

						<label htmlFor="removeCssJsHandle" className="wppo-label" style={{ marginTop: '15px' }}>{translations.removeCssJsHandle || 'WooCommerce Handles to Remove (Advanced):'}</label>
						<textarea
							id="removeCssJsHandle"
							className="wppo-text-area-field"
							value={fileOptSettings.removeCssJsHandle || ''}
							onChange={(e) => handleChange('removeCssJsHandle', e.target.value, 'textarea')}
							rows="3"
							placeholder={translations.removeCssJsHandleHelpText || "e.g., style:woocommerce-general\nscript:wc-cart-fragments\n(Defaults will be used if empty)"}
						/>
						<p className="wppo-option-description">{translations.removeCssJsHandleDesc || 'Prefix with "style:" or "script:". If empty, default WooCommerce handles will be targeted.'}</p>
					</div>
				)}
			</div>

		</div>
	);
};

export default FileOptimization;