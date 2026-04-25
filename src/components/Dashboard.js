import {
	useState,
	useEffect,
	useCallback,
	useRef,
	useMemo,
} from '@wordpress/element';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import ConfirmDialog from './common/ConfirmDialog';
import FeatureHeader from './common/FeatureHeader';
import PerformanceAudit from './PerformanceAudit';
import PageSpeedPanel from './PageSpeedPanel';
import SuggestionsPanel from './SuggestionsPanel';
import SystemInfo from './SystemInfo';
import ImageOptimizationCard from './ImageOptimizationCard';
import RecentActivityCard from './RecentActivityCard';

/**
 * Normalize wppoSettings.image_info which stores arrays of file paths
 * into the {webp: count, avif: count} shape the component expects.
 * @param {Object} raw - Raw image info object.
 */
const normalizeImageInfo = ( raw ) => {
	const normalize = ( bucket ) => ( {
		webp: Array.isArray( bucket?.webp )
			? bucket.webp.length
			: bucket?.webp || 0,
		avif: Array.isArray( bucket?.avif )
			? bucket.avif.length
			: bucket?.avif || 0,
	} );
	return {
		completed: normalize( raw?.completed ),
		pending: normalize( raw?.pending ),
		failed: normalize( raw?.failed ),
	};
};

