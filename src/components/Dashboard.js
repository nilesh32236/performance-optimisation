import React, { useState, useEffect, useCallback, useRef } from 'react';
import { apiCall } from '../lib/apiRequest';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheckCircle } from '@fortawesome/free-solid-svg-icons';
import LoadingSubmitButton from './common/LoadingSubmitButton';

const Dashboard = ( { activities } ) => {
	const translations = wppoSettings.translations;

	// Initialize state
	const [ state, setState ] = useState( {
		totalCacheSize: wppoSettings.cache_size,
		total_js: wppoSettings.total_js_css.js,
		total_css: wppoSettings.total_js_css.css,
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

	// Memoizing the image information to reduce unnecessary re-renders
	const { imageInfo, loading, totalCacheSize, total_js, total_css } = state;
	const { completed = {}, pending = {}, failed = {} } = imageInfo;

	// General function to update state
	const updateState = useCallback( ( updates ) => {
		setState( ( prevState ) => ( { ...prevState, ...updates } ) );
	}, [] );

	// Handle loading state changes
	const handleLoading = useCallback( ( key, isLoading ) => {
		setState( ( prevState ) => ( {
			...prevState,
			loading: { ...prevState.loading, [ key ]: isLoading },
		} ) );
	}, [] );

	// Update cache values in state
	const updateCache = useCallback( () => {
		updateState( {
			totalCacheSize: 0,
			total_js: 0,
			total_css: 0,
		} );
	}, [ updateState ] );

	// Poll for background image job status
	const pollJobStatus = useCallback( async () => {
		try {
			const response = await apiCall( 'image_job_status', {} );
			if ( response.success && response.data ) {
				const { queued_jobs } = response.data;
				setBgJobsQueued( queued_jobs );

				// Update image info counts
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

				if ( queued_jobs === 0 ) {
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

	// Cleanup polling on unmount
	useEffect( () => {
		return () => {
			if ( pollingRef.current ) {
				clearInterval( pollingRef.current );
			}
		};
	}, [] );

	// Clear Cache Handler
	const onClearCache = useCallback(
		( e ) => {
			e.preventDefault();
			handleLoading( 'clear_cache', true );
			apiCall( 'clear_cache', { action: 'clear_cache' } )
				.then( ( data ) => {
					if ( data.success ) {
						updateCache();
					} else {
						console.error( translations.errorClearCache, data.message || '' );
					}
				} )
				.catch( ( error ) =>
					console.error( translations.errorClearCache, error )
				)
				.finally( () => handleLoading( 'clear_cache', false ) );
		},
		[ handleLoading, updateCache, translations ]
	);

	// Optimize Pending Images
	const optimizeImages = useCallback( () => {
		handleLoading( 'optimize_images', true );

		const { webp = [], avif = [] } = pending;
		if ( ! webp.length && ! avif.length ) {
			alert( translations.noPendingImage );
			handleLoading( 'optimize_images', false );
			return;
		}

		apiCall( 'optimise_image', { webp, avif } )
			.then( ( response ) => {
				// Check if response indicates background processing
				if ( response.data?.background ) {
					setBgProcessing( true );
					setBgJobsQueued( response.data.jobs_queued || 0 );

					// Start polling every 5 seconds
					if ( pollingRef.current ) {
						clearInterval( pollingRef.current );
					}
					pollingRef.current = setInterval( pollJobStatus, 5000 );
				} else {
					wppoSettings.imageInfo = response;
				}
			} )
			.catch( ( error ) =>
				console.error( translations.errorOptimiseImg, error )
			)
			.finally( () => handleLoading( 'optimize_images', false ) );
	}, [ handleLoading, pending, completed, failed, pollJobStatus ] );

	// Remove Optimized Images
	const removeImages = useCallback( () => {
		handleLoading( 'remove_images', true );

		const { webp = [], avif = [] } = completed;
		if ( ! webp.length && ! avif.length ) {
			alert( translations.noImgRemove );
			handleLoading( 'remove_images', false );
			return;
		}

		apiCall( 'delete_optimised_image', {} )
			.then( ( data ) => {
				if ( data.success ) {
					wppoSettings.image_info.completed = { webp: [], avif: [] };
				} else {
					console.error( translations.failedToRemove, data.failed );
				}
			} )
			.catch( ( error ) => {
				console.error( translations.errorRemovingImg, error );
				alert( translations.errorEccurredRemovingImg );
			} )
			.finally( () => handleLoading( 'remove_images', false ) );
	}, [ handleLoading, completed ] );

	// Sync state with wppoSettings changes
	useEffect( () => {
		updateState( {
			totalCacheSize: wppoSettings.cache_size,
			total_js: wppoSettings.total_js_css.js,
			total_css: wppoSettings.total_js_css.css,
			imageInfo: wppoSettings.image_info || state.imageInfo,
		} );
	}, [ wppoSettings, updateState, state.imageInfo ] );

	return (
		<div className="settings-form">
			<h2>{ translations.dashboard }</h2>
			<div className="dashboard-overview">
				{ /* Cache Section */ }
				<div className="dashboard-card">
					<h3>{ translations.cacheStatus }</h3>
					<p>
						{ translations.currentCacheSize } { totalCacheSize }
					</p>
					<LoadingSubmitButton
						className="clear-cache-btn"
						onClick={ onClearCache }
						isLoading={ loading.clear_cache }
						label={ translations.clearCacheNow }
						loadingLabel={ translations.clearing }
					/>
				</div>

				{ /* JavaScript & CSS Optimization Section */ }
				<div className="dashboard-card">
					<h3>{ translations.JSCSSOptimisation }</h3>
					<p>
						{ translations.JSFilesMinified } { total_js }
					</p>
					<p>
						{ translations.CSSFilesMinified } { total_css }
					</p>
				</div>

				{ /* Image Optimization Section */ }
				<div className="dashboard-card image-overview">
					<h3>{ translations.imageOptimization }</h3>
					<div className="status-group">
						{ [ 'webp', 'avif' ].map( ( format ) => (
							<div key={ format } className="status-item">
								<h4>{ format.toUpperCase() }</h4>
								<p>
									{ translations.completed }:{ ' ' }
									{ completed[ format ]?.length || 0 }
								</p>
								<p>
									{ translations.pending }:{ ' ' }
									{ pending[ format ]?.length || 0 }
								</p>
								<p>
									{ translations.failed }:{ ' ' }
									{ failed[ format ]?.length || 0 }
								</p>
							</div>
						) ) }
					</div>

					{ /* Background Processing Status */ }
					{ bgProcessing && (
						<div className="img-job-status img-job-status--processing">
							<FontAwesomeIcon icon={ faSpinner } spin />
							<span>
								{ translations.imgProcessing ||
									'Processing in background...' }{ ' ' }
								({ translations.imgJobsQueued || 'Jobs Queued' }
								: { bgJobsQueued })
							</span>
						</div>
					) }
					{ ! bgProcessing &&
						bgJobsQueued === 0 &&
						state.imageInfo?.completed &&
						( completed.webp?.length > 0 ||
							completed.avif?.length > 0 ) && (
							<div className="img-job-status img-job-status--complete">
								<FontAwesomeIcon icon={ faCheckCircle } />
								<span>
									{ translations.imgJobsComplete ||
										'All background jobs complete!' }
								</span>
							</div>
						) }

					<div className="action-buttons">
						<LoadingSubmitButton
							className="optimize-images-btn"
							onClick={ optimizeImages }
							isLoading={ loading.optimize_images }
							disabled={ bgProcessing }
							label={ translations.optimiseNow }
							loadingLabel={ translations.optimizing }
						/>
						<LoadingSubmitButton
							className="remove-optimized-btn"
							onClick={ removeImages }
							isLoading={ loading.remove_images }
							label={ translations.removeOptimized }
							loadingLabel={ translations.removing }
						/>
					</div>
				</div>
			</div>

			{ /* Recent Activities */ }
			<div className="recent-activities">
				<h3>{ translations.recentActivities }</h3>
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
						<li>{ translations.loadingRecentActivities }</li>
					) }
				</ul>
			</div>
		</div>
	);
};

export default Dashboard;
