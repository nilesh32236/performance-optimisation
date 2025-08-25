import React from 'react';
import { useWizard } from '../WizardContext';

interface PresetStepProps {
	stepConfig: any;
	wizardState: any;
}

interface Preset {
	id: string;
	title: string;
	description: string;
	features: string[];
	isRecommended?: boolean;
	hasWarning?: boolean;
	performance: 'low' | 'medium' | 'high';
	compatibility: 'high' | 'medium' | 'low';
}

function PresetStep({ stepConfig }: PresetStepProps) {
	const { state, updateData } = useWizard();
	const selectedPreset = state.data.preset || '';

	const presets: Preset[] = [
		{
			id: 'safe',
			title: 'Safe Mode',
			description: 'Basic optimizations that work with all websites and hosting environments.',
			features: [
				'Page Caching',
				'Image Lazy Loading',
				'Basic HTML Compression'
			],
			performance: 'low',
			compatibility: 'high',
		},
		{
			id: 'recommended',
			title: 'Recommended',
			description: 'The best balance of performance and compatibility for most websites.',
			features: [
				'All Safe Mode features',
				'CSS & JavaScript Minification',
				'File Combination',
				'Browser Caching Headers'
			],
			isRecommended: true,
			performance: 'medium',
			compatibility: 'high',
		},
		{
			id: 'advanced',
			title: 'Advanced',
			description: 'Maximum performance optimizations. May require testing on some websites.',
			features: [
				'All Recommended features',
				'JavaScript Deferring',
				'Critical CSS Inlining',
				'Resource Preloading'
			],
			hasWarning: true,
			performance: 'high',
			compatibility: 'medium',
		}
	];

	const handlePresetSelect = (presetId: string) => {
		updateData('preset', presetId);
	};

	const getPerformanceColor = (level: string) => {
		switch (level) {
			case 'high': return '#00a32a';
			case 'medium': return '#dba617';
			case 'low': return '#d63638';
			default: return '#50575e';
		}
	};

	const getCompatibilityColor = (level: string) => {
		switch (level) {
			case 'high': return '#00a32a';
			case 'medium': return '#dba617';
			case 'low': return '#d63638';
			default: return '#50575e';
		}
	};

	return (
		<div className="wppo-wizard-step wppo-preset-step">
			<div className="wppo-step-header">
				<h2>Choose Your Optimization Level</h2>
				<p className="wppo-step-description">
					Select the optimization preset that best fits your website's needs. 
					You can always adjust individual settings later.
				</p>
			</div>
			
			<fieldset className="wppo-presets-grid" role="radiogroup" aria-labelledby="preset-title">
				<legend className="wppo-sr-only">Optimization presets</legend>
				
				{presets.map((preset) => (
					<div 
						key={preset.id}
						className={`wppo-preset-card ${selectedPreset === preset.id ? 'selected' : ''} ${preset.isRecommended ? 'recommended' : ''}`}
						onClick={() => handlePresetSelect(preset.id)}
						role="radio"
						aria-checked={selectedPreset === preset.id}
						tabIndex={0}
						onKeyDown={(e) => {
							if (e.key === 'Enter' || e.key === ' ') {
								e.preventDefault();
								handlePresetSelect(preset.id);
							}
						}}
					>
						<div className="wppo-preset-header">
							<div className="wppo-preset-title-row">
								<h3 className="wppo-preset-title">{preset.title}</h3>
								{preset.isRecommended && (
									<span className="wppo-preset-badge wppo-preset-badge--recommended">
										Recommended
									</span>
								)}
								{preset.hasWarning && (
									<span className="wppo-preset-badge wppo-preset-badge--warning">
										Advanced
									</span>
								)}
							</div>
							<p className="wppo-preset-description">{preset.description}</p>
						</div>
						
						<div className="wppo-preset-metrics">
							<div className="wppo-preset-metric">
								<span className="wppo-metric-label">Performance</span>
								<div className="wppo-metric-bar">
									<div 
										className="wppo-metric-fill"
										style={{ 
											width: preset.performance === 'high' ? '100%' : preset.performance === 'medium' ? '66%' : '33%',
											backgroundColor: getPerformanceColor(preset.performance)
										}}
									/>
								</div>
								<span className="wppo-metric-value">{preset.performance}</span>
							</div>
							
							<div className="wppo-preset-metric">
								<span className="wppo-metric-label">Compatibility</span>
								<div className="wppo-metric-bar">
									<div 
										className="wppo-metric-fill"
										style={{ 
											width: preset.compatibility === 'high' ? '100%' : preset.compatibility === 'medium' ? '66%' : '33%',
											backgroundColor: getCompatibilityColor(preset.compatibility)
										}}
									/>
								</div>
								<span className="wppo-metric-value">{preset.compatibility}</span>
							</div>
						</div>
						
						<div className="wppo-preset-features">
							<h4>Included Features:</h4>
							<ul>
								{preset.features.map((feature, index) => (
									<li key={index}>
										<span className="dashicons dashicons-yes-alt" aria-hidden="true" />
										{feature}
									</li>
								))}
							</ul>
						</div>
						
						<div className="wppo-preset-radio">
							<input
								type="radio"
								id={`preset-${preset.id}`}
								name="preset"
								value={preset.id}
								checked={selectedPreset === preset.id}
								onChange={() => handlePresetSelect(preset.id)}
								aria-describedby={`preset-${preset.id}-description`}
							/>
							<label htmlFor={`preset-${preset.id}`} className="wppo-sr-only">
								Select {preset.title}
							</label>
						</div>
					</div>
				))}
			</fieldset>
		</div>
	);
}

export default PresetStep;