/**
 * External dependencies
 */
import React, { useState, useEffect } from 'react';
/**
 * Internal dependencies
 */
import { Button } from '../Button';

interface TourStep {
	id: string;
	target: string;
	title: string;
	content: string;
	position?: 'top' | 'bottom' | 'left' | 'right';
	action?: {
		text: string;
		callback: () => void;
	};
}

interface OnboardingTourProps {
	steps: TourStep[];
	isActive: boolean;
	onComplete: () => void;
	onSkip: () => void;
	className?: string;
}

const OnboardingTour: React.FC<OnboardingTourProps> = ( {
	steps,
	isActive,
	onComplete,
	onSkip,
	className = '',
} ) => {
	const [ currentStep, setCurrentStep ] = useState( 0 );
	const [ targetElement, setTargetElement ] = useState<HTMLElement | null>( null );
	const [ tourPosition, setTourPosition ] = useState( { top: 0, left: 0 } );

	useEffect( () => {
		if ( isActive && steps.length > 0 ) {
			updateTourPosition();
		}
	}, [ isActive, currentStep, steps ] );

	useEffect( () => {
		const handleResize = () => {
			if ( isActive ) {
				updateTourPosition();
			}
		};

		window.addEventListener( 'resize', handleResize );
		return () => window.removeEventListener( 'resize', handleResize );
	}, [ isActive ] );

	const updateTourPosition = () => {
		if ( currentStep >= steps.length ) {
			return;
		}

		const step = steps[ currentStep ];
		const element = document.querySelector( step.target ) as HTMLElement;

		if ( element ) {
			setTargetElement( element );

			const rect = element.getBoundingClientRect();
			const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
			const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;

			// Add highlight to target element
			element.classList.add( 'wppo-tour-highlight' );

			// Calculate position based on step position preference
			let top = rect.top + scrollTop;
			let left = rect.left + scrollLeft;

			switch ( step.position ) {
				case 'bottom':
					top += rect.height + 10;
					left += rect.width / 2;
					break;
				case 'top':
					top -= 10;
					left += rect.width / 2;
					break;
				case 'right':
					top += rect.height / 2;
					left += rect.width + 10;
					break;
				case 'left':
					top += rect.height / 2;
					left -= 10;
					break;
				default:
					top += rect.height + 10;
					left += rect.width / 2;
			}

			setTourPosition( { top, left } );

			// Scroll element into view
			element.scrollIntoView( {
				behavior: 'smooth',
				block: 'center',
				inline: 'center',
			} );
		}
	};

	const nextStep = () => {
		// Remove highlight from current element
		if ( targetElement ) {
			targetElement.classList.remove( 'wppo-tour-highlight' );
		}

		if ( currentStep < steps.length - 1 ) {
			setCurrentStep( currentStep + 1 );
		} else {
			completeTour();
		}
	};

	const previousStep = () => {
		if ( targetElement ) {
			targetElement.classList.remove( 'wppo-tour-highlight' );
		}

		if ( currentStep > 0 ) {
			setCurrentStep( currentStep - 1 );
		}
	};

	const completeTour = () => {
		// Remove highlight from all elements
		document.querySelectorAll( '.wppo-tour-highlight' ).forEach( ( el ) => {
			el.classList.remove( 'wppo-tour-highlight' );
		} );

		onComplete();
	};

	const skipTour = () => {
		// Remove highlight from all elements
		document.querySelectorAll( '.wppo-tour-highlight' ).forEach( ( el ) => {
			el.classList.remove( 'wppo-tour-highlight' );
		} );

		onSkip();
	};

	if ( ! isActive || steps.length === 0 ) {
		return null;
	}

	const step = steps[ currentStep ];
	const isLastStep = currentStep === steps.length - 1;

	return (
		<>
			{ /* Overlay */ }
			<div className="wppo-tour-overlay" onClick={ skipTour } />

			{ /* Tour Popup */ }
			<div
				className={ `wppo-tour-popup wppo-tour-popup--${ step.position || 'bottom' } ${ className }` }
				style={ {
					position: 'absolute',
					top: tourPosition.top,
					left: tourPosition.left,
					zIndex: 10000,
				} }
			>
				<div className="wppo-tour-popup__header">
					<h4 className="wppo-tour-popup__title">{ step.title }</h4>
					<button
						className="wppo-tour-popup__close"
						onClick={ skipTour }
						aria-label="Close tour"
					>
						<span className="dashicons dashicons-no-alt"></span>
					</button>
				</div>

				<div className="wppo-tour-popup__content">
					<div
						className="wppo-tour-popup__text"
						dangerouslySetInnerHTML={ { __html: step.content } }
					/>

					{ step.action && (
						<div className="wppo-tour-popup__action">
							<Button variant="secondary" size="small" onClick={ step.action.callback }>
								{ step.action.text }
							</Button>
						</div>
					) }
				</div>

				<div className="wppo-tour-popup__footer">
					<div className="wppo-tour-popup__progress">
						<span className="wppo-tour-popup__step-counter">
							{ currentStep + 1 } of { steps.length }
						</span>
						<div className="wppo-tour-popup__progress-bar">
							<div
								className="wppo-tour-popup__progress-fill"
								style={ { width: `${ ( ( currentStep + 1 ) / steps.length ) * 100 }%` } }
							/>
						</div>
					</div>

					<div className="wppo-tour-popup__actions">
						<Button variant="tertiary" size="small" onClick={ skipTour }>
							Skip Tour
						</Button>

						{ currentStep > 0 && (
							<Button variant="secondary" size="small" onClick={ previousStep }>
								Previous
							</Button>
						) }

						<Button variant="primary" size="small" onClick={ nextStep }>
							{ isLastStep ? 'Finish' : 'Next' }
						</Button>
					</div>
				</div>

				<div className="wppo-tour-popup__arrow"></div>
			</div>
		</>
	);
};

export default OnboardingTour;
