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
import ConfirmDialog from './common/ConfirmDialog';

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
	const [ confirmRemove, setConfirmRemove ] = useState( false );

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

			{ /* Processing Status — shown above the grid */ }
			{ ( bgProcessing || bgJobsQueued > 0 ) && (
				<div className="db-notification db-notification--success">
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
						<div className="dashboard-img-stats">
							{ [ 'webp', 'avif' ].map( ( format ) => (
								<div
									key={ format }
									className="dashboard-img-format"
								>
									<div className="dashboard-card-label">
										{ format.toUpperCase() }
									</div>
									<div className="img-stat-row">
										<strong>
											{ completed[ format ]?.length || 0 }
										</strong>{ ' ' }
										{ translations.completed }
									</div>
									<div className="img-stat-row img-stat-row--muted">
										<strong>
											{ pending[ format ]?.length || 0 }
										</strong>{ ' ' }
										{ translations.pending }
									</div>
								</div>
							) ) }
						</div>
					</div>

					<div className="dashboard-card-actions">
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
							onClick={ () => setConfirmRemove( true ) }
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
							<div className="activities-empty">
								{ translations.loadingRecentActivities }
							</div>
						</li>
					) }
				</ul>
			</div>

			{ /* Confirm dialog for Remove Optimized Images */ }
			<ConfirmDialog
				isOpen={ confirmRemove }
				onConfirm={ () => {
					setConfirmRemove( false );
					removeImages();
				} }
				onCancel={ () => setConfirmRemove( false ) }
				title={
					translations.confirmRemoveImgTitle ||
					'Remove Optimized Images'
				}
				message={
					translations.confirmRemoveImgMsg ||
					'This will delete all optimized WebP and AVIF copies. Original images will not be affected.'
				}
				confirmLabel={ translations.deleteBtn || 'Delete' }
				variant="danger"
			/>
		</div>
	);
};

export default Dashboard;
