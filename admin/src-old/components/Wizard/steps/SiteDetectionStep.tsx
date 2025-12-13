/**
 * External dependencies
 */
import React, { useState, useEffect } from 'react';
/**
 * Internal dependencies
 */
import { useWizard } from '../WizardContext';

interface SiteDetectionStepProps {
	stepConfig: any;
	wizardState: any;
}

interface SiteAnalysis {
	hosting: {
		server_software: string;
		php_version: string;
		memory_limit: number;
		hosting_provider: string;
		ssl_enabled: boolean;
	};
	wordpress: {
		version: string;
		multisite: boolean;
	};
	plugins: {
		total_count: number;
		performance_plugins: string[];
		conflicts: any[];
	};
	content: {
		post_count: number;
		media_count: number;
		large_images: number;
	};
	compatibility: {
		[key: string]: {
			compatible: boolean;
			score: number;
		};
	};
	recommendations: any[];
	conflicts: any[];
}

interface Recommendations {
	preset: {
		preset: string;
		confidence: number;
		reasons: string[];
	};
	personalized: any[];
}

function SiteDetectionStep( { stepConfig }: SiteDetectionStepProps ) {
	const { state, updateData } = useWizard();
	const [ analysis, setAnalysis ] = useState<SiteAnalysis | null>( null );
	const [ recommendations, setRecommendations ] = useState<Recommendations | null>( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ loadingStep, setLoadingStep ] = useState( 0 );

	useEffect( () => {
		performSiteAnalysis();
	}, [] );

	// Animate loading steps
	useEffect( () => {
		if ( isLoading ) {
			const interval = setInterval( () => {
				setLoadingStep( ( prev ) => ( prev < 2 ? prev + 1 : prev ) );
			}, 800 );
			return () => clearInterval( interval );
		}
	}, [ isLoading ] );

	const performSiteAnalysis = async () => {
		setIsLoading( true );
		setError( null );
		setLoadingStep( 0 );

		try {
			const analysisResponse = await fetch(
				`${ window.wppoWizardData.apiUrl }/wizard/analysis`,
				{
					headers: {
						'X-WP-Nonce': window.wppoWizardData.nonce,
					},
				}
			);

			if ( ! analysisResponse.ok ) {
				throw new Error( 'Failed to analyze site' );
			}

			const analysisResult = await analysisResponse.json();
			if ( ! analysisResult.success ) {
				throw new Error( analysisResult.message || 'Analysis failed' );
			}

			setAnalysis( analysisResult.data );

			const recommendationsResponse = await fetch(
				`${ window.wppoWizardData.apiUrl }/recommendations`,
				{
					headers: {
						'X-WP-Nonce': window.wppoWizardData.nonce,
					},
				}
			);

			if ( ! recommendationsResponse.ok ) {
				throw new Error( 'Failed to get recommendations' );
			}

			const recommendationsResult = await recommendationsResponse.json();
			if ( ! recommendationsResult.success ) {
				throw new Error( recommendationsResult.message || 'Recommendations failed' );
			}

			setRecommendations( recommendationsResult.data );

			const recommendedPreset = recommendationsResult.data.preset.preset;
			updateData( 'preset', recommendedPreset );
			updateData( 'siteAnalysis', analysisResult.data );
			updateData( 'recommendations', recommendationsResult.data );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : 'An error occurred during site analysis' );
		} finally {
			setIsLoading( false );
		}
	};

	const getScoreColor = ( score: number ) => {
		if ( score >= 80 ) return 'text-green-600';
		if ( score >= 60 ) return 'text-amber-600';
		return 'text-red-600';
	};

	const getScoreBg = ( score: number ) => {
		if ( score >= 80 ) return 'bg-green-500';
		if ( score >= 60 ) return 'bg-amber-500';
		return 'bg-red-500';
	};

	const getScoreLabel = ( score: number ) => {
		if ( score >= 80 ) return 'Excellent';
		if ( score >= 60 ) return 'Good';
		return 'Limited';
	};

	// Loading State
	if ( isLoading ) {
		const loadingSteps = [
			{ icon: '🔧', text: 'Checking hosting environment...' },
			{ icon: '🔌', text: 'Analyzing installed plugins...' },
			{ icon: '⚡', text: 'Generating recommendations...' },
		];

		return (
			<div className="text-center py-8 animate-fade-in">
				<h2 className="text-2xl font-bold text-slate-900 mb-2">Analyzing Your Site</h2>
				<p className="text-slate-600 mb-8">
					We're analyzing your website to provide the best optimization recommendations.
				</p>

				{/* Spinner */}
				<div className="mb-8">
					<div className="inline-flex items-center justify-center w-20 h-20 rounded-full bg-blue-100">
						<svg className="w-10 h-10 text-blue-600 animate-spin" fill="none" viewBox="0 0 24 24">
							<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
							<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
						</svg>
					</div>
				</div>

				{/* Loading Steps */}
				<div className="max-w-sm mx-auto space-y-3">
					{ loadingSteps.map( ( step, index ) => (
						<div
							key={ index }
							className={ `
								flex items-center gap-3 p-3 rounded-lg transition-all duration-300
								${ index <= loadingStep
									? 'bg-blue-50 border border-blue-200'
									: 'bg-slate-50 border border-slate-200 opacity-50'
								}
							` }
						>
							<span className="text-xl">{ step.icon }</span>
							<span className={ index <= loadingStep ? 'text-blue-700' : 'text-slate-500' }>
								{ step.text }
							</span>
							{ index < loadingStep && (
								<svg className="w-5 h-5 text-green-500 ml-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M5 13l4 4L19 7" />
								</svg>
							) }
							{ index === loadingStep && (
								<div className="w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full animate-spin ml-auto" />
							) }
						</div>
					) ) }
				</div>
			</div>
		);
	}

	// Error State
	if ( error ) {
		return (
			<div className="text-center py-8 animate-fade-in">
				<h2 className="text-2xl font-bold text-slate-900 mb-4">Analysis Error</h2>

				<div className="max-w-md mx-auto bg-red-50 border border-red-200 rounded-xl p-6">
					<div className="w-16 h-16 mx-auto mb-4 rounded-full bg-red-100 flex items-center justify-center">
						<svg className="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
						</svg>
					</div>
					<h3 className="text-lg font-semibold text-red-800 mb-2">Unable to Analyze Site</h3>
					<p className="text-red-700 mb-4">{ error }</p>
					<button
						type="button"
						onClick={ performSiteAnalysis }
						className="inline-flex items-center gap-2 px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
					>
						<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
						</svg>
						Try Again
					</button>
				</div>
			</div>
		);
	}

	if ( ! analysis || ! recommendations ) {
		return null;
	}

	return (
		<div className="animate-fade-in">
			<div className="text-center mb-8">
				<h2 className="text-2xl font-bold text-slate-900 mb-2">Site Analysis Complete</h2>
				<p className="text-slate-600">
					We've analyzed your website and generated personalized recommendations.
				</p>
			</div>

			<div className="space-y-6">
				{/* Site Overview */}
				<div className="bg-slate-50 rounded-xl p-5">
					<h3 className="flex items-center gap-2 text-lg font-semibold text-slate-900 mb-4">
						<span className="text-xl">🏠</span>
						Site Overview
					</h3>
					<div className="grid grid-cols-2 md:grid-cols-3 gap-4">
						<div className="bg-white rounded-lg p-3 border border-slate-200">
							<div className="text-xs text-slate-500 uppercase tracking-wide">Hosting</div>
							<div className="text-sm font-medium text-slate-900 mt-1">
								{ analysis.hosting.hosting_provider !== 'Unknown'
									? analysis.hosting.hosting_provider
									: 'Custom' }
							</div>
						</div>
						<div className="bg-white rounded-lg p-3 border border-slate-200">
							<div className="text-xs text-slate-500 uppercase tracking-wide">WordPress</div>
							<div className="text-sm font-medium text-slate-900 mt-1">{ analysis.wordpress.version }</div>
						</div>
						<div className="bg-white rounded-lg p-3 border border-slate-200">
							<div className="text-xs text-slate-500 uppercase tracking-wide">PHP</div>
							<div className="text-sm font-medium text-slate-900 mt-1">{ analysis.hosting.php_version }</div>
						</div>
						<div className="bg-white rounded-lg p-3 border border-slate-200">
							<div className="text-xs text-slate-500 uppercase tracking-wide">Plugins</div>
							<div className="text-sm font-medium text-slate-900 mt-1">{ analysis.plugins.total_count } active</div>
						</div>
						<div className="bg-white rounded-lg p-3 border border-slate-200">
							<div className="text-xs text-slate-500 uppercase tracking-wide">Content</div>
							<div className="text-sm font-medium text-slate-900 mt-1">{ analysis.content.post_count } posts</div>
						</div>
						<div className="bg-white rounded-lg p-3 border border-slate-200">
							<div className="text-xs text-slate-500 uppercase tracking-wide">SSL</div>
							<div className={ `text-sm font-medium mt-1 ${ analysis.hosting.ssl_enabled ? 'text-green-600' : 'text-amber-600' }` }>
								{ analysis.hosting.ssl_enabled ? '✓ Enabled' : '⚠ Not Enabled' }
							</div>
						</div>
					</div>
				</div>

				{/* Recommended Configuration */}
				<div className="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-5 border border-blue-200">
					<h3 className="flex items-center gap-2 text-lg font-semibold text-slate-900 mb-4">
						<span className="text-xl">⭐</span>
						Recommended Configuration
					</h3>
					<div className="flex items-start gap-4">
						<div className="flex-1">
							<div className="text-xl font-bold text-blue-700 capitalize mb-2">
								{ recommendations.preset.preset } Mode
							</div>
							<div className="flex items-center gap-2 mb-3">
								<span className="text-sm text-slate-600">Confidence:</span>
								<div className="flex-1 h-2 bg-slate-200 rounded-full overflow-hidden max-w-[120px]">
									<div
										className="h-full bg-blue-500 transition-all duration-500"
										style={ { width: `${ recommendations.preset.confidence }%` } }
									/>
								</div>
								<span className="text-sm font-medium text-blue-700">
									{ recommendations.preset.confidence }%
								</span>
							</div>
							<ul className="space-y-1">
								{ recommendations.preset.reasons.map( ( reason, index ) => (
									<li key={ index } className="flex items-start gap-2 text-sm text-slate-700">
										<svg className="w-4 h-4 text-green-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
											<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M5 13l4 4L19 7" />
										</svg>
										{ reason }
									</li>
								) ) }
							</ul>
						</div>
					</div>
				</div>

				{/* Feature Compatibility */}
				<div className="bg-slate-50 rounded-xl p-5">
					<h3 className="flex items-center gap-2 text-lg font-semibold text-slate-900 mb-4">
						<span className="text-xl">🔧</span>
						Feature Compatibility
					</h3>
					<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
						{ Object.entries( analysis.compatibility ).map( ( [ feature, compat ] ) => (
							<div key={ feature } className="bg-white rounded-lg p-3 border border-slate-200">
								<div className="flex items-center justify-between mb-2">
									<span className="text-sm font-medium text-slate-700 capitalize">
										{ feature.replace( /_/g, ' ' ) }
									</span>
									<span className={ `text-xs font-semibold ${ getScoreColor( compat.score ) }` }>
										{ getScoreLabel( compat.score ) }
									</span>
								</div>
								<div className="h-1.5 bg-slate-200 rounded-full overflow-hidden">
									<div
										className={ `h-full ${ getScoreBg( compat.score ) } transition-all duration-500` }
										style={ { width: `${ compat.score }%` } }
									/>
								</div>
							</div>
						) ) }
					</div>
				</div>
			</div>
		</div>
	);
}

export default SiteDetectionStep;
