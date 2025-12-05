/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
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
	icon: string;
	performance: 'low' | 'medium' | 'high';
	compatibility: 'high' | 'medium' | 'low';
}

function PresetStep( { stepConfig }: PresetStepProps ) {
	const { state, updateData } = useWizard();
	const selectedPreset = state.data.preset || '';

	const presets: Preset[] = [
		{
			id: 'safe',
			title: 'Safe Mode',
			description: 'Basic optimizations that work with all websites and hosting environments.',
			features: [ 'Page Caching', 'Image Lazy Loading', 'Basic HTML Compression' ],
			icon: '🛡️',
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
				'Browser Caching Headers',
			],
			isRecommended: true,
			icon: '⭐',
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
				'Resource Preloading',
			],
			hasWarning: true,
			icon: '🚀',
			performance: 'high',
			compatibility: 'medium',
		},
	];

	const handlePresetSelect = ( presetId: string ) => {
		updateData( 'preset', presetId );
	};

	const getPerformanceWidth = ( level: string ) => {
		switch ( level ) {
			case 'high': return '100%';
			case 'medium': return '66%';
			case 'low': return '33%';
			default: return '0%';
		}
	};

	return (
		<div className="animate-fade-in">
			<div className="text-center mb-8">
				<h2 className="text-2xl font-bold text-slate-900 mb-2">
					Choose Your Optimization Level
				</h2>
				<p className="text-slate-600">
					Select the optimization preset that best fits your website's needs. You can
					always adjust individual settings later.
				</p>
			</div>

			<fieldset className="space-y-4" role="radiogroup" aria-label="Optimization presets">
				<legend className="sr-only">Optimization presets</legend>

				{ presets.map( ( preset ) => (
					<div
						key={ preset.id }
						onClick={ () => handlePresetSelect( preset.id ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								handlePresetSelect( preset.id );
							}
						} }
						role="radio"
						aria-checked={ selectedPreset === preset.id }
						tabIndex={ 0 }
						className={ `
							relative p-5 rounded-xl border-2 cursor-pointer transition-all duration-200
							${ selectedPreset === preset.id
								? 'border-blue-500 bg-blue-50 shadow-lg shadow-blue-100'
								: 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-md'
							}
							${ preset.isRecommended ? 'ring-2 ring-blue-200 ring-offset-2' : '' }
						` }
					>
						{/* Recommended Badge */}
						{ preset.isRecommended && (
							<div className="absolute -top-3 left-4 px-3 py-1 bg-blue-500 text-white text-xs font-semibold rounded-full">
								Recommended
							</div>
						) }
						{ preset.hasWarning && (
							<div className="absolute -top-3 left-4 px-3 py-1 bg-amber-500 text-white text-xs font-semibold rounded-full">
								Advanced
							</div>
						) }

						<div className="flex gap-4">
							{/* Icon */}
							<div className="flex-shrink-0">
								<div className={ `
									w-14 h-14 rounded-xl flex items-center justify-center text-2xl
									${ selectedPreset === preset.id
										? 'bg-blue-100'
										: 'bg-slate-100'
									}
								` }>
									{ preset.icon }
								</div>
							</div>

							{/* Content */}
							<div className="flex-1 min-w-0">
								<div className="flex items-center gap-3 mb-1">
									<h3 className="text-lg font-semibold text-slate-900">
										{ preset.title }
									</h3>
								</div>
								<p className="text-sm text-slate-600 mb-3">
									{ preset.description }
								</p>

								{/* Metrics */}
								<div className="flex gap-6 mb-3">
									<div className="flex-1">
										<div className="flex items-center justify-between text-xs mb-1">
											<span className="text-slate-500">Performance</span>
											<span className="font-medium text-slate-700 capitalize">{ preset.performance }</span>
										</div>
										<div className="h-1.5 bg-slate-200 rounded-full overflow-hidden">
											<div
												className="h-full bg-green-500 transition-all duration-300"
												style={ { width: getPerformanceWidth( preset.performance ) } }
											/>
										</div>
									</div>
									<div className="flex-1">
										<div className="flex items-center justify-between text-xs mb-1">
											<span className="text-slate-500">Compatibility</span>
											<span className="font-medium text-slate-700 capitalize">{ preset.compatibility }</span>
										</div>
										<div className="h-1.5 bg-slate-200 rounded-full overflow-hidden">
											<div
												className="h-full bg-blue-500 transition-all duration-300"
												style={ { width: getPerformanceWidth( preset.compatibility ) } }
											/>
										</div>
									</div>
								</div>

								{/* Features */}
								<div className="flex flex-wrap gap-2">
									{ preset.features.map( ( feature, index ) => (
										<span
											key={ index }
											className="inline-flex items-center gap-1 text-xs px-2 py-1 bg-slate-100 text-slate-600 rounded-md"
										>
											<svg className="w-3 h-3 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
												<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M5 13l4 4L19 7" />
											</svg>
											{ feature }
										</span>
									) ) }
								</div>
							</div>

							{/* Radio Indicator */}
							<div className="flex-shrink-0 self-center">
								<div className={ `
									w-6 h-6 rounded-full border-2 flex items-center justify-center transition-all duration-200
									${ selectedPreset === preset.id
										? 'border-blue-500 bg-blue-500'
										: 'border-slate-300'
									}
								` }>
									{ selectedPreset === preset.id && (
										<svg className="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
											<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M5 13l4 4L19 7" />
										</svg>
									) }
								</div>
							</div>
						</div>

						{/* Hidden Radio Input */}
						<input
							type="radio"
							name="preset"
							value={ preset.id }
							checked={ selectedPreset === preset.id }
							onChange={ () => handlePresetSelect( preset.id ) }
							className="sr-only"
						/>
					</div>
				) ) }
			</fieldset>
		</div>
	);
}

export default PresetStep;
