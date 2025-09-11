/**
 * Main App Component
 *
 * @package
 * @since 1.1.0
 */

/**
 * External dependencies
 */
import React, { useState, useEffect } from 'react';
import { PluginConfig } from '@types/index';
import { Card, Button, LoadingSpinner } from '@components/index';

export const App: React.FC = () => {
	const [ config, setConfig ] = useState<PluginConfig | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

	useEffect( () => {
		// Load initial configuration from WordPress
		const loadConfig = async () => {
			try {
				if ( window.wppoAdmin?.config ) {
					setConfig( window.wppoAdmin.config );
				} else {
					throw new Error( 'Configuration not available' );
				}
			} catch ( err ) {
				setError( err instanceof Error ? err.message : 'Failed to load configuration' );
			} finally {
				setLoading( false );
			}
		};

		loadConfig();
	}, [] );

	if ( loading ) {
		return (
			<div className="wppo-admin-loading">
				<LoadingSpinner size="large" />
				<p>Loading Performance Optimisation settings...</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="wppo-admin-error">
				<Card title="Error" description={ error }>
					<Button onClick={ () => window.location.reload() }>Reload Page</Button>
				</Card>
			</div>
		);
	}

	return (
		<div className="wppo-admin">
			<div className="wppo-admin__header">
				<h1>Performance Optimisation</h1>
				<p>Optimize your WordPress site for better performance and user experience.</p>
			</div>

			<div className="wppo-admin__content">
				<div className="wppo-admin__grid">
					<Card title="Caching" description="Configure page and object caching settings">
						<div className="wppo-setting-group">
							<label htmlFor="page-cache-enabled">
								<input
									id="page-cache-enabled"
									type="checkbox"
									checked={ config?.caching?.page_cache_enabled || false }
									onChange={ ( e ) => {
										if ( config ) {
											setConfig( {
												...config,
												caching: {
													...config.caching,
													page_cache_enabled: e.target.checked,
												},
											} );
										}
									} }
								/>
								Enable Page Caching
							</label>
						</div>
					</Card>

					<Card title="Minification" description="Minify CSS, JavaScript, and HTML files">
						<div className="wppo-setting-group">
							<label htmlFor="minify-css">
								<input
									id="minify-css"
									type="checkbox"
									checked={ config?.minification?.minify_css || false }
									onChange={ ( e ) => {
										if ( config ) {
											setConfig( {
												...config,
												minification: {
													...config.minification,
													minify_css: e.target.checked,
												},
											} );
										}
									} }
								/>
								Minify CSS
							</label>
						</div>
					</Card>

					<Card
						title="Image Optimization"
						description="Optimize images for better performance"
					>
						<div className="wppo-setting-group">
							<label htmlFor="lazy-loading">
								<input
									id="lazy-loading"
									type="checkbox"
									checked={ config?.images?.lazy_loading || false }
									onChange={ ( e ) => {
										if ( config ) {
											setConfig( {
												...config,
												images: {
													...config.images,
													lazy_loading: e.target.checked,
												},
											} );
										}
									} }
								/>
								Enable Lazy Loading
							</label>
						</div>
					</Card>
				</div>

				<div className="wppo-admin__actions">
					<Button variant="primary">Save Settings</Button>
					<Button variant="secondary">Reset to Defaults</Button>
				</div>
			</div>
		</div>
	);
};
