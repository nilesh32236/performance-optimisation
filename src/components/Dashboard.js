import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { apiCall } from '../lib/apiRequest';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faSpinner,
	faBolt,
	faCode,
	faImages,
	faHistory,
} from '@fortawesome/free-solid-svg-icons';
import LoadingSubmitButton from './common/LoadingSubmitButton';

const Dashboard = ( { activities } ) => {
	const translations = wppoSettings.translations;

	// Initialize state
	const [ state, setState ] = useState( {
		totalCacheSize: wppoSettings.cache_size,
		totalJs: wppoSettings.total_js_css.js,
		totalCss: wppoSettings.total_js_css.css,
		imageInfo: wppoSettings.image_info || [],
		loading: {
			clear_cache: false,
			optimize_images: false,
			remove_images: false,
		},
	} );

	const [ bgProcessing, setBgProcessing ] = useState( false );
	const [ bgJobsQueued, setBgJobsQueued ] = useState( 0 );
	const pollingRef = useRef( null );

	const { imageInfo, loading, totalCacheSize, totalJs, totalCss } = state;
	const { completed = {}, pending = {} } = imageInfo;

	const updateState = useCallback( ( updates ) => {
		setState( ( prevState ) => ( { ...prevState, ...updates } ) );
	}, [] );

	const handleLoading = useCallback( ( key, isLoading ) => {
		setState( ( prevState ) => ( {
			...prevState,
			loading: { ...prevState.loading, [ key ]: isLoading },
		} ) );
	}, [] );

	const updateCache = useCallback( () => {
		updateState( {
			totalCacheSize: '0 B',
			totalJs: 0,
			totalCss: 0,
		} );
	}, [ updateState ] );

	const pollJobStatus = useCallback( async () => {
		try {
			const response = await apiCall( 'image_job_status', {}, 'GET' );
			if ( response.success && response.data ) {
				const { queued_jobs: queuedJobs } = response.data;
				setBgJobsQueued( queuedJobs );

				updateState( {
					imageInfo: {
						completed: {
							webp: Array(
								response.data.completed?.webp || 0
							).fill( '' ),
							avif: Array(
								response.data.completed?.avif || 0
							).fill( '' ),
						},
						pending: {
							webp: Array(
								response.data.pending?.webp || 0
							).fill( '' ),
							avif: Array(
								response.data.pending?.avif || 0
							).fill( '' ),
						},
						failed: {
							webp: Array( response.data.failed?.webp || 0 ).fill(
								''
							),
							avif: Array( response.data.failed?.avif || 0 ).fill(
								''
							),
						},
					},
				} );

				if ( queuedJobs === 0 ) {
					setBgProcessing( false );
					if ( pollingRef.current ) {
						clearInterval( pollingRef.current );
						pollingRef.current = null;
					}
				}
			}
		} catch ( error ) {
			console.error( 'Error polling job status:', error );
		}
	}, [ updateState ] );

	useEffect( () => {
		return () => {
			if ( pollingRef.current ) {
				clearInterval( pollingRef.current );
			}
		};
	}, [] );

	const onClearCache = useCallback(
		( e ) => {
			e.preventDefault();
			handleLoading( 'clear_cache', true );
			apiCall( 'clear_cache', { action: 'clear_cache' } )
				.then( ( data ) => {
					if ( data.success ) {
						updateCache();
					}
				} )
				.finally( () => handleLoading( 'clear_cache', false ) );
		},
		[ handleLoading, updateCache ]
	);

	const optimizeImages = useCallback( () => {
		handleLoading( 'optimize_images', true );
		const { webp = [], avif = [] } = pending;

		apiCall( 'optimise_image', { webp, avif } )
			.then( ( response ) => {
				if ( response.data?.background ) {
					setBgProcessing( true );
					setBgJobsQueued( response.data.jobs_queued || 0 );
					if ( pollingRef.current ) {
						clearInterval( pollingRef.current );
					}
					pollingRef.current = setInterval( pollJobStatus, 5000 );
				}
			} )
			.finally( () => handleLoading( 'optimize_images', false ) );
	}, [ handleLoading, pending, pollJobStatus ] );

	const removeImages = useCallback( () => {
		handleLoading( 'remove_images', true );
		apiCall( 'delete_optimised_image', {} )
			.then( ( data ) => {
				if ( data.success ) {
					wppoSettings.image_info.completed = { webp: [], avif: [] };
				}
			} )
			.finally( () => handleLoading( 'remove_images', false ) );
	}, [ handleLoading ] );

	useEffect( () => {
		updateState( {
			totalCacheSize: wppoSettings.cache_size,
			totalJs: wppoSettings.total_js_css.js,
			totalCss: wppoSettings.total_js_css.css,
			imageInfo: wppoSettings.image_info || state.imageInfo,
		} );
	}, [ updateState, state.imageInfo ] );

	return (
		<div className="settings-form fadeIn">
			<h2>{ translations.dashboard }</h2>

			<div className="dashboard-overview">
				{ /* Cache Status */ }
				<div className="dashboard-card">
					<div>
						<h3>
							<FontAwesomeIcon icon={ faBolt } />{ ' ' }
							{ translations.cacheStatus }
						</h3>
						<div className="dashboard-card-label">
							{ translations.currentCacheSize }
						</div>
						<div className="dashboard-card-value">
							{ totalCacheSize }
						</div>
					</div>
					<LoadingSubmitButton
						className="submit-button"
						onClick={ onClearCache }
						isLoading={ loading.clear_cache }
						label={ translations.clearCacheNow }
						loadingLabel={ translations.clearing }
					/>
				</div>

				{ /* Assets Optimization */ }
				<div className="dashboard-card">
					<div>
						<h3>
							<FontAwesomeIcon icon={ faCode } />{ ' ' }
							{ translations.JSCSSOptimisation }
						</h3>
						<div className="dashboard-card-label">
							{ translations.JSFilesMinified }
						</div>
						<div className="dashboard-card-value">{ totalJs }</div>
						<div className="dashboard-card-label">
							{ translations.CSSFilesMinified }
						</div>
						<div className="dashboard-card-value">{ totalCss }</div>
					</div>
				</div>

				{ /* Images Performance */ }
				<div className="dashboard-card">
					<div>
						<h3>
							<FontAwesomeIcon icon={ faImages } />{ ' ' }
							{ translations.imageOptimization }
						</h3>
						<div
							style={ {
								display: 'grid',
								gridTemplateColumns: '1fr 1fr',
								gap: '16px',
								marginBottom: '24px',
							} }
						>
							{ [ 'webp', 'avif' ].map( ( format ) => (
								<div key={ format }>
									<div
										className="dashboard-card-label"
										style={ {
											color: 'var(--wppo-primary)',
										} }
									>
										{ format.toUpperCase() }
									</div>
									<div
										style={ {
											fontSize: '14px',
											marginBottom: '4px',
										} }
									>
										<strong>
											{ completed[ format ]?.length || 0 }
										</strong>{ ' ' }
										{ translations.completed }
									</div>
									<div
										style={ {
											fontSize: '14px',
											color: 'var(--wppo-text-light)',
										} }
									>
										<strong>
											{ pending[ format ]?.length || 0 }
										</strong>{ ' ' }
										{ translations.pending }
									</div>
								</div>
							) ) }
						</div>
					</div>

					<div
						style={ {
							display: 'flex',
							flexWrap: 'wrap',
							gap: '12px',
						} }
					>
						<LoadingSubmitButton
							className="submit-button"
							onClick={ optimizeImages }
							isLoading={ loading.optimize_images }
							disabled={
								bgProcessing ||
								( ! pending.webp?.length &&
									! pending.avif?.length )
							}
							label={ translations.optimiseNow }
							loadingLabel={ translations.optimizing }
						/>
						<LoadingSubmitButton
							className="submit-button secondary"
							onClick={ removeImages }
							isLoading={ loading.remove_images }
							disabled={
								! completed.webp?.length &&
								! completed.avif?.length
							}
							label={ translations.removeOptimized }
							loadingLabel={ translations.removing }
						/>
					</div>
				</div>
			</div>

			{ /* Processing Status Bar (Absolute/Floating) */ }
			{ ( bgProcessing || bgJobsQueued > 0 ) && (
				<div
					className="db-notification db-notification--success"
					style={ { marginBottom: '48px' } }
				>
					<FontAwesomeIcon icon={ faSpinner } spin />
					<span>
						{ translations.imgProcessing ||
							'Processing background optimization jobs...' }
						<strong>
							{ ' ' }
							({ bgJobsQueued }{ ' ' }
							{ translations.imgJobsQueued || 'queued' })
						</strong>
					</span>
				</div>
			) }

			{ /* Recent Activity Timeline */ }
			<div className="recent-activities">
				<h3>
					<FontAwesomeIcon icon={ faHistory } />{ ' ' }
					{ translations.recentActivities }
				</h3>
				<ul>
					{ activities?.length ? (
						activities.map( ( activity, index ) => (
							<li key={ index }>
								<div
									dangerouslySetInnerHTML={ {
										__html: activity.activity,
									} }
								/>
							</li>
						) )
					) : (
						<li>
							<div
								style={ {
									background: 'transparent',
									boxShadow: 'none',
									border: 'none',
									padding: 0,
								} }
							>
								{ translations.loadingRecentActivities }
							</div>
						</li>
					) }
				</ul>
			</div>
		</div>
	);
};

export default Dashboard;
