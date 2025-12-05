/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import { useWizard } from './WizardContext';

interface WizardNavigationProps {
	onComplete?: ( data: Record<string, any> ) => Promise<void>;
}

function WizardNavigation( { onComplete }: WizardNavigationProps ) {
	const { state, nextStep, previousStep, validateCurrentStep, setError, setLoading } =
		useWizard();

	const { currentStep, totalSteps, isLoading, data } = state;

	const isFirstStep = currentStep === 1;
	const isLastStep = currentStep === totalSteps;
	const canProceed = validateCurrentStep();

	const handleNext = async () => {
		if ( ! canProceed ) {
			setError( 'Please complete all required fields before continuing.' );
			return;
		}

		setError( null );

		if ( isLastStep && onComplete ) {
			try {
				setLoading( true );
				await onComplete( data );
			} catch ( error ) {
				setError(
					error instanceof Error
						? error.message
						: 'An error occurred while completing setup.'
				);
			} finally {
				setLoading( false );
			}
		} else {
			nextStep();
		}
	};

	const handlePrevious = () => {
		setError( null );
		previousStep();
	};

	return (
		<div className="flex flex-col items-center gap-4">
			{/* Navigation Buttons */}
			<div className="flex items-center gap-4">
				{/* Back Button */}
				{ ! isFirstStep && (
					<button
						type="button"
						onClick={ handlePrevious }
						disabled={ isLoading }
						className="
							inline-flex items-center gap-2 px-6 py-3
							bg-white border border-slate-300 text-slate-700
							font-medium rounded-xl
							hover:bg-slate-50 hover:border-slate-400
							focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2
							disabled:opacity-50 disabled:cursor-not-allowed
							transition-all duration-200
						"
					>
						<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M15 19l-7-7 7-7" />
						</svg>
						Back
					</button>
				) }

				{/* Next / Complete Button */}
				<button
					type="button"
					onClick={ handleNext }
					disabled={ ! canProceed || isLoading }
					className={ `
						inline-flex items-center gap-2 px-8 py-3
						font-semibold rounded-xl
						focus:outline-none focus:ring-2 focus:ring-offset-2
						disabled:opacity-50 disabled:cursor-not-allowed
						transition-all duration-200 transform
						hover:scale-[1.02] active:scale-[0.98]
						${ isLastStep
							? 'bg-green-600 text-white hover:bg-green-700 focus:ring-green-500 shadow-lg shadow-green-200'
							: 'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500 shadow-lg shadow-blue-200'
						}
					` }
					aria-describedby={ ! canProceed ? 'validation-message' : undefined }
				>
					{ isLoading ? (
						<>
							<svg className="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
								<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
								<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
							</svg>
							{ isLastStep ? 'Completing...' : 'Please wait...' }
						</>
					) : (
						<>
							{ isLastStep ? 'Complete Setup' : 'Continue' }
							{ isLastStep ? (
								<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M5 13l4 4L19 7" />
								</svg>
							) : (
								<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M9 5l7 7-7 7" />
								</svg>
							) }
						</>
					) }
				</button>
			</div>

			{/* Validation Message */}
			{ ! canProceed && (
				<p
					id="validation-message"
					className="text-sm text-amber-600 bg-amber-50 px-4 py-2 rounded-lg border border-amber-200"
					role="alert"
				>
					<svg className="inline-block w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
					</svg>
					Please complete all required fields before continuing.
				</p>
			) }
		</div>
	);
}

export default WizardNavigation;
