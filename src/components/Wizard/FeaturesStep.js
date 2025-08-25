/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import FeatureToggle from './FeatureToggle';

function FeaturesStep({
	translations,
	preloadCache,
	imageConversion,
	onFeatureChange,
	onNext,
	onBack,
}) {
	const features = [
		{
			id: 'preloadCache',
			label: 'Cache Preloading',
			description:
				translations?.preloadCache ||
				'Automatically prepare cached versions of your pages for faster delivery.',
			longDescription:
				'This runs in the background to ensure visitors always get the fastest version of your site. Recommended for most websites.',
			value: preloadCache,
			recommended: true,
		},
		{
			id: 'imageConversion',
			label: 'Modern Image Formats',
			description:
				translations?.imageConversion ||
				'Automatically convert uploaded images to modern, faster formats (like WebP).',
			longDescription:
				'This makes your images smaller without losing quality. Your original images are kept as a backup.',
			value: imageConversion,
			recommended: true,
		},
	];

	return (
		<div className="wppo-wizard-step wppo-features-step">
			<div className="wppo-step-header">
				<h2>Advanced Features</h2>
				<p>
					Enable these powerful features to supercharge your website&apos;s performance.
					Both are safe and can be disabled later if needed.
				</p>
			</div>

			<div className="wppo-features-list">
				{features.map(feature => (
					<FeatureToggle
						key={feature.id}
						feature={feature}
						onChange={value => onFeatureChange(feature.id, value)}
						translations={translations}
					/>
				))}
			</div>

			<div className="wppo-features-note">
				<div className="wppo-note-icon">
					<span className="dashicons dashicons-info"></span>
				</div>
				<div className="wppo-note-content">
					<strong>Good to know:</strong> These features are completely optional but highly
					recommended. They work automatically in the background and can significantly
					improve your site&apos;s speed.
				</div>
			</div>

			<div className="wppo-wizard-actions">
				<button type="button" className="button button-secondary" onClick={onBack}>
					{translations?.previousStep || 'Back'}
				</button>

				<button type="button" className="button button-primary" onClick={onNext}>
					{translations?.nextStep || 'Next'}
				</button>
			</div>
		</div>
	);
}

export default FeaturesStep;
