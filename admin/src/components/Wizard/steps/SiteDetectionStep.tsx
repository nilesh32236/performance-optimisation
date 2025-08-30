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

	useEffect( () => {
		performSiteAnalysis();
	}, [] );

	const performSiteAnalysis = async () => {
		setIsLoading( true );
		setError( null );

		try {
			// Get site analysis
			const analysisResponse = await fetch( `${ window.wppoWizardData.apiUrl }/wizard/analysis`, {
				headers: {
					'X-WP-Nonce': window.wppoWizardData.nonce,
				},
			} );

			if ( ! analysisResponse.ok ) {
				throw new Error( 'Failed to analyze site' );
			}

			const analysisResult = await analysisResponse.json();
			if ( ! analysisResult.success ) {
				throw new Error( analysisResult.message || 'Analysis failed' );
			}

			setAnalysis( analysisResult.data.analysis );

			// Get recommendations
			const recommendationsResponse = await fetch(
				`${ window.wppoWizardData.apiUrl }recommendations`,
				{
					headers: {
						'X-WP-Nonce': window.wppoWizardData.nonce,
					},
				},
			);

			if ( ! recommendationsResponse.ok ) {
				throw new Error( 'Failed to get recommendations' );
			}

			const recommendationsResult = await recommendationsResponse.json();
			if ( ! recommendationsResult.success ) {
				throw new Error( recommendationsResult.message || 'Recommendations failed' );
			}

			setRecommendations( recommendationsResult.data.recommendations );

			// Auto-select recommended preset
			const recommendedPreset = recommendationsResult.data.recommendations.preset.preset;
			updateData( 'preset', recommendedPreset );
			updateData( 'siteAnalysis', analysisResult.data.analysis );
			updateData( 'recommendations', recommendationsResult.data.recommendations );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : 'An error occurred during site analysis' );
		} finally {
			setIsLoading( false );
		}
	};

	const getCompatibilityColor = ( score: number ) => {
		if ( score >= 80 ) {
			return '#00a32a';
		}
		if ( score >= 60 ) {
			return '#dba617';
		}
		return '#d63638';
	};

	const getCompatibilityText = ( score: number ) => {
		if ( score >= 80 ) {
			return 'Excellent';
		}
		if ( score >= 60 ) {
			return 'Good';
		}
		return 'Limited';
	};

	if ( isLoading ) {
		return (
			<div className="wppo-wizard-step wppo-site-detection-step">
				<div className="wppo-step-header">
					<h2>Analyzing Your Site</h2>
					<p className="wppo-step-description">
						We're analyzing your website to provide the best optimization
						recommendations.
					</p>
				</div>

				<div className="wppo-analysis-loading">
					<div className="wppo-loading-spinner">
						<div className="wppo-spinner"></div>
					</div>
					<div className="wppo-loading-steps">
						<div className="wppo-loading-step active">
							<span className="wppo-step-icon">
								<span className="dashicons dashicons-admin-tools"></span>
							</span>
							<span className="wppo-step-text">Checking hosting environment...</span>
						</div>
						<div className="wppo-loading-step">
							<span className="wppo-step-icon">
								<span className="dashicons dashicons-admin-plugins"></span>
							</span>
							<span className="wppo-step-text">Analyzing installed plugins...</span>
						</div>
						<div className="wppo-loading-step">
							<span className="wppo-step-icon">
								<span className="dashicons dashicons-performance"></span>
							</span>
							<span className="wppo-step-text">Generating recommendations...</span>
						</div>
					</div>
				</div>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="wppo-wizard-step wppo-site-detection-step">
				<div className="wppo-step-header">
					<h2>Analysis Error</h2>
				</div>

				<div className="wppo-analysis-error">
					<div className="wppo-error-icon">
						<span className="dashicons dashicons-warning"></span>
					</div>
					<div className="wppo-error-content">
						<h3>Unable to Analyze Site</h3>
						<p>{ error }</p>
						<button
							type="button"
							className="wppo-button wppo-button--primary"
							onClick={ performSiteAnalysis }
						>
							Try Again
						</button>
					</div>
				</div>
			</div>
		);
	}

	if ( ! analysis || ! recommendations ) {
		return null;
	}

	return (
		<div className="wppo-wizard-step wppo-site-detection-step">
			<div className="wppo-step-header">
				<h2>Site Analysis Complete</h2>
				<p className="wppo-step-description">
					We've analyzed your website and generated personalized recommendations.
				</p>
			</div>

			<div className="wppo-analysis-results">
				{ /* Site Overview */ }
				<div className="wppo-analysis-section">
					<h3>
						<span className="dashicons dashicons-admin-home"></span>
						Site Overview
					</h3>
					<div className="wppo-overview-grid">
						<div className="wppo-overview-item">
							<div className="wppo-overview-label">Hosting Provider</div>
							<div className="wppo-overview-value">
								{ analysis.hosting.hosting_provider !== 'Unknown'
									? analysis.hosting.hosting_provider
									: 'Custom/Unknown' }
							</div>
						</div>
						<div className="wppo-overview-item">
							<div className="wppo-overview-label">WordPress Version</div>
							<div className="wppo-overview-value">{ analysis.wordpress.version }</div>
						</div>
						<div className="wppo-overview-item">
							<div className="wppo-overview-label">PHP Version</div>
							<div className="wppo-overview-value">
								{ analysis.hosting.php_version }
							</div>
						</div>
						<div className="wppo-overview-item">
							<div className="wppo-overview-label">Active Plugins</div>
							<div className="wppo-overview-value">
								{ analysis.plugins.total_count }
							</div>
						</div>
						<div className="wppo-overview-item">
							<div className="wppo-overview-label">Content Items</div>
							<div className="wppo-overview-value">
								{ analysis.content.post_count } posts, { analysis.content.media_count }{ ' ' }
								media files
							</div>
						</div>
						<div className="wppo-overview-item">
							<div className="wppo-overview-label">SSL Status</div>
							<div className="wppo-overview-value">
								{ analysis.hosting.ssl_enabled ? (
									<span className="wppo-status-good">
										<span className="dashicons dashicons-yes-alt"></span>
										Enabled
									</span>
								) : (
									<span className="wppo-status-warning">
										<span className="dashicons dashicons-warning"></span>
										Not Enabled
									</span>
								) }
							</div>
						</div>
					</div>
				</div>

				{ /* Recommended Preset */ }
				<div className="wppo-analysis-section">
					<h3>
						<span className="dashicons dashicons-star-filled"></span>
						Recommended Configuration
					</h3>
					<div className="wppo-recommended-preset">
						<div className="wppo-preset-info">
							<h4>
								{ recommendations.preset.preset.charAt( 0 ).toUpperCase() +
									recommendations.preset.preset.slice( 1 ) }{ ' ' }
								Mode
							</h4>
							<div className="wppo-confidence-meter">
								<span className="wppo-confidence-label">Confidence:</span>
								<div className="wppo-confidence-bar">
									<div
										className="wppo-confidence-fill"
										style={ { width: `${ recommendations.preset.confidence }%` } }
									></div>
								</div>
								<span className="wppo-confidence-value">
									{ recommendations.preset.confidence }%
								</span>
							</div>
						</div>
						<div className="wppo-preset-reasons">
							<h5>Why this configuration?</h5>
							<ul>
								{ recommendations.preset.reasons.map( ( reason, index ) => (
									<li key={ index }>
										<span className="dashicons dashicons-yes-alt"></span>
										{ reason }
									</li>
								) ) }
							</ul>
						</div>
					</div>
				</div>

				{ /* Compatibility Check */ }
				<div className="wppo-analysis-section">
					<h3>
						<span className="dashicons dashicons-admin-tools"></span>
						Feature Compatibility
					</h3>
					<div className="wppo-compatibility-grid">
						{ Object.entries( analysis.compatibility ).map( ( [ feature, compat ] ) => (
							<div key={ feature } className="wppo-compatibility-item">
								<div className="wppo-compatibility-header">
									<span className="wppo-feature-name">
										{ feature
											.replace( /_/g, ' ' )
											.replace( /\b\w/g, ( l ) => l.toUpperCase() ) }
									</span>
									<span
										className="wppo-compatibility-score"
										style={ { color: getCompatibilityColor( compat.score ) } }
									>
										{ getCompatibilityText( compat.score ) }
									</span>
								</div>
								<div className="wppo-compatibility-bar">
									<div
										className="wppo-compatibility-fill"
										style={ {
											width: `${ compat.score }%`,
											backgroundColor: getCompatibilityColor( compat.score ),
										} }
									></div>
								</div>
							</div>
						) ) }
					</div>
				</div>

				{ /* Conflicts and Warnings */ }
				{ ( analysis.conflicts.length > 0 || recommendations.personalized.length > 0 ) && (
					<div className="wppo-analysis-section">
						<h3>
							<span className="dashicons dashicons-info"></span>
							Important Notices
						</h3>

						{ analysis.conflicts.length > 0 && (
							<div className="wppo-conflicts">
								<h4>Potential Conflicts</h4>
								{ analysis.conflicts.map( ( conflict, index ) => (
									<div key={ index } className="wppo-conflict-item">
										<div className="wppo-conflict-icon">
											<span className="dashicons dashicons-warning"></span>
										</div>
										<div className="wppo-conflict-content">
											<h5>{ conflict.title }</h5>
											<p>{ conflict.description }</p>
											{ conflict.resolution && (
												<p className="wppo-conflict-resolution">
													<strong>Resolution:</strong>{ ' ' }
													{ conflict.resolution }
												</p>
											) }
										</div>
									</div>
								) ) }
							</div>
						) }

						{ recommendations.personalized.length > 0 && (
							<div className="wppo-recommendations">
								<h4>Personalized Recommendations</h4>
								{ recommendations.personalized.slice( 0, 3 ).map( ( rec, index ) => (
									<div key={ index } className="wppo-recommendation-item">
										<div className="wppo-recommendation-icon">
											<span
												className={ `dashicons dashicons-${
													rec.priority === 'high'
														? 'warning'
														: rec.priority === 'medium'
															? 'info'
															: 'lightbulb'
												}` }
											></span>
										</div>
										<div className="wppo-recommendation-content">
											<h5>{ rec.title }</h5>
											<p>{ rec.description }</p>
										</div>
									</div>
								) ) }
							</div>
						) }
					</div>
				) }
			</div>
		</div>
	);
}

export default SiteDetectionStep;
