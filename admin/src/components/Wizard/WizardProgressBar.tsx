/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import { useWizard } from './WizardContext';

function WizardProgressBar() {
	const { state } = useWizard();
	const { currentStep, totalSteps, completedSteps } = state;

	const getStepStatus = ( stepNumber: number ) => {
		if ( completedSteps.has( stepNumber ) ) {
			return 'completed';
		}
		if ( stepNumber === currentStep ) {
			return 'current';
		}
		return 'pending';
	};

	const stepLabels = [ 'Welcome', 'Analysis', 'Preset', 'Features', 'Summary' ];

	return (
		<div className="w-full" role="navigation" aria-label="Setup progress">
			{/* Step Indicators */}
			<div className="flex items-center justify-between relative">
				{/* Progress Line Background */}
				<div className="absolute top-5 left-0 right-0 h-0.5 bg-white/30 -z-10" />
				
				{/* Progress Line Fill */}
				<div
					className="absolute top-5 left-0 h-0.5 bg-teal-400 -z-10 transition-all duration-500 ease-out"
					style={ { width: `${ ( ( currentStep - 1 ) / ( totalSteps - 1 ) ) * 100 }%` } }
				/>

				{Array.from( { length: totalSteps }, ( _, index ) => {
					const stepNumber = index + 1;
					const status = getStepStatus( stepNumber );

					return (
						<div
							key={ stepNumber }
							className="flex flex-col items-center"
							role="listitem"
							aria-current={ status === 'current' ? 'step' : undefined }
						>
							{/* Step Circle */}
							<div
								className={ `
									w-10 h-10 rounded-full flex items-center justify-center
									border-2 transition-all duration-300 ease-out
									${ status === 'completed'
										? 'bg-teal-500 border-teal-400 text-white'
										: status === 'current'
											? 'bg-white border-white text-primary-600 shadow-lg shadow-primary-900/20'
											: 'bg-white/20 border-white/40 text-white/70'
									}
								` }
							>
								{ status === 'completed' ? (
									<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M5 13l4 4L19 7" />
									</svg>
								) : (
									<span className="text-sm font-semibold">{ stepNumber }</span>
								) }
							</div>

							{/* Step Label */}
							<span
								className={ `
									mt-2 text-xs font-medium transition-colors duration-300
									${ status === 'current'
										? 'text-white'
										: status === 'completed'
											? 'text-teal-300'
											: 'text-white/60'
									}
								` }
							>
								{ stepLabels[ index ] || `Step ${ stepNumber }` }
							</span>
						</div>
					);
				} ) }
			</div>

			{/* Screen Reader Text */}
			<p className="sr-only" aria-live="polite">
				Step { currentStep } of { totalSteps }
			</p>
		</div>
	);
}

export default WizardProgressBar;
