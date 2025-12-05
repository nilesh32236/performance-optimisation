/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import { useWizard } from '../WizardContext';

interface FeaturesStepProps {
	stepConfig: any;
	wizardState: any;
}

interface Feature {
	id: string;
	title: string;
	description: string;
	longDescription: string;
	recommended: boolean;
	impact: 'low' | 'medium' | 'high';
	complexity: 'low' | 'medium' | 'high';
	icon: string;
}

function FeaturesStep( { stepConfig }: FeaturesStepProps ) {
	const { state, updateData } = useWizard();

	const features: Feature[] = [
		{
			id: 'preloadCache',
			title: 'Cache Preloading',
			description: 'Automatically prepare cached versions of your pages for faster delivery.',
			longDescription:
				'This feature runs in the background to ensure visitors always get the fastest version of your site. It intelligently preloads your most important pages.',
			recommended: true,
			impact: 'high',
			complexity: 'low',
			icon: '📦',
		},
		{
			id: 'imageConversion',
			title: 'Modern Image Formats',
			description: 'Convert images to WebP and AVIF formats for better compression.',
			longDescription:
				'Automatically converts uploaded images to modern formats that are 25-50% smaller while maintaining quality. Original images are kept as backup.',
			recommended: true,
			impact: 'high',
			complexity: 'medium',
			icon: '🖼️',
		},
		{
			id: 'criticalCSS',
			title: 'Critical CSS Generation',
			description: 'Inline critical CSS for above-the-fold content.',
			longDescription:
				'Automatically identifies and inlines the CSS needed for above-the-fold content, eliminating render-blocking CSS for faster page loads.',
			recommended: false,
			impact: 'medium',
			complexity: 'high',
			icon: '🎨',
		},
		{
			id: 'resourceHints',
			title: 'Resource Preloading',
			description: 'Preload important resources for faster navigation.',
			longDescription:
				'Intelligently preloads fonts, images, and other resources that are likely to be needed, reducing perceived load times.',
			recommended: true,
			impact: 'medium',
			complexity: 'low',
			icon: '🔗',
		},
	];

	const handleFeatureToggle = ( featureId: string, enabled: boolean ) => {
		updateData( featureId, enabled );
	};

	const getImpactStyles = ( impact: string ) => {
		switch ( impact ) {
			case 'high': return { bg: 'bg-green-100', text: 'text-green-700', dot: 'bg-green-500' };
			case 'medium': return { bg: 'bg-amber-100', text: 'text-amber-700', dot: 'bg-amber-500' };
			case 'low': return { bg: 'bg-blue-100', text: 'text-blue-700', dot: 'bg-blue-500' };
			default: return { bg: 'bg-slate-100', text: 'text-slate-700', dot: 'bg-slate-500' };
		}
	};

	const getComplexityStyles = ( complexity: string ) => {
		switch ( complexity ) {
			case 'low': return { bg: 'bg-green-100', text: 'text-green-700', dot: 'bg-green-500' };
			case 'medium': return { bg: 'bg-amber-100', text: 'text-amber-700', dot: 'bg-amber-500' };
			case 'high': return { bg: 'bg-red-100', text: 'text-red-700', dot: 'bg-red-500' };
			default: return { bg: 'bg-slate-100', text: 'text-slate-700', dot: 'bg-slate-500' };
		}
	};

	return (
		<div className="animate-fade-in">
			<div className="text-center mb-8">
				<h2 className="text-2xl font-bold text-slate-900 mb-2">Advanced Features</h2>
				<p className="text-slate-600">
					Enable these powerful features to supercharge your website's performance. All
					features are optional and can be configured later.
				</p>
			</div>

			<div className="space-y-4">
				{ features.map( ( feature ) => {
					const isEnabled = state.data[ feature.id ] || false;
					const impactStyles = getImpactStyles( feature.impact );
					const complexityStyles = getComplexityStyles( feature.complexity );

					return (
						<div
							key={ feature.id }
							className={ `
								p-5 rounded-xl border-2 transition-all duration-200
								${ isEnabled
									? 'border-blue-500 bg-blue-50/50'
									: 'border-slate-200 bg-white hover:border-slate-300'
								}
							` }
						>
							<div className="flex items-start gap-4">
								{/* Icon */}
								<div className={ `
									flex-shrink-0 w-12 h-12 rounded-xl flex items-center justify-center text-2xl
									${ isEnabled ? 'bg-blue-100' : 'bg-slate-100' }
								` }>
									{ feature.icon }
								</div>

								{/* Content */}
								<div className="flex-1 min-w-0">
									<div className="flex items-center gap-2 mb-1">
										<h3 className="text-lg font-semibold text-slate-900">
											{ feature.title }
										</h3>
										{ feature.recommended && (
											<span className="px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-700 rounded-full">
												Recommended
											</span>
										) }
									</div>
									<p className="text-sm text-slate-600 mb-3">
										{ feature.description }
									</p>

									{/* Expanded Description */}
									<p className="text-sm text-slate-500 mb-3 leading-relaxed" id={ `${ feature.id }-description` }>
										{ feature.longDescription }
									</p>

									{/* Metrics */}
									<div className="flex flex-wrap gap-3">
										<div className={ `inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${ impactStyles.bg } ${ impactStyles.text }` }>
											<span className={ `w-2 h-2 rounded-full ${ impactStyles.dot }` } />
											Impact: { feature.impact }
										</div>
										<div className={ `inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${ complexityStyles.bg } ${ complexityStyles.text }` }>
											<span className={ `w-2 h-2 rounded-full ${ complexityStyles.dot }` } />
											Complexity: { feature.complexity }
										</div>
									</div>
								</div>

								{/* Toggle Switch */}
								<div className="flex-shrink-0">
									<label className="relative inline-flex cursor-pointer">
										<input
											type="checkbox"
											checked={ isEnabled }
											onChange={ ( e ) => handleFeatureToggle( feature.id, e.target.checked ) }
											className="sr-only peer"
											aria-describedby={ `${ feature.id }-description` }
										/>
										<div className="
											w-14 h-7 bg-slate-200 rounded-full
											peer-checked:bg-blue-500
											peer-focus:ring-4 peer-focus:ring-blue-200
											transition-colors duration-200
											after:content-[''] after:absolute after:top-0.5 after:left-0.5
											after:bg-white after:rounded-full after:h-6 after:w-6
											after:transition-transform after:duration-200 after:shadow-sm
											peer-checked:after:translate-x-7
										" />
									</label>
								</div>
							</div>
						</div>
					);
				} ) }
			</div>

			{/* Info Note */}
			<div className="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-4">
				<div className="flex gap-3">
					<div className="flex-shrink-0">
						<svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
						</svg>
					</div>
					<div className="text-sm text-blue-800">
						<strong>Good to know:</strong> These features work automatically in the
						background and can be disabled at any time if you experience any issues.
					</div>
				</div>
			</div>
		</div>
	);
}

export default FeaturesStep;
