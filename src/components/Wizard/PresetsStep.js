/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import PresetCard from './PresetCard';

function PresetsStep({ translations, selectedPreset, onPresetChange, onNext, onBack }) {
	const presets = [
		{
			id: 'standard',
			title: translations?.standardPreset || 'Standard (Safe)',
			description: 'Basic improvements that are safe for all sites. A great starting point.',
			features: ['Page Caching', 'Image Lazy Loading'],
			isRecommended: false,
		},
		{
			id: 'recommended',
			title: translations?.recommendedPreset || 'Recommended (Balanced)',
			description:
				'The best balance of speed and compatibility for most websites. Includes asset optimization.',
			features: [
				'Page Caching',
				'Image Lazy Loading',
				'CSS & HTML Minification',
				'Combine CSS Files',
			],
			isRecommended: true,
		},
		{
			id: 'aggressive',
			title: translations?.aggressivePreset || 'Aggressive (Maximum Speed)',
			description:
				'For maximum performance. May require testing, as some scripts can be sensitive.',
			features: [
				'All Recommended features',
				'JavaScript Minification',
				'Defer JavaScript',
				'Delay JavaScript',
			],
			isRecommended: false,
			hasWarning: true,
		},
	];

	const canProceed = selectedPreset !== '';

	return (
		<div
			className="wppo-wizard-step wppo-presets-step"
			role="region"
			aria-labelledby="presets-title"
		>
			<div className="wppo-step-header">
				<h2 id="presets-title">Choose Your Optimization Level</h2>
				<p className="wppo-step-description">
					Select the optimization preset that best fits your website&apos;s needs. You can
					always change this later.
				</p>
			</div>

			<fieldset
				className="wppo-presets-grid"
				role="radiogroup"
				aria-labelledby="presets-title"
			>
				<legend className="wppo-sr-only">Optimization presets</legend>
				{presets.map(preset => (
					<PresetCard
						key={preset.id}
						preset={preset}
						isSelected={selectedPreset === preset.id}
						onSelect={() => onPresetChange(preset.id)}
						translations={translations}
					/>
				))}
			</fieldset>

			<div className="wppo-wizard-navigation">
				<div className="wppo-nav-buttons">
					<button
						type="button"
						className="wppo-wizard-button wppo-wizard-button--secondary"
						onClick={onBack}
					>
						<span className="dashicons dashicons-arrow-left-alt2"></span>
						{translations?.previousStep || 'Back'}
					</button>

					<button
						type="button"
						className="wppo-wizard-button"
						onClick={onNext}
						disabled={!canProceed}
						aria-describedby={!canProceed ? 'preset-required' : undefined}
					>
						{translations?.nextStep || 'Next'}
						<span className="dashicons dashicons-arrow-right-alt2"></span>
					</button>
				</div>

				{!canProceed && (
					<p id="preset-required" className="wppo-validation-message" role="alert">
						Please select an optimization preset to continue.
					</p>
				)}
			</div>
		</div>
	);
}

export default PresetsStep;