const Dashboard = ( { activities, onNavigate } ) => {
	// Raw pending paths from the initial API response — used for optimise_image payload.
	const rawPending = wppoSettings.image_info?.pending ?? {};
	const [ pendingPaths, setPendingPaths ] = useState( {
		webp: Array.isArray( rawPending.webp ) ? rawPending.webp : [],
		avif: Array.isArray( rawPending.avif ) ? rawPending.avif : [],
	} );

	// Phase 2 — suggestions state (populated by telemetry scan + PageSpeed scan).
	const [ telemetrySuggestions, setTelemetrySuggestions ] = useState( [] );
	const [ pagespeedSuggestions, setPagespeedSuggestions ] = useState( [] );
	const [ auditUrl, setAuditUrl ] = useState(
		wppoSettings.performance_audit?.homeUrl ?? ''
	);

	// Merge telemetry and PageSpeed suggestions, deduplicating by metric key.
	const allSuggestions = useMemo( () => {
		const seen = new Set();
		const merged = [];
		for ( const s of [
			...pagespeedSuggestions,
			...telemetrySuggestions,
		] ) {
			if ( ! seen.has( s.metric ) ) {
				seen.add( s.metric );
				merged.push( s );
			}
		}
		return merged;
	}, [ telemetrySuggestions, pagespeedSuggestions ] );

	// Initialize state
	const [ state, setState ] = useState( {
		totalCacheSize: wppoSettings.cache_size,
		totalJs: wppoSettings.total_js_css.js,
		totalCss: wppoSettings.total_js_css.css,
		imageInfo: normalizeImageInfo( wppoSettings.image_info ),
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

		apiCall( 'optimise_image', {
			webp: pendingPaths.webp,
			avif: pendingPaths.avif,
		} )
			.then( ( response ) => {
				if ( response.data?.background ) {
					// Background (Action Scheduler) path.
					setBgProcessing( true );
					setBgJobsQueued( response.data.jobs_queued || 0 );
					setPendingPaths( { webp: [], avif: [] } );
					if ( pollingRef.current ) {
						clearInterval( pollingRef.current );
					}
					pollingRef.current = setInterval( pollJobStatus, 5000 );
				} else {
					// Synchronous path (Action Scheduler unavailable).
					setPendingPaths( { webp: [], avif: [] } );
					setBgJobsQueued( 0 );
					setBgProcessing( false );

					if ( response.success && response.data ) {
						updateState( {
							imageInfo: normalizeImageInfo( response.data ),
						} );
					}

					if ( pollingRef.current ) {
						clearInterval( pollingRef.current );
						pollingRef.current = null;
					}
				}
			} )
			.finally( () => handleLoading( 'optimize_images', false ) );
	}, [ handleLoading, pendingPaths, pollJobStatus ] );

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
	const totalOptimizedPercent =
		totalWebP + totalAvif > 0
			? ( ( ( completed.webp || 0 ) + ( completed.avif || 0 ) ) /
					( totalWebP + totalAvif ) ) *
			  100
			: null;

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
				status={ <></> }
				actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						onClick={ onClearCache }
						isLoading={ loading.clear_cache }
						label="Purge All Cache"
						loadingLabel="Purging..."
					/>
				}
			/>

			{ /* Quick-stat overview strip */ }
			<div className="wppo-stats-grid">
				<div className="wppo-stat-item">
					<div className="wppo-stat-header">
						<span className="wppo-stat-label">Cache Size</span>
					</div>
					<span className="wppo-stat-value">{ totalCacheSize }</span>
					<button
						type="button"
						className="wppo-stat-link"
						onClick={ () => onNavigate( 'fileOptimization' ) }
					>
						Manage Cache →
					</button>
				</div>
				<div className="wppo-stat-item">
					<div className="wppo-stat-header">
						<span className="wppo-stat-label">Optimized Files</span>
					</div>
					<span className="wppo-stat-value">
						{ ( totalJs || 0 ) + ( totalCss || 0 ) }
					</span>
					<button
						type="button"
						className="wppo-stat-link"
						onClick={ () => onNavigate( 'fileOptimization' ) }
					>
						View Settings →
					</button>
				</div>
				<div className="wppo-stat-item">
					<div className="wppo-stat-header">
						<span className="wppo-stat-label">DB Overhead</span>
					</div>
					<span className="wppo-stat-value">{ dbOverheadCount }</span>
					<button
						type="button"
						className="wppo-stat-link"
						onClick={ () => onNavigate( 'databaseCleanup' ) }
					>
						Clean Now →
					</button>
				</div>
				<div className="wppo-stat-item">
					<div className="wppo-stat-header">
						<span className="wppo-stat-label">
							Images Optimized
						</span>
					</div>
					<span className="wppo-stat-value">
						{ totalOptimizedPercent !== null
							? `${ totalOptimizedPercent.toFixed( 0 ) }%`
							: 'N/A' }
					</span>
					<button
						type="button"
						className="wppo-stat-link"
						onClick={ () => onNavigate( 'imageOptimization' ) }
					>
						View Images →
					</button>
				</div>
			</div>

			{ /* Phase 1 — Performance Audit & System Info (v1.5.0) */ }
			<div className="wppo-stacked-cards">
				<PerformanceAudit
					onSuggestionsReady={ setTelemetrySuggestions }
					onUrlChange={ setAuditUrl }
				/>

				{ /* Phase 2 — SuggestionsPanel sits directly below PerformanceAudit (v1.6.0) */ }
				{ allSuggestions.length > 0 && (
					<SuggestionsPanel
						suggestions={ allSuggestions }
						onNavigate={ onNavigate }
					/>
				) }

				{ /* Phase 2 — PageSpeed Insights panel (v1.6.0) */ }
				<PageSpeedPanel
					url={ auditUrl }
					onSuggestionsReady={ setPagespeedSuggestions }
				/>

				<SystemInfo />
			</div>

			{ /* Image optimization + activity log */ }
			<div className="wppo-stacked-cards wppo-mt-20">
				<ImageOptimizationCard
					completed={ completed }
					pending={ pending }
					bgProcessing={ bgProcessing }
					bgJobsQueued={ bgJobsQueued }
					loading={ loading }
					pendingPathsCount={
						pendingPaths.webp.length + pendingPaths.avif.length
					}
					onOptimize={ optimizeImages }
					onRemove={ () => setConfirmRemove( true ) }
				/>

				<RecentActivityCard
					activities={ activities }
					onNavigate={ onNavigate }
				/>
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
