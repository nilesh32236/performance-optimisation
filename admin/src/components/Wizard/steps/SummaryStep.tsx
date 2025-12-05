/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import { useWizard } from '../WizardContext';

interface SummaryStepProps {
	stepConfig: any;
	wizardState: any;
}

function SummaryStep( { stepConfig }: SummaryStepProps ) {
	const { state } = useWizard();
	const { data } = state;

	const getPresetInfo = ( presetId: string ) => {
		const presets = {
			safe: {
				title: 'Safe Mode',
				description: 'Basic optimizations for maximum compatibility',
				features: [ 'Page Caching', 'Image Lazy Loading', 'Basic HTML Compression' ],
				icon: '🛡️',
			},
			recommended: {
				title: 'Recommended',
				description: 'Balanced performance and compatibility',
				features: [
					'Page Caching',
					'Image Lazy Loading',
					'CSS & JS Minification',
					'File Combination',
				],
				icon: '⭐',
			},
			advanced: {
				title: 'Advanced',
				description: 'Maximum performance optimizations',
				features: [
					'All Recommended features',
					'JavaScript Deferring',
					'Critical CSS',
					'Resource Preloading',
				],
				icon: '🚀',
			},
		};
		return presets[ presetId as keyof typeof presets ] || presets.safe;
	};

	const getEnabledFeatures = () => {
		const features = [];
		if ( data.preloadCache ) features.push( { name: 'Cache Preloading', icon: '📦' } );
		if ( data.imageConversion ) features.push( { name: 'Modern Image Formats', icon: '🖼️' } );
		if ( data.criticalCSS ) features.push( { name: 'Critical CSS Generation', icon: '🎨' } );
		if ( data.resourceHints ) features.push( { name: 'Resource Preloading', icon: '🔗' } );
		return features;
	};

	const presetInfo = getPresetInfo( data.preset );
	const enabledFeatures = getEnabledFeatures();

	const benefits = [
		{ icon: '⚡', title: 'Faster Loading', description: '20-50% improvement in page load times' },
		{ icon: '📈', title: 'Better SEO', description: 'Improved search engine rankings' },
		{ icon: '📱', title: 'Mobile Optimized', description: 'Enhanced mobile user experience' },
		{ icon: '☁️', title: 'Reduced Bandwidth', description: 'Lower hosting costs and resource usage' },
	];

	const nextSteps = [
		{ step: 1, title: 'Settings Applied', description: 'Your optimization settings will be configured automatically' },
		{ step: 2, title: 'Cache Initialization', description: 'The caching system will be activated and start optimizing your site' },
		{ step: 3, title: 'Dashboard Access', description: "You'll be redirected to the dashboard to monitor performance" },
	];

	return (
		<div className="animate-fade-in">
			<div className="text-center mb-8">
				<h2 className="text-2xl font-bold text-slate-900 mb-2">Setup Summary</h2>
				<p className="text-slate-600">
					Review your configuration before completing the setup. These settings will be
					applied to your website.
				</p>
			</div>

			<div className="space-y-6">
				{/* Preset Summary */}
				<div className="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-5 border border-blue-200">
					<h3 className="flex items-center gap-2 text-lg font-semibold text-slate-900 mb-4">
						<span className="text-xl">⚙️</span>
						Optimization Preset
					</h3>
					<div className="bg-white rounded-lg p-4 border border-blue-100">
						<div className="flex items-start gap-4">
							<div className="w-14 h-14 rounded-xl bg-blue-100 flex items-center justify-center text-2xl">
								{ presetInfo.icon }
							</div>
							<div className="flex-1">
								<h4 className="text-lg font-semibold text-slate-900">{ presetInfo.title }</h4>
								<p className="text-sm text-slate-600 mb-3">{ presetInfo.description }</p>
								<div className="flex flex-wrap gap-2">
									{ presetInfo.features.map( ( feature, index ) => (
										<span
											key={ index }
											className="inline-flex items-center gap-1 text-xs px-2 py-1 bg-green-50 text-green-700 rounded-md"
										>
											<svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
												<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M5 13l4 4L19 7" />
											</svg>
											{ feature }
										</span>
									) ) }
								</div>
							</div>
						</div>
					</div>
				</div>

				{/* Advanced Features */}
				{ enabledFeatures.length > 0 && (
					<div className="bg-slate-50 rounded-xl p-5">
						<h3 className="flex items-center gap-2 text-lg font-semibold text-slate-900 mb-4">
							<span className="text-xl">✨</span>
							Advanced Features
						</h3>
						<div className="grid grid-cols-2 gap-3">
							{ enabledFeatures.map( ( feature, index ) => (
								<div key={ index } className="flex items-center gap-3 bg-white rounded-lg p-3 border border-slate-200">
									<span className="text-xl">{ feature.icon }</span>
									<span className="text-sm font-medium text-slate-700">{ feature.name }</span>
								</div>
							) ) }
						</div>
					</div>
				) }

				{/* Expected Benefits */}
				<div className="bg-slate-50 rounded-xl p-5">
					<h3 className="flex items-center gap-2 text-lg font-semibold text-slate-900 mb-4">
						<span className="text-xl">📊</span>
						Expected Benefits
					</h3>
					<div className="grid grid-cols-2 gap-3">
						{ benefits.map( ( benefit, index ) => (
							<div key={ index } className="bg-white rounded-lg p-4 border border-slate-200">
								<div className="flex items-center gap-3 mb-1">
									<span className="text-xl">{ benefit.icon }</span>
									<h4 className="font-semibold text-slate-900">{ benefit.title }</h4>
								</div>
								<p className="text-sm text-slate-600 ml-8">{ benefit.description }</p>
							</div>
						) ) }
					</div>
				</div>

				{/* What Happens Next */}
				<div className="bg-slate-50 rounded-xl p-5">
					<h3 className="flex items-center gap-2 text-lg font-semibold text-slate-900 mb-4">
						<span className="text-xl">📋</span>
						What Happens Next
					</h3>
					<div className="space-y-3">
						{ nextSteps.map( ( item ) => (
							<div key={ item.step } className="flex items-start gap-4 bg-white rounded-lg p-4 border border-slate-200">
								<div className="flex-shrink-0 w-8 h-8 rounded-full bg-blue-500 text-white flex items-center justify-center font-semibold text-sm">
									{ item.step }
								</div>
								<div>
									<h4 className="font-semibold text-slate-900">{ item.title }</h4>
									<p className="text-sm text-slate-600">{ item.description }</p>
								</div>
							</div>
						) ) }
					</div>
				</div>
			</div>

			{/* Info Note */}
			<div className="mt-6 bg-green-50 border border-green-200 rounded-xl p-4">
				<div className="flex gap-3">
					<div className="flex-shrink-0">
						<svg className="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
						</svg>
					</div>
					<div className="text-sm text-green-800">
						<strong>Ready to go!</strong> Click "Complete Setup" to apply these settings.
						All settings can be modified later from the plugin dashboard.
					</div>
				</div>
			</div>
		</div>
	);
}

export default SummaryStep;
