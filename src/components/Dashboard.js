import {
	useState,
	useEffect,
	useCallback,
	useRef,
	useMemo,
} from '@wordpress/element';
import { apiCall } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import ConfirmDialog from './common/ConfirmDialog';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import SwitchField from './common/SwitchField';
import CheckboxOption from './common/CheckboxOption';
import NoticeBanner from './common/NoticeBanner';
import PerformanceAudit from './PerformanceAudit';
import PageSpeedPanel from './PageSpeedPanel';
import WebVitalsTrends from './WebVitalsTrends';
import WebVitalsRum from './WebVitalsRum';
import SuggestionsPanel from './SuggestionsPanel';
import SystemInfo from './SystemInfo';
import AutoloadedOptions from './AutoloadedOptions';
import LlmsPanel from './LlmsPanel';
import AiPanel from './AiPanel';
import EdgeCachePanel from './EdgeCachePanel';
import ImageOptimizationCard from './ImageOptimizationCard';
import RecentActivityCard from './RecentActivityCard';
import WelcomePanel from './WelcomePanel';
import { __ } from '@wordpress/i18n';
import { modeLabel } from '../lib/litespeed';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faServer,
	faFileCode,
	faDatabase,
	faImages,
	faExclamationTriangle,
	faBroom,
} from '@fortawesome/free-solid-svg-icons';

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

