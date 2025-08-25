/**
 * External dependencies
 */
import React from 'react';

function SummaryStep({ translations, selections, isLoading, onFinish, onBack }) {
	const { preset, preloadCache, imageConversion } = selections;

	const getPresetDisplayName = presetId => {
		switch (presetId) {
			case 'standard':
				return translations?.standardPreset || 'Standard (Safe)';
			case 'recommended':
				return translations?.recommendedPreset || 'Recommended (Balanced)';
			case 'aggressive':
				return translations?.aggressivePreset || 'Aggressive (Maximum Speed)';
			default:
				return presetId;
		}
	};

	const getPresetFeatures = presetId => {
		const baseFeatures = ['Page Caching', 'Image Lazy Loading'];

		switch (presetId) {
			case 'standard':
				return baseFeatures;
			case 'recommended':
				return [...baseFeatures, 'CSS & HTML Minification', 'Combine CSS Files'];
			case 'aggressive':
				return [
					...baseFeatures,
					'CSS & HTML Minification',
					'Combine CSS Files',
					'JavaScript Minification',
					'Defer JavaScript',
					'Delay JavaScript',
				];
			default:
				return baseFeatures;
		}
	};

	const presetFeatures = getPresetFeatures(preset);
	const additionalFeatures = [];

	if (preloadCache) {
		additionalFeatures.push('Cache Preloading');
	}

	if (imageConversion) {
		additionalFeatures.push('Modern Image Formats (WebP)');
	}

	return (
		<div className="wppo-wizard-step wppo-summary-step">
			<div className="wppo-step-header">
				<h2>Setup Summary</h2>
				<p>
					Review your selections below. Once you click &quot;Finish Setup&quot;, these
					optimizations will be applied to your website.
				</p>
			</div>

			<div className="wppo-summary-content">
				<div className="wppo-summary-section">
					<h3>
						<span className="dashicons dashicons-admin-settings"></span>
						Optimization Level
					</h3>
					<div className="wppo-summary-value">
						<strong>{getPresetDisplayName(preset)}</strong>
					</div>
					<div className="wppo-summary-features">
						<h4>Includes:</h4>
						<ul>
							{presetFeatures.map((feature, index) => (
								<li key={index}>
									<span className="dashicons dashicons-yes-alt"></span>
									{feature}
								</li>
							))}
						</ul>
					</div>
				</div>

				{additionalFeatures.length > 0 && (
					<div className="wppo-summary-section">
						<h3>
							<span className="dashicons dashicons-star-filled"></span>
							Additional Features
						</h3>
						<ul className="wppo-summary-features">
							{additionalFeatures.map((feature, index) => (
								<li key={index}>
									<span className="dashicons dashicons-yes-alt"></span>
									{feature}
								</li>
							))}
						</ul>
					</div>
				)}

				<div className="wppo-summary-note">
					<div className="wppo-note-icon">
						<span className="dashicons dashicons-info"></span>
					</div>
					<div className="wppo-note-content">
						<strong>What happens next?</strong>
						<ul>
							<li>Your settings will be saved automatically</li>
							<li>Cache will be cleared to apply new optimizations</li>
							<li>You can modify these settings anytime from the dashboard</li>
							<li>Your website will start loading faster immediately</li>
						</ul>
					</div>
				</div>
			</div>

			<div className="wppo-wizard-navigation">
				<div className="wppo-nav-buttons">
					<button
						type="button"
						className="wppo-wizard-button wppo-wizard-button--secondary"
						onClick={onBack}
						disabled={isLoading}
					>
						<span className="dashicons dashicons-arrow-left-alt2"></span>
						{translations?.previousStep || 'Back'}
					</button>

					<button
						type="button"
						className="wppo-wizard-button wppo-wizard-button--large"
						onClick={onFinish}
						disabled={isLoading}
						aria-describedby={isLoading ? 'setup-progress' : undefined}
					>
						{isLoading ? (
							<div className="wppo-wizard-loading">
								<span className="wppo-spinner" aria-hidden="true"></span>
								<span id="setup-progress">Setting up...</span>
							</div>
						) : (
							<>
								{translations?.finishSetup ||
									'Finish Setup &amp; Start Optimizing'}
								<span className="dashicons dashicons-yes-alt"></span>
							</>
						)}
					</button>
				</div>
			</div>
		</div>
	);
}

export default SummaryStep;
