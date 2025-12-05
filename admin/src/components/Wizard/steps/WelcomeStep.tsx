/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import { useWizard } from '../WizardContext';

interface WelcomeStepProps {
	stepConfig: any;
	wizardState: any;
}



function WelcomeStep( { stepConfig }: WelcomeStepProps ) {
	const { updateData } = useWizard();

	React.useEffect( () => {
		// Mark that user has seen the welcome step
		updateData( 'welcomeViewed', true );
	}, [ updateData ] );

	const features = [
		{ id: 'loading', icon: 'dashicons-performance', text: 'Faster page loading times' },
		{ id: 'seo', icon: 'dashicons-chart-bar', text: 'Improved search engine rankings' },
		{ id: 'ux', icon: 'dashicons-smiley', text: 'Better user experience' },
		{ id: 'server', icon: 'dashicons-cloud', text: 'Reduced server load' },
	];

	return (
		<div className="text-center max-w-2xl mx-auto animate-fade-in">
			{/* Hero Icon */}
			<div className="mb-8">
				<div className="inline-flex items-center justify-center w-24 h-24 rounded-full bg-gradient-to-br from-primary-500 to-secondary-500 text-white shadow-xl shadow-primary-200">
					<span className="dashicons dashicons-performance" style={ { fontSize: '48px', width: '48px', height: '48px' } } aria-hidden="true"></span>
				</div>
			</div>

			{/* Title */}
			<h2 className="text-2xl font-bold text-slate-900 mb-4">
				Welcome to Performance Optimisation
			</h2>

			{/* Description */}
			<p className="text-lg text-slate-600 mb-8 leading-relaxed">
				This setup wizard will help you configure optimal performance settings for your
				website. The process takes just a few minutes and will significantly improve
				your site's speed.
			</p>

			{/* Features Grid */}
			<div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
				{ features.map( ( feature ) => (
					<div
						key={ feature.id }
						className="flex items-center gap-3 p-4 bg-slate-50 rounded-xl text-left transition-all duration-200 hover:bg-primary-50 hover:shadow-sm"
					>
						<div className="flex-shrink-0 w-10 h-10 bg-white rounded-lg shadow-sm flex items-center justify-center text-primary-600">
							<span className={ `dashicons ${ feature.icon }` } style={ { fontSize: '24px', width: '24px', height: '24px' } } aria-hidden="true"></span>
						</div>
						<span className="text-slate-700 font-medium">{ feature.text }</span>
					</div>
				) ) }
			</div>

			{/* Info Box */}
			<div className="bg-indigo-50 border border-indigo-200 rounded-xl p-5 text-left">
				<div className="flex gap-4">
					<div className="flex-shrink-0">
						<div className="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
							<svg className="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
							</svg>
						</div>
					</div>
					<div>
						<p className="text-indigo-900 font-medium mb-1">Don't worry!</p>
						<p className="text-indigo-700 text-sm">
							All settings can be changed later, and we'll only enable features that are
							safe for your website.
						</p>
					</div>
				</div>
			</div>
		</div>
	);
}

export default WelcomeStep;
