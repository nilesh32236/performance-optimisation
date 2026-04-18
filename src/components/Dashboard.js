import {
	useState,
	useEffect,
	useCallback,
	useRef,
	useMemo,
} from '@wordpress/element';
import { apiCall } from '../lib/apiRequest';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faSpinner,
	faImages,
	faHistory,
	faCheckCircle,
	faExclamationTriangle,
	faTimesCircle,
} from '@fortawesome/free-solid-svg-icons';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import ConfirmDialog from './common/ConfirmDialog';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';

const Dashboard = ( { activities } ) => {
	// Initialize state
	const [ state, setState ] = useState( {
		totalCacheSize: wppoSettings.cache_size,
		totalJs: wppoSettings.total_js_css.js,
		totalCss: wppoSettings.total_js_css.css,
		imageInfo: wppoSettings.image_info || {
			completed: {},
			pending: {},
			failed: {},
		},
		dbCounts: {},
		loading: {
			clear_cache: false,
			optimize_images: false,
			remove_images: false,
			db_counts: true,
		},
	} );

	const [ bgProcessing, setBgProcessing ] = useState( false );
	const [ bgJobsQueued, setBgJobsQueued ] = useState( 0 );
	const pollingRef = useRef( null );
	const [ confirmRemove, setConfirmRemove ] = useState( false );

	const { imageInfo, loading, totalCacheSize, totalJs, totalCss, dbCounts } =
		state;
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

	const fetchDbCounts = useCallback( async () => {
		handleLoading( 'db_counts', true );
		try {
			const response = await apiCall(
				'database_cleanup_counts',
				{},
				'GET'
			);
			if ( response.success && response.data ) {
				updateState( { dbCounts: response.data } );
			}
		} catch ( error ) {
			console.error( 'Error fetching db counts:', error );
		} finally {
			handleLoading( 'db_counts', false );
		}
	}, [ handleLoading, updateState ] );

	useEffect( () => {
		fetchDbCounts();
	}, [ fetchDbCounts ] );

	const dbOverheadCount = useMemo( () => {
		return Object.values( dbCounts ).reduce(
			( sum, val ) => sum + ( parseInt( val, 10 ) || 0 ),
			0
		);
	}, [ dbCounts ] );

	const pollJobStatus = useCallback( async () => {
		try {
			const response = await apiCall( 'image_job_status', {}, 'GET' );
			if ( response.success && response.data ) {
				const { queued_jobs: queuedJobs } = response.data;
				setBgJobsQueued( queuedJobs );

				updateState( {
					imageInfo: {
						completed: {
							webp: response.data.completed?.webp || 0,
							avif: response.data.completed?.avif || 0,
						},
						pending: {
							webp: response.data.pending?.webp || 0,
							avif: response.data.pending?.avif || 0,
						},
						failed: {
							webp: response.data.failed?.webp || 0,
							avif: response.data.failed?.avif || 0,
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
						updateState( {
							totalCacheSize: '0 B',
							totalJs: 0,
							totalCss: 0,
						} );
					}
				} )
				.finally( () => handleLoading( 'clear_cache', false ) );
		},
		[ handleLoading, updateState ]
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
					setState( ( prev ) => ( {
						...prev,
						imageInfo: {
							...prev.imageInfo,
							completed: { webp: 0, avif: 0 },
						},
					} ) );
				}
			} )
			.finally( () => handleLoading( 'remove_images', false ) );
	}, [ handleLoading ] );

	const totalWebP = ( completed.webp || 0 ) + ( pending.webp || 0 );
	const totalAvif = ( completed.avif || 0 ) + ( pending.avif || 0 );
	const webpPercent =
		totalWebP > 0 ? ( ( completed.webp || 0 ) / totalWebP ) * 100 : 0;
	const avifPercent =
		totalAvif > 0 ? ( ( completed.avif || 0 ) / totalAvif ) * 100 : 0;
	const totalOptimizedPercent =
		totalWebP + totalAvif > 0
			? ( ( ( completed.webp || 0 ) + ( completed.avif || 0 ) ) /
					( totalWebP + totalAvif ) ) *
			  100
			: null;

	const statusInfo = useMemo( () => {
		const hasFailures =
			( imageInfo.failed?.webp || 0 ) > 0 ||
			( imageInfo.failed?.avif || 0 ) > 0;
		if ( hasFailures || dbOverheadCount > 1000 ) {
			return {
				icon: faTimesCircle,
				text:
					wppoSettings.translations[ 'Attention required' ] ||
					'Attention required! High database overhead or image failures.',
				variant: 'error',
			};
		}
		if (
			dbOverheadCount > 0 ||
			( totalOptimizedPercent !== null && totalOptimizedPercent < 90 ) ||
			bgProcessing ||
			( pending.webp || 0 ) > 0 ||
			( pending.avif || 0 ) > 0
		) {
			return {
				icon: faExclamationTriangle,
				text:
					wppoSettings.translations[ 'Optimization pending' ] ||
					'Optimization pending. Run cleanup and image processing.',
				variant: 'warning',
			};
		}
		return {
			icon: faCheckCircle,
			text:
				wppoSettings.translations[ 'Looks Good' ] ||
				'Looks Good! System is optimized.',
			variant: 'success',
		};
	}, [
		dbOverheadCount,
		totalOptimizedPercent,
		bgProcessing,
		pending,
		imageInfo.failed,
	] );

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={
					wppoSettings.translations[ 'System Health' ] ||
					'System Health'
				}
				description={
					wppoSettings.translations[ 'System Health Description' ] ||
					'Real-time performance overview and quick optimization actions.'
				}
				status={
					<div
						className={ `wppo-feature-header__status wppo-status--${ statusInfo.variant }` }
					>
						<FontAwesomeIcon icon={ statusInfo.icon } />
						<span>{ statusInfo.text }</span>
					</div>
				}
				actions={
					<div className="wppo-feature-header__actions">
						<LoadingSubmitButton
							className="wppo-button wppo-button--primary"
							onClick={ onClearCache }
							isLoading={ loading.clear_cache }
							label="Purge All Cache"
							loadingLabel="Purging..."
						/>
						<LoadingSubmitButton
							className="wppo-button wppo-button--secondary"
							onClick={ optimizeImages }
							isLoading={ loading.optimize_images }
							disabled={
								bgProcessing ||
								( ! pending.webp && ! pending.avif )
							}
							label="Optimize All"
							loadingLabel="Optimizing..."
						/>
					</div>
				}
			/>

			<div className="wppo-stats-grid">
				<div className="wppo-stat-item">
					<span className="wppo-stat-label">Cache Size</span>
					<span className="wppo-stat-value">{ totalCacheSize }</span>
					<a href="#/tools" className="wppo-stat-link">
						View Cache
					</a>
				</div>
				<div className="wppo-stat-item">
					<span className="wppo-stat-label">JS/CSS Optimized</span>
					<span className="wppo-stat-value">
						{ ( totalJs || 0 ) + ( totalCss || 0 ) } Files
					</span>
					<a href="#/fileOptimization" className="wppo-stat-link">
						Settings
					</a>
				</div>
				<div className="wppo-stat-item">
					<span className="wppo-stat-label">DB Health</span>
					<span className="wppo-stat-value">
						{ dbOverheadCount } Overhead
					</span>
					<a href="#/databaseCleanup" className="wppo-stat-link">
						Clean Now
					</a>
				</div>
				<div className="wppo-stat-item">
					<span className="wppo-stat-label">Images Optimized</span>
					<span className="wppo-stat-value">
						{ totalOptimizedPercent !== null
							? `${ totalOptimizedPercent.toFixed( 0 ) }%`
							: 'N/A' }
					</span>
					<a href="#/imageOptimization" className="wppo-stat-link">
						Continue
					</a>
				</div>
			</div>

			<div className="wppo-grid-2-col">
				<FeatureCard
					title="Image Optimization"
					icon={ <FontAwesomeIcon icon={ faImages } /> }
					actions={
						<LoadingSubmitButton
							className="wppo-button wppo-button--danger"
							onClick={ () => setConfirmRemove( true ) }
							isLoading={ loading.remove_images }
							disabled={ ! completed.webp && ! completed.avif }
							label={
								wppoSettings.translations[
									'Remove Optimized'
								] || 'Remove Optimized'
							}
							loadingLabel="Removing..."
						/>
					}
				>
					<div className="wppo-progress-section">
						<div className="wppo-progress-header">
							<span>WebP Generation</span>
							<span>
								{ completed.webp || 0 } / { totalWebP }
							</span>
						</div>
						<div
							className="wppo-progress-bar"
							role="progressbar"
							aria-valuemin="0"
							aria-valuemax="100"
							aria-valuenow={ Math.round( webpPercent ) }
							aria-label="WebP conversion progress"
						>
							<div
								className="wppo-progress-bar__fill"
								style={ { width: `${ webpPercent }%` } }
							></div>
						</div>
					</div>

					<div className="wppo-progress-section wppo-progress-section--spaced">
						<div className="wppo-progress-header">
							<span>AVIF Generation</span>
							<span>
								{ completed.avif || 0 } / { totalAvif }
							</span>
						</div>
						<div
							className="wppo-progress-bar"
							role="progressbar"
							aria-valuemin="0"
							aria-valuemax="100"
							aria-valuenow={ Math.round( avifPercent ) }
							aria-label="AVIF conversion progress"
						>
							<div
								className="wppo-progress-bar__fill"
								style={ { width: `${ avifPercent }%` } }
							></div>
						</div>
					</div>

					{ ( bgProcessing || bgJobsQueued > 0 ) && (
						<div
							className="wppo-notice wppo-notice--info"
							style={ { marginTop: '24px' } }
						>
							<FontAwesomeIcon icon={ faSpinner } spin />
							<span>
								Processing background optimization jobs (
								{ bgJobsQueued } queued)
							</span>
						</div>
					) }
				</FeatureCard>

				<FeatureCard
					title="Recent Activity"
					icon={ <FontAwesomeIcon icon={ faHistory } /> }
					actions={
						<a href="#/activityLog" className="wppo-stat-link">
							View Full Log
						</a>
					}
				>
					<ul
						className="wppo-activity-list"
						style={ { listStyle: 'none', padding: 0, margin: 0 } }
					>
						{ activities?.length ? (
							activities
								.slice( 0, 5 )
								.map( ( activity, index ) => (
									<li
										key={ index }
										style={ {
											padding: '12px 0',
											borderBottom:
												'1px solid var(--wppo-border)',
										} }
									>
										<div style={ { fontSize: '13px' } }>
											{ activity.activity }
										</div>
									</li>
								) )
						) : (
							<li className="wppo-text-muted">
								No recent activity found.
							</li>
						) }
					</ul>
				</FeatureCard>
			</div>

			<ConfirmDialog
				isOpen={ confirmRemove }
				onConfirm={ () => {
					setConfirmRemove( false );
					removeImages();
				} }
				onCancel={ () => setConfirmRemove( false ) }
				title={
					wppoSettings.translations[ 'Remove Optimized Images' ] ||
					'Remove Optimized Images'
				}
				message="This will delete all optimized WebP and AVIF copies. Original images will not be affected."
				confirmLabel={ wppoSettings.translations.Delete || 'Delete' }
				variant="danger"
			/>
		</div>
	);
};

export default Dashboard;