const Dashboard = ( {
	activities,
	cacheSettings: propCacheSettings,
	userRoles: propUserRoles,
	onNavigate,
} ) => {
	// Phase 2 — suggestions state (populated by telemetry scan + PageSpeed scan).
	const [ telemetrySuggestions, setTelemetrySuggestions ] = useState( [] );
	const [ pagespeedSuggestions, setPagespeedSuggestions ] = useState( [] );
	const [ auditUrl, setAuditUrl ] = useState(
		typeof wppoSettings !== 'undefined'
			? wppoSettings?.performance_audit?.homeUrl ?? ''
			: ''
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

	// Reset suggestions when auditUrl changes to prevent stale results from merging.
	useEffect( () => {
		setTelemetrySuggestions( [] );
		setPagespeedSuggestions( [] );
	}, [ auditUrl ] );

	// Initialize state
	const [ state, setState ] = useState( {
		totalCacheSize:
			typeof wppoSettings !== 'undefined'
				? wppoSettings?.cache_size ?? '0 B'
				: '0 B',
		totalJs:
			typeof wppoSettings !== 'undefined'
				? wppoSettings?.total_js_css?.js ?? 0
				: 0,
		totalCss:
			typeof wppoSettings !== 'undefined'
				? wppoSettings?.total_js_css?.css ?? 0
				: 0,
		imageInfo: normalizeImageInfo(
			typeof wppoSettings !== 'undefined' ? wppoSettings?.image_info : {}
		),
		dbCounts: {},
		loading: {
			clear_cache: false,
			optimize_images: false,
			remove_images: false,
			db_counts: true,
		},
	} );

	// Logged-in user cache settings — prefer props from App.js, fallback to global for direct mounts/tests.
	const cacheSettings =
		propCacheSettings ??
		( typeof wppoSettings !== 'undefined'
			? wppoSettings?.settings?.cache_settings || {}
			: {} );
	const userRoles =
		propUserRoles ??
		( typeof wppoSettings !== 'undefined'
			? wppoSettings?.userRoles || {}
			: {} );
	const [ pageCacheEnabled, setPageCacheEnabled ] = useState(
		!! cacheSettings.enableCache
	);
	const [ savingPageCache, setSavingPageCache ] = useState( false );
	const [ cacheLife, setCacheLife ] = useState(
		Number( cacheSettings.cacheLife ?? 0 )
	);
	const [ ttlPost, setTtlPost ] = useState(
		cacheSettings.ttlOverrides?.post ?? ''
	);
	const [ ttlPage, setTtlPage ] = useState(
		cacheSettings.ttlOverrides?.page ?? ''
	);
	const [ ttlProduct, setTtlProduct ] = useState(
		cacheSettings.ttlOverrides?.product ?? ''
	);
	const [ loggedInCacheEnabled, setLoggedInCacheEnabled ] = useState(
		!! cacheSettings.enableLoggedInCache
	);
	const [ loggedInCacheRoles, setLoggedInCacheRoles ] = useState(
		Array.isArray( cacheSettings.loggedInCacheRoles )
			? cacheSettings.loggedInCacheRoles
			: []
	);
	const [ savingLoggedInCache, setSavingLoggedInCache ] = useState( false );

	// CDN cache purge (Cloudflare / Varnish).
	const [ cdnPurgeService, setCdnPurgeService ] = useState(
		cacheSettings.cdnPurgeService ?? 'none'
	);
	const [ cloudflareZoneId, setCloudflareZoneId ] = useState(
		cacheSettings.cloudflareZoneId ?? ''
	);
	const [ varnishPurgeUrls, setVarnishPurgeUrls ] = useState(
		Array.isArray( cacheSettings.varnishPurgeUrls )
			? cacheSettings.varnishPurgeUrls.join( '\n' )
			: ''
	);
	const [ savingCdnPurge, setSavingCdnPurge ] = useState( false );

	// Sync derived state when cacheSettings changes (e.g. parent App.js
	// re-fetches settings or apiCall mutates global wppoSettings).
	useEffect( () => {
		setPageCacheEnabled( !! cacheSettings.enableCache );
		setCacheLife( Number( cacheSettings.cacheLife ?? 0 ) );
		const ov =
			cacheSettings.ttlOverrides &&
			typeof cacheSettings.ttlOverrides === 'object'
				? cacheSettings.ttlOverrides
				: {};
		setTtlPost( ov?.post ?? '' );
		setTtlPage( ov?.page ?? '' );
		setTtlProduct( ov?.product ?? '' );
		setLoggedInCacheEnabled( !! cacheSettings.enableLoggedInCache );
		setLoggedInCacheRoles(
			Array.isArray( cacheSettings.loggedInCacheRoles )
				? cacheSettings.loggedInCacheRoles
				: []
		);
		setCdnPurgeService( cacheSettings.cdnPurgeService ?? 'none' );
		setCloudflareZoneId( cacheSettings.cloudflareZoneId ?? '' );
		setVarnishPurgeUrls(
			Array.isArray( cacheSettings.varnishPurgeUrls )
				? cacheSettings.varnishPurgeUrls.join( '\n' )
				: ''
		);
	}, [
		cacheSettings.enableCache,
		cacheSettings.cacheLife,
		cacheSettings.ttlOverrides,
		cacheSettings.enableLoggedInCache,
		cacheSettings.loggedInCacheRoles,
		cacheSettings.cdnPurgeService,
		cacheSettings.cloudflareZoneId,
		cacheSettings.varnishPurgeUrls,
	] );

	const [ bgProcessing, setBgProcessing ] = useState( false );
	const [ bgJobsQueued, setBgJobsQueued ] = useState( 0 );
	const [ imgSavings, setImgSavings ] = useState( null );
	const pollingRef = useRef( null );
	const pollRetryRef = useRef( 0 );
	const submittingRef = useRef( false );
	const [ confirmRemove, setConfirmRemove ] = useState( false );
	const { notice, notify, dismiss } = useNotice();

	const { imageInfo, loading, totalCacheSize, totalJs, totalCss, dbCounts } =
		state;
	const { completed = {}, pending = {}, failed = {} } = imageInfo;

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
			notify( {
				type: 'error',
				message: __(
					'Failed to load database counts.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			handleLoading( 'db_counts', false );
		}
	}, [ handleLoading, updateState, notify ] );

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
		const currentTimeout = pollingRef.current;
		try {
			const response = await apiCall( 'image_job_status', {}, 'GET' );
			pollRetryRef.current = 0;
			if ( response.success && response.data ) {
				const { queued_jobs: queuedJobs } = response.data;
				setBgJobsQueued( queuedJobs );
				setImgSavings( response.data.savings || null );

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
					notify( {
						type: 'success',
						message: __(
							'Image optimisation completed.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
					pollingRef.current = null;
					return;
				}
			}
		} catch ( error ) {
			console.error( 'Error polling job status:', error );
			pollRetryRef.current++;
			if ( pollRetryRef.current >= 5 ) {
				setBgProcessing( false );
				pollingRef.current = null;
				notify( {
					type: 'error',
					message: __(
						'Status check stopped after repeated failures.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
				return;
			}
			notify( {
				type: 'error',
				message: __(
					'Status check failed. Retrying…',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		}
		if ( pollingRef.current === currentTimeout ) {
			pollingRef.current = setTimeout( pollJobStatus, 5000 );
		}
	}, [ updateState, notify ] );

	useEffect( () => {
		return () => {
			if ( pollingRef.current ) {
				clearTimeout( pollingRef.current );
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
						notify( {
							type: 'success',
							message: __(
								'Cache cleared successfully.',
								'performance-optimisation'
							),
							durationMs: 5000,
						} );
						updateState( {
							totalCacheSize: '0 B',
							totalJs: 0,
							totalCss: 0,
						} );
					} else {
						notify( {
							type: 'error',
							message:
								data.message ||
								__(
									'Failed to clear cache.',
									'performance-optimisation'
								),
							durationMs: 5000,
						} );
					}
				} )
				.catch( () =>
					notify( {
						type: 'error',
						message: __(
							'Failed to clear cache.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} )
				)
				.finally( () => handleLoading( 'clear_cache', false ) );
		},
		[ handleLoading, updateState, notify ]
	);

	const optimizeImages = useCallback( () => {
		if (
			loading.optimize_images ||
			bgProcessing ||
			submittingRef.current
		) {
			return;
		}
		submittingRef.current = true;
		handleLoading( 'optimize_images', true );

		apiCall( 'optimise_image', {} )
			.then( ( response ) => {
				if ( response.data?.background ) {
					// Background (Action Scheduler) path.
					setBgProcessing( true );
					setBgJobsQueued( response.data.jobs_queued || 0 );
					notify( {
						type: 'success',
						message: __(
							'Image optimisation started in background.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
					if ( pollingRef.current ) {
						clearTimeout( pollingRef.current );
					}
					pollingRef.current = setTimeout( pollJobStatus, 5000 );
				} else {
					// Synchronous path (Action Scheduler unavailable).
					setBgJobsQueued( 0 );
					setBgProcessing( false );

					if ( response.success && response.data ) {
						updateState( {
							imageInfo: normalizeImageInfo( response.data ),
						} );
						notify( {
							type: 'success',
							message: __(
								'Images optimized successfully.',
								'performance-optimisation'
							),
							durationMs: 5000,
						} );
					}

					if ( pollingRef.current ) {
						clearTimeout( pollingRef.current );
						pollingRef.current = null;
					}
				}
			} )
			.catch( () =>
				notify( {
					type: 'error',
					message: __(
						'Image optimisation failed.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} )
			)
			.finally( () => {
				submittingRef.current = false;
				handleLoading( 'optimize_images', false );
			} );
	}, [
		handleLoading,
		pollJobStatus,
		updateState,
		notify,
		bgProcessing,
		loading.optimize_images,
	] );

	const removeImages = useCallback( () => {
		handleLoading( 'remove_images', true );
		apiCall( 'delete_optimised_image', {} )
			.then( ( data ) => {
				if ( data.success ) {
					setState( ( prev ) => ( {
						...prev,
						imageInfo: {
							completed: { webp: 0, avif: 0 },
							pending: { webp: 0, avif: 0 },
							failed: { webp: 0, avif: 0 },
						},
					} ) );
					notify( {
						type: 'success',
						message: __(
							'Optimized images removed.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
				} else {
					notify( {
						type: 'error',
						message:
							data.message ||
							__(
								'Failed to remove optimized images.',
								'performance-optimisation'
							),
						durationMs: 5000,
					} );
				}
			} )
			.catch( () =>
				notify( {
					type: 'error',
					message: __(
						'Failed to remove optimized images.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} )
			)
			.finally( () => handleLoading( 'remove_images', false ) );
	}, [ handleLoading, notify ] );

	const savePageCacheSettings = useCallback( () => {
		setSavingPageCache( true );
		// Re-read global wppoSettings at call-time to avoid stale closure
		// after prior save mutated it via apiCall's freeze.
		const currentSettings =
			( typeof wppoSettings !== 'undefined'
				? wppoSettings.settings?.cache_settings
				: null ) ??
			cacheSettings ??
			{};
		const overrides = {};
		if ( '' !== ttlPost && null !== ttlPost && undefined !== ttlPost ) {
			overrides.post = Number( ttlPost );
		}
		if ( '' !== ttlPage && null !== ttlPage && undefined !== ttlPage ) {
			overrides.page = Number( ttlPage );
		}
		if (
			'' !== ttlProduct &&
			null !== ttlProduct &&
			undefined !== ttlProduct
		) {
			overrides.product = Number( ttlProduct );
		}
		apiCall( 'update_settings', {
			tab: 'cache_settings',
			settings: {
				...currentSettings,
				enableCache: pageCacheEnabled,
				cacheLife,
				ttlOverrides: overrides,
			},
		} )
			.then( ( response ) => {
				if ( response.success && response.data ) {
					notify( {
						type: 'success',
						message: __(
							'Page cache settings saved.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
				}
			} )
			.catch( () =>
				notify( {
					type: 'error',
					message: __(
						'Failed to save page cache settings.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} )
			)
			.finally( () => setSavingPageCache( false ) );
	}, [
		pageCacheEnabled,
		cacheLife,
		ttlPost,
		ttlPage,
		ttlProduct,
		cacheSettings,
		notify,
	] );

	const saveLoggedInCacheSettings = useCallback( () => {
		setSavingLoggedInCache( true );
		// Re-read global wppoSettings at call-time to avoid stale closure.
		const currentSettings =
			( typeof wppoSettings !== 'undefined'
				? wppoSettings.settings?.cache_settings
				: null ) ??
			cacheSettings ??
			{};
		apiCall( 'update_settings', {
			tab: 'cache_settings',
			settings: {
				...currentSettings,
				enableLoggedInCache: loggedInCacheEnabled,
				loggedInCacheRoles,
			},
		} )
			.then( ( response ) => {
				if ( response.success && response.data ) {
					notify( {
						type: 'success',
						message: __(
							'Logged-in cache settings saved.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
				}
			} )
			.catch( () =>
				notify( {
					type: 'error',
					message: __(
						'Failed to save logged-in cache settings.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} )
			)
			.finally( () => setSavingLoggedInCache( false ) );
	}, [ loggedInCacheEnabled, loggedInCacheRoles, cacheSettings, notify ] );

	const saveCdnPurgeSettings = useCallback( () => {
		setSavingCdnPurge( true );
		// Re-read global wppoSettings at call-time to avoid stale closure.
		const currentSettings =
			( typeof wppoSettings !== 'undefined'
				? wppoSettings.settings?.cache_settings
				: null ) ??
			cacheSettings ??
			{};
		const urls = varnishPurgeUrls
			.split( '\n' )
			.map( ( url ) => url.trim() )
			.filter( Boolean );
		apiCall( 'update_settings', {
			tab: 'cache_settings',
			settings: {
				...currentSettings,
				cdnPurgeService,
				cloudflareZoneId,
				varnishPurgeUrls: urls,
			},
		} )
			.then( ( response ) => {
				if ( response.success && response.data ) {
					notify( {
						type: 'success',
						message: __(
							'CDN purge settings saved.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
				}
			} )
			.catch( () =>
				notify( {
					type: 'error',
					message: __(
						'Failed to save CDN purge settings.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} )
			)
			.finally( () => setSavingCdnPurge( false ) );
	}, [
		cdnPurgeService,
		cloudflareZoneId,
		varnishPurgeUrls,
		cacheSettings,
		notify,
	] );

	const handleLoggedInCacheToggle = useCallback( ( e ) => {
		setLoggedInCacheEnabled( e.target.checked );
	}, [] );

	const handleRoleCheckbox = useCallback( ( e ) => {
		const role = e.target.name;
		const checked = e.target.checked;
		setLoggedInCacheRoles( ( prev ) =>
			checked ? [ ...prev, role ] : prev.filter( ( r ) => r !== role )
		);
	}, [] );

	const totalWebP = ( completed.webp || 0 ) + ( pending.webp || 0 );
	const totalAvif = ( completed.avif || 0 ) + ( pending.avif || 0 );
	const totalOptimizedPercent =
		totalWebP + totalAvif > 0
			? ( ( ( completed.webp || 0 ) + ( completed.avif || 0 ) ) /
					( totalWebP + totalAvif ) ) *
			  100
			: null;

	const isCacheMissing =
		typeof totalCacheSize === 'string' &&
		/does not exist/i.test( totalCacheSize );
	const cacheSizeValue = ! isCacheMissing ? totalCacheSize ?? '—' : '—';
	const cacheSizeUnit = isCacheMissing
		? __( 'Cache missing', 'performance-optimisation' )
		: '';
	const optimizedFilesCount = ( totalJs || 0 ) + ( totalCss || 0 );

	let dbBadgeClass = 'wppo-status-badge--good';
	let dbBadgeLabel = __( 'Healthy', 'performance-optimisation' );
	if ( dbOverheadCount > 50 ) {
		dbBadgeClass = 'wppo-status-badge--poor';
		dbBadgeLabel = __( 'High', 'performance-optimisation' );
	} else if ( dbOverheadCount >= 20 ) {
		dbBadgeClass = 'wppo-status-badge--warning';
		dbBadgeLabel = __( 'Medium', 'performance-optimisation' );
	}

	const renderCacheStatus = () => {
		if ( isCacheMissing ) {
			return (
				<>
					{ cacheSizeUnit } •{ ' ' }
					<span className="wppo-status-badge wppo-status-badge--poor">
						{ __( 'Not cached', 'performance-optimisation' ) }
					</span>
				</>
			);
		}
		if ( cacheSizeUnit ) {
			return cacheSizeUnit;
		}
		return (
			<span className="wppo-text-muted wppo-text-small">
				{ __( 'Ready', 'performance-optimisation' ) }
			</span>
		);
	};

	// LiteSpeed banner data from global wppoSettings (injected by PHP).
	const litespeedInfo =
		typeof wppoSettings !== 'undefined' ? wppoSettings?.litespeed : null;
	const isLiteSpeed = !! litespeedInfo?.detected;
	const effectiveMode = litespeedInfo?.effective_mode || 'standalone';
	const lscacheActive = !! litespeedInfo?.lscache_active;
	const effectiveLabel = modeLabel( effectiveMode );
	const effectiveBadgeClass =
		effectiveMode === 'litespeed'
			? 'wppo-status-badge--warning'
			: 'wppo-status-badge--good';

	return (
		<div className="wppo-dashboard-view">
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
			{ isLiteSpeed && (
				<div
					className="wppo-notice wppo-notice--info wppo-litespeed-banner wppo-mb-16"
					role="alert"
					aria-live="polite"
				>
					<FontAwesomeIcon icon={ faServer } aria-hidden="true" />
					<span className="wppo-litespeed-banner__text">
						<strong>
							{ __(
								'LiteSpeed Detected',
								'performance-optimisation'
							) }
						</strong>{ ' ' }
						{ lscacheActive
							? __(
									'LiteSpeed Cache plugin is active.',
									'performance-optimisation'
							  )
							: __(
									'Server is LiteSpeed / OpenLiteSpeed.',
									'performance-optimisation'
							  ) }
					</span>
					<span className="wppo-litespeed-banner__badges">
						<span
							className={ `wppo-status-badge ${ effectiveBadgeClass }` }
						>
							{ __( 'Effective:', 'performance-optimisation' ) }{ ' ' }
							{ effectiveLabel }
						</span>
						<span
							className={ `wppo-status-badge ${
								lscacheActive
									? 'wppo-status-badge--poor'
									: 'wppo-status-badge--good'
							}` }
						>
							{ lscacheActive
								? 'LSCache Active'
								: 'LSCache Inactive' }
						</span>
					</span>
					{ lscacheActive && effectiveMode === 'litespeed' && (
						<span className="wppo-text-muted wppo-text-small">
							{ __(
								'WPPO optimisation is paused in this mode.',
								'performance-optimisation'
							) }
						</span>
					) }
				</div>
			) }
			<FeatureHeader
				title={
					<>
						<span className="wppo-health-dot" aria-hidden="true">
							●
						</span>
						{ __( 'System Health', 'performance-optimisation' ) }
					</>
				}
				description={ __(
					'Real-time performance overview and quick optimisation actions.',
					'performance-optimisation'
				) }
				status={ <></> }
				actions={
					<LoadingSubmitButton
						type="button"
						className="wppo-button wppo-button--primary"
						onClick={ onClearCache }
						isLoading={ loading.clear_cache }
						label={
							<>
								<FontAwesomeIcon
									icon={ faBroom }
									aria-hidden="true"
									className="wppo-mr-8"
								/>
								{ __(
									'Purge All Cache',
									'performance-optimisation'
								) }
							</>
						}
						loadingLabel={ __(
							'Purging…',
							'performance-optimisation'
						) }
					/>
				}
			/>

			<WelcomePanel />

			{ isCacheMissing && (
				<div className="wppo-banner wppo-banner--warning" role="alert">
					<span className="wppo-banner__icon" aria-hidden="true">
						<FontAwesomeIcon icon={ faExclamationTriangle } />
					</span>
					<span className="wppo-banner__text">
						{ __(
							'Cache directory not found.',
							'performance-optimisation'
						) }
					</span>
					<button
						type="button"
						className="wppo-button wppo-button--primary wppo-button--sm"
						onClick={ () => onNavigate( 'fileOptimization' ) }
					>
						{ __( 'Fix Now', 'performance-optimisation' ) }
					</button>
				</div>
			) }
			{ /* Quick-stat overview strip */ }
			<div className="wppo-stats-grid">
				<div className="wppo-stat-item wppo-stat-item--cache">
					<div className="wppo-stat-header">
						<span className="wppo-stat-label">
							{ __( 'Cache Size', 'performance-optimisation' ) }
						</span>
						<span className="wppo-stat-icon" aria-hidden="true">
							<FontAwesomeIcon icon={ faServer } />
						</span>
					</div>
					<span
						className={
							isCacheMissing
								? 'wppo-stat-value wppo-stat-value--muted'
								: 'wppo-stat-value'
						}
					>
						{ cacheSizeValue }
					</span>
					<span className="wppo-stat-unit">
						{ renderCacheStatus() }
					</span>
					<div className="wppo-stat-footer">
						<button
							type="button"
							className="wppo-button wppo-button--secondary wppo-button--sm wppo-stat-link"
							onClick={ () => onNavigate( 'fileOptimization' ) }
						>
							{ __( 'Manage →', 'performance-optimisation' ) }
						</button>
					</div>
				</div>
				<div className="wppo-stat-item wppo-stat-item--files">
					<div className="wppo-stat-header">
						<span className="wppo-stat-label">
							{ __(
								'Optimized Files',
								'performance-optimisation'
							) }
						</span>
						<span className="wppo-stat-icon" aria-hidden="true">
							<FontAwesomeIcon icon={ faFileCode } />
						</span>
					</div>
					<span className="wppo-stat-value">
						{ optimizedFilesCount }
					</span>
					<span className="wppo-stat-unit">
						{ __( 'files', 'performance-optimisation' ) }
					</span>
					<div className="wppo-stat-footer">
						<button
							type="button"
							className="wppo-button wppo-button--secondary wppo-button--sm wppo-stat-link"
							onClick={ () => onNavigate( 'fileOptimization' ) }
						>
							{ __( 'Configure →', 'performance-optimisation' ) }
						</button>
					</div>
				</div>
				<div className="wppo-stat-item wppo-stat-item--db">
					<div className="wppo-stat-header">
						<span className="wppo-stat-label">
							{ __( 'DB Overhead', 'performance-optimisation' ) }
						</span>
						<span className="wppo-stat-icon" aria-hidden="true">
							<FontAwesomeIcon icon={ faDatabase } />
						</span>
					</div>
					<span className="wppo-stat-value">{ dbOverheadCount }</span>
					<span className="wppo-stat-unit">
						{ __( 'items', 'performance-optimisation' ) }
						<span
							className={ `wppo-status-badge ${ dbBadgeClass }` }
						>
							{ dbBadgeLabel }
						</span>
					</span>
					<div className="wppo-stat-footer">
						<button
							type="button"
							className="wppo-button wppo-button--secondary wppo-button--sm wppo-stat-link"
							onClick={ () => onNavigate( 'databaseCleanup' ) }
						>
							{ __( 'Optimize →', 'performance-optimisation' ) }
						</button>
					</div>
				</div>
				<div className="wppo-stat-item wppo-stat-item--images">
					<div className="wppo-stat-header">
						<span className="wppo-stat-label">
							{ __(
								'Images Optimized',
								'performance-optimisation'
							) }
						</span>
						<span className="wppo-stat-icon" aria-hidden="true">
							<FontAwesomeIcon icon={ faImages } />
						</span>
					</div>
					<span
						className={
							totalOptimizedPercent === null
								? 'wppo-stat-value wppo-stat-value--muted'
								: 'wppo-stat-value'
						}
					>
						{ totalOptimizedPercent !== null
							? `${ totalOptimizedPercent.toFixed( 0 ) }%`
							: '—' }
					</span>
					<span className="wppo-stat-unit">
						{ totalOptimizedPercent !== null
							? __( 'optimized', 'performance-optimisation' )
							: __( 'No images', 'performance-optimisation' ) }
					</span>
					<div className="wppo-stat-footer">
						<button
							type="button"
							className="wppo-button wppo-button--secondary wppo-button--sm wppo-stat-link"
							onClick={ () => onNavigate( 'imageOptimization' ) }
						>
							{ __( 'View →', 'performance-optimisation' ) }
						</button>
					</div>
				</div>
			</div>

			{ /* Page cache master toggle */ }
			<FeatureCard
				title={ __( 'Page Cache', 'performance-optimisation' ) }
				icon={ <i className="fas fa-bolt"></i> }
			>
				<SwitchField
					label={ __(
						'Enable Page Cache',
						'performance-optimisation'
					) }
					description={ __(
						'Generate static HTML copies of your pages and serve them to visitors without running WordPress. Recommended for faster TTFB on non-logged-in traffic.',
						'performance-optimisation'
					) }
					name="enableCache"
					checked={ pageCacheEnabled }
					onChange={ ( e ) =>
						setPageCacheEnabled( e.target.checked )
					}
				/>
				<div className="wppo-field">
					<label className="wppo-field-label" htmlFor="wppoCacheLife">
						{ __( 'Cache Lifespan', 'performance-optimisation' ) }
					</label>
					<select
						className="wppo-select"
						id="wppoCacheLife"
						name="cacheLife"
						value={ cacheLife }
						onChange={ ( e ) =>
							setCacheLife( Number( e.target.value ) )
						}
					>
						<option value={ 0 }>
							{ __( 'Never expire', 'performance-optimisation' ) }
						</option>
						<option value={ 1 }>
							{ __( '1 hour', 'performance-optimisation' ) }
						</option>
						<option value={ 6 }>
							{ __( '6 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 12 }>
							{ __( '12 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 24 }>
							{ __( '24 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 48 }>
							{ __( '48 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 168 }>
							{ __( '1 week', 'performance-optimisation' ) }
						</option>
					</select>
					<p className="wppo-text-muted wppo-text-small">
						{ __(
							'File cache uses this lifespan. LiteSpeed server layer may vary per post type below.',
							'performance-optimisation'
						) }
					</p>
				</div>
				<div className="wppo-field">
					<label className="wppo-field-label" htmlFor="wppoTtlPost">
						{ __(
							'Posts TTL override',
							'performance-optimisation'
						) }
					</label>
					<select
						className="wppo-select"
						id="wppoTtlPost"
						name="ttlPost"
						value={ '' === ttlPost ? '' : String( ttlPost ) }
						onChange={ ( e ) =>
							setTtlPost(
								'' === e.target.value
									? ''
									: Number( e.target.value )
							)
						}
						aria-describedby="wppoTtlOverrides-desc"
					>
						<option value="">
							{ __(
								'Inherit global',
								'performance-optimisation'
							) }
						</option>
						<option value={ 0 }>
							{ __( 'Never expire', 'performance-optimisation' ) }
						</option>
						<option value={ 1 }>
							{ __( '1 hour', 'performance-optimisation' ) }
						</option>
						<option value={ 6 }>
							{ __( '6 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 12 }>
							{ __( '12 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 24 }>
							{ __( '24 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 48 }>
							{ __( '48 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 168 }>
							{ __( '1 week', 'performance-optimisation' ) }
						</option>
					</select>
				</div>
				<div className="wppo-field">
					<label className="wppo-field-label" htmlFor="wppoTtlPage">
						{ __(
							'Pages TTL override',
							'performance-optimisation'
						) }
					</label>
					<select
						className="wppo-select"
						id="wppoTtlPage"
						name="ttlPage"
						value={ '' === ttlPage ? '' : String( ttlPage ) }
						onChange={ ( e ) =>
							setTtlPage(
								'' === e.target.value
									? ''
									: Number( e.target.value )
							)
						}
						aria-describedby="wppoTtlOverrides-desc"
					>
						<option value="">
							{ __(
								'Inherit global',
								'performance-optimisation'
							) }
						</option>
						<option value={ 0 }>
							{ __( 'Never expire', 'performance-optimisation' ) }
						</option>
						<option value={ 1 }>
							{ __( '1 hour', 'performance-optimisation' ) }
						</option>
						<option value={ 6 }>
							{ __( '6 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 12 }>
							{ __( '12 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 24 }>
							{ __( '24 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 48 }>
							{ __( '48 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 168 }>
							{ __( '1 week', 'performance-optimisation' ) }
						</option>
					</select>
				</div>
				<div className="wppo-field">
					<label
						className="wppo-field-label"
						htmlFor="wppoTtlProduct"
					>
						{ __(
							'Products TTL override',
							'performance-optimisation'
						) }
					</label>
					<select
						className="wppo-select"
						id="wppoTtlProduct"
						name="ttlProduct"
						value={ '' === ttlProduct ? '' : String( ttlProduct ) }
						onChange={ ( e ) =>
							setTtlProduct(
								'' === e.target.value
									? ''
									: Number( e.target.value )
							)
						}
						aria-describedby="wppoTtlOverrides-desc"
					>
						<option value="">
							{ __(
								'Inherit global',
								'performance-optimisation'
							) }
						</option>
						<option value={ 0 }>
							{ __( 'Never expire', 'performance-optimisation' ) }
						</option>
						<option value={ 1 }>
							{ __( '1 hour', 'performance-optimisation' ) }
						</option>
						<option value={ 6 }>
							{ __( '6 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 12 }>
							{ __( '12 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 24 }>
							{ __( '24 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 48 }>
							{ __( '48 hours', 'performance-optimisation' ) }
						</option>
						<option value={ 168 }>
							{ __( '1 week', 'performance-optimisation' ) }
						</option>
					</select>
					<p
						id="wppoTtlOverrides-desc"
						className="wppo-text-muted wppo-text-small"
					>
						{ __(
							'LiteSpeed only — per post type overrides for X-LiteSpeed-Cache-Control. Non-singular pages use the global lifespan. Filter wppo_litespeed_ttl still works.',
							'performance-optimisation'
						) }
					</p>
				</div>
				<div className="wppo-feature-card__footer">
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						onClick={ savePageCacheSettings }
						isLoading={ savingPageCache }
						label={ __(
							'Save Page Cache Settings',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Saving…',
							'performance-optimisation'
						) }
					/>
				</div>
			</FeatureCard>

			{ /* CDN cache purge (Cloudflare / Varnish) */ }
			<FeatureCard
				title={ __( 'CDN Cache Purge', 'performance-optimisation' ) }
				icon={ <i className="fas fa-globe"></i> }
			>
				<div className="wppo-field">
					<label
						className="wppo-field-label"
						htmlFor="cdnPurgeService"
					>
						{ __(
							'CDN Purge Service',
							'performance-optimisation'
						) }
					</label>
					<select
						className="wppo-select"
						id="cdnPurgeService"
						name="cdnPurgeService"
						value={ cdnPurgeService }
						onChange={ ( e ) =>
							setCdnPurgeService( e.target.value )
						}
						aria-describedby="wppo-cdnPurgeService-desc"
					>
						<option value="none">
							{ __( 'None', 'performance-optimisation' ) }
						</option>
						<option value="cloudflare">
							{ __( 'Cloudflare', 'performance-optimisation' ) }
						</option>
						<option value="varnish">
							{ __( 'Varnish', 'performance-optimisation' ) }
						</option>
					</select>
					<p
						id="wppo-cdnPurgeService-desc"
						className="wppo-text-muted wppo-text-small"
					>
						{ __(
							'Purge the edge cache whenever the plugin cache is cleared.',
							'performance-optimisation'
						) }
					</p>
				</div>

				{ cdnPurgeService === 'cloudflare' && (
					<div className="wppo-field">
						<label
							className="wppo-field-label"
							htmlFor="cloudflareZoneId"
						>
							{ __(
								'Cloudflare Zone ID',
								'performance-optimisation'
							) }
						</label>
						<input
							className="wppo-input"
							id="cloudflareZoneId"
							name="cloudflareZoneId"
							type="text"
							value={ cloudflareZoneId }
							onChange={ ( e ) =>
								setCloudflareZoneId( e.target.value )
							}
							aria-describedby="wppo-cloudflareZoneId-desc"
						/>
						<p
							id="wppo-cloudflareZoneId-desc"
							className="wppo-text-muted wppo-text-small"
						>
							{ __(
								'Define WPPO_CLOUDFLARE_API_TOKEN in wp-config.php with an API token that has Zone > Cache Purge permission. The token is never stored in the database.',
								'performance-optimisation'
							) }
						</p>
					</div>
				) }

				{ cdnPurgeService === 'varnish' && (
					<div className="wppo-field">
						<label
							className="wppo-field-label"
							htmlFor="varnishPurgeUrls"
						>
							{ __(
								'Varnish Purge Endpoints',
								'performance-optimisation'
							) }
						</label>
						<textarea
							className="wppo-textarea"
							id="varnishPurgeUrls"
							name="varnishPurgeUrls"
							rows={ 3 }
							value={ varnishPurgeUrls }
							onChange={ ( e ) =>
								setVarnishPurgeUrls( e.target.value )
							}
							aria-describedby="wppo-varnishPurgeUrls-desc"
							placeholder={ __(
								'http://127.0.0.1:8081/purge',
								'performance-optimisation'
							) }
						/>
						<p
							id="wppo-varnishPurgeUrls-desc"
							className="wppo-text-muted wppo-text-small"
						>
							{ __(
								'One URL per line. Each receives a PURGE request on cache clear.',
								'performance-optimisation'
							) }
						</p>
					</div>
				) }

				<div className="wppo-feature-card__footer">
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						onClick={ saveCdnPurgeSettings }
						isLoading={ savingCdnPurge }
						label={ __(
							'Save CDN Purge',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Saving…',
							'performance-optimisation'
						) }
					/>
				</div>
			</FeatureCard>

			{ /* Logged-in user cache settings */ }
			<FeatureCard
				title={ __(
					'Cache for Logged-in Users',
					'performance-optimisation'
				) }
				icon={ <i className="fas fa-user-check"></i> }
			>
				<SwitchField
					label={ __( 'Enable', 'performance-optimisation' ) }
					description={ __(
						'Serve cached pages to logged-in users based on their role(s). The admin bar and user-specific content are preserved per role group.',
						'performance-optimisation'
					) }
					name="enableLoggedInCache"
					checked={ loggedInCacheEnabled }
					onChange={ handleLoggedInCacheToggle }
				/>
				{ loggedInCacheEnabled && (
					<div className="wppo-logged-in-cache-roles">
						<p className="wppo-text-muted">
							{ __(
								'Select which user roles should receive cached pages:',
								'performance-optimisation'
							) }
						</p>
						{ Object.entries( userRoles ).map(
							( [ slug, name ] ) => (
								<CheckboxOption
									key={ slug }
									label={ name }
									name={ slug }
									checked={ loggedInCacheRoles.includes(
										slug
									) }
									onChange={ handleRoleCheckbox }
								/>
							)
						) }
						<p className="wppo-text-muted wppo-mt-10">
							{ __(
								'When no roles are selected, caching applies to all logged-in users.',
								'performance-optimisation'
							) }
						</p>
					</div>
				) }
				<div className="wppo-feature-card__footer">
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						onClick={ saveLoggedInCacheSettings }
						isLoading={ savingLoggedInCache }
						label={ __(
							'Save Settings',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Saving…',
							'performance-optimisation'
						) }
					/>
				</div>
			</FeatureCard>

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

				{ /* Phase 2 — Web Vitals trends (v2.14.0) */ }
				<WebVitalsTrends url={ auditUrl } />

				{ /* Phase 3 — Real-user Web Vitals (v2.18.0) */ }
				<WebVitalsRum />

				{ /* Phase 3 — Autoloaded options audit (v2.18.0) */ }
				<AutoloadedOptions />

				<LlmsPanel />

				<AiPanel />

				<EdgeCachePanel />

				<SystemInfo />
			</div>

			{ /* Image optimization + activity log */ }
			<div className="wppo-stacked-cards wppo-mt-20">
				<ImageOptimizationCard
					completed={ completed }
					pending={ pending }
					failed={ failed }
					bgProcessing={ bgProcessing }
					bgJobsQueued={ bgJobsQueued }
					loading={ loading }
					savings={ imgSavings }
					pendingPathsCount={
						( pending.webp || 0 ) + ( pending.avif || 0 )
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
				title={ __(
					'Remove Optimized Images',
					'performance-optimisation'
				) }
				message={ __(
					'This will delete all optimized WebP and AVIF copies. Original images will not be affected.',
					'performance-optimisation'
				) }
				confirmLabel={ __( 'Delete', 'performance-optimisation' ) }
				variant="danger"
			/>
		</div>
	);
};

export default Dashboard;
