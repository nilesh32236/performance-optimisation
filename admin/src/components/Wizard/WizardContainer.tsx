/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import { WizardProvider, WizardStep } from './WizardContext';
import WizardNavigation from './WizardNavigation';
import WizardProgressBar from './WizardProgressBar';
import WizardStepRenderer from './WizardStepRenderer';
import ErrorBoundary from '../common/ErrorBoundary';

interface WizardContainerProps {
	steps: WizardStep[];
	title?: string;
	subtitle?: string;
	onComplete?: ( data: Record<string, any> ) => Promise<void>;
	className?: string;
}

function WizardContainer( {
	steps,
	title = 'Setup Wizard',
	subtitle,
	onComplete,
	className = '',
}: WizardContainerProps ) {
	return (
		<ErrorBoundary>
			<WizardProvider steps={ steps }>
				<div
					className={ `min-h-screen bg-gradient-to-br from-slate-50 via-white to-blue-50 ${ className }` }
					role="main"
					aria-label="Setup Wizard"
				>
					{/* Skip Link for Accessibility */}
					<a
						href="#wppo-wizard-content"
						className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-blue-600 focus:text-white focus:px-4 focus:py-2 focus:rounded-lg"
					>
						Skip to main content
					</a>

					{/* Wizard Header */}
					<header className="bg-white border-b border-slate-200 shadow-sm">
						<div className="max-w-4xl mx-auto px-6 py-8 text-center">
							<h1
								id="wppo-wizard-title"
								className="text-3xl font-bold text-slate-900 mb-2"
							>
								{ title }
							</h1>
							{ subtitle && (
								<p className="text-lg text-slate-600 mb-6">
									{ subtitle }
								</p>
							) }
							<WizardProgressBar />
						</div>
					</header>

					{/* Wizard Content */}
					<main
						id="wppo-wizard-content"
						className="max-w-4xl mx-auto px-6 py-8"
						role="region"
						aria-labelledby="wppo-wizard-title"
						aria-live="polite"
					>
						<div className="bg-white rounded-2xl shadow-lg border border-slate-200 p-8 animate-fade-in">
							<WizardStepRenderer steps={ steps } />
						</div>
					</main>

					{/* Wizard Navigation */}
					<footer className="bg-white border-t border-slate-200 shadow-sm">
						<div className="max-w-4xl mx-auto px-6 py-6">
							<WizardNavigation onComplete={ onComplete } />
						</div>
					</footer>

					{/* Footer Credit */}
					<div className="text-center py-4 text-sm text-slate-500">
						Thank you for creating with{' '}
						<a
							href="https://wordpress.org"
							className="text-blue-600 hover:text-blue-700 underline"
							target="_blank"
							rel="noopener noreferrer"
						>
							WordPress
						</a>
						.
					</div>
				</div>
			</WizardProvider>
		</ErrorBoundary>
	);
}

export default WizardContainer;
