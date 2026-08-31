import {
	useState,
	useEffect,
	useCallback,
	useRef,
	useMemo,
} from '@wordpress/element';
import { apiCall } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import ConfirmDialog from './common/ConfirmDialog';
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
import HealthHeader from './HealthHeader';
import SectionHeader from './ui/SectionHeader';
import Card from './ui/Card';
import Button from './ui/Button';
import Badge from './ui/Badge';
import Alert from './ui/Alert';
import { __ } from '@wordpress/i18n';

// Diagnostics anchor for HealthHeader Run Scan → scroll target.
const DiagnosticsAnchor = () => (
	<div
		id="wppo-audit-details"
		className="tw-scroll-mt-6"
		aria-hidden="true"
	/>
);
import { modeLabel } from '../lib/litespeed';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faServer,
	faFileCode,
	faDatabase,
	faImages,
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
		apiCall( 'update_settings', {
			tab: 'cache_settings',
			settings: {
				...currentSettings,
				enableCache: pageCacheEnabled,
				cacheLife,
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
	}, [ pageCacheEnabled, cacheLife, cacheSettings, notify ] );

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

	let dbBadgeLabel = __( 'Healthy', 'performance-optimisation' );
	let dbBadgeTone = 'good';
	if ( dbOverheadCount > 50 ) {
		dbBadgeLabel = __( 'High', 'performance-optimisation' );
		dbBadgeTone = 'error';
	} else if ( dbOverheadCount >= 20 ) {
		dbBadgeLabel = __( 'Medium', 'performance-optimisation' );
		dbBadgeTone = 'warning';
	}

	let cacheSizeSubtext = null;
	if ( isCacheMissing ) {
		cacheSizeSubtext = (
			<>
				{ cacheSizeUnit } <span className="tw-mx-1">•</span>{ ' ' }
				<Badge tone="error">
					{ __( 'Not cached', 'performance-optimisation' ) }
				</Badge>
			</>
		);
	} else if ( cacheSizeUnit ) {
		cacheSizeSubtext = cacheSizeUnit;
	} else {
		cacheSizeSubtext = (
			<span className="tw-text-[13px] tw-text-[var(--wppo-text-muted)]">
				{ __( 'Ready', 'performance-optimisation' ) }
			</span>
		);
	}

	// LiteSpeed banner data from global wppoSettings (injected by PHP).
	const litespeedInfo =
		typeof wppoSettings !== 'undefined' ? wppoSettings?.litespeed : null;
	const isLiteSpeed = !! litespeedInfo?.detected;
	const effectiveMode = litespeedInfo?.effective_mode || 'standalone';
	const lscacheActive = !! litespeedInfo?.lscache_active;
	const effectiveLabel = modeLabel( effectiveMode );

	// Truthful health derived from real settings — no fake 92/88/64 scores (trust issue §6).
	// Use status only (Good/Needs attention/Needs review) rather than invented precision.
	const health = ( () => {
		const cacheOn = !! cacheSettings?.enableCache;
		const fo =
			typeof wppoSettings !== 'undefined'
				? wppoSettings?.settings?.file_optimisation
				: null;
		const minifyOn = !! ( fo?.minifyCSS || fo?.minifyJS );
		const deferOn = !! fo?.deferJS;
		const lazyOn =
			typeof wppoSettings !== 'undefined'
				? !! wppoSettings?.settings?.image_optimisation?.lazyLoadImages
				: false;
		// Speed: good if cache + minify, needs_attention if cache off, poor never invented.
		let speedStatus = 'needs_attention';
		if ( cacheOn && minifyOn ) {
			speedStatus = 'good';
		}
		// Stability: good by default (no CLS/INP without RUM); needs_review if defer off but could improve.
		const stabilityStatus = deferOn ? 'good' : 'needs_attention';
		// Efficiency: good if lazy + cache, needs_attention otherwise.
		const efficiencyStatus = cacheOn && lazyOn ? 'good' : 'needs_attention';
		return { speedStatus, stabilityStatus, efficiencyStatus };
	} )();

	return (
		<div className="tw-w-full tw-max-w-full tw-min-w-0 tw-space-y-5 sm:tw-space-y-6 tw-py-2 sm:tw-py-4 tw-overflow-x-hidden">
			{ /* Page Header — product identity, title, description */ }
			<SectionHeader
				eyebrow={ __(
					'Performance Optimisation',
					'performance-optimisation'
				) }
				title={ __( 'Overview', 'performance-optimisation' ) }
				description={ __(
					'Performance overview and quick actions for your site. Health shows what to improve next.',
					'performance-optimisation'
				) }
				actions={
					<Button
						variant="secondary"
						size="md"
						className="tw-w-full sm:tw-w-auto"
						onClick={ onClearCache }
						isLoading={ loading.clear_cache }
						loadingLabel={ __(
							'Purging…',
							'performance-optimisation'
						) }
					>
						<FontAwesomeIcon icon={ faBroom } aria-hidden="true" />
						{ __( 'Purge All Cache', 'performance-optimisation' ) }
					</Button>
				}
			/>
			<HealthHeader
				speedStatus={ health.speedStatus }
				stabilityStatus={ health.stabilityStatus }
				efficiencyStatus={ health.efficiencyStatus }
				speedScore={ null }
				stabilityScore={ null }
				efficiencyScore={ null }
				onApplyRecommended={ () => {
					// Delegate to SetupWizard via event
					window.dispatchEvent(
						new CustomEvent( 'wppo:open-wizard' )
					);
				} }
				onRunScan={ () => {
					const el = document.getElementById( 'wppo-audit-details' );
					if ( el ) {
						el.scrollIntoView( { behavior: 'smooth' } );
					} else {
						// Fallback: diagnostics live in Manage → Tools, navigate there.
						// HealthHeader triggers via onNavigate when available.
						window.dispatchEvent(
							new CustomEvent( 'wppo:navigate-manage' )
						);
					}
				} }
			/>
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
			{ isLiteSpeed && (
				<Alert tone="info" className="tw-mb-0">
					<div className="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center tw-gap-2 sm:tw-gap-3 tw-w-full tw-min-w-0">
						<div className="tw-flex tw-items-center tw-gap-2 tw-min-w-0 tw-flex-1">
							<FontAwesomeIcon
								icon={ faServer }
								aria-hidden="true"
								className="tw-flex-shrink-0"
							/>
							<span className="tw-font-semibold tw-text-[13.5px] tw-leading-5 tw-min-w-0">
								{ __(
									'LiteSpeed Detected',
									'performance-optimisation'
								) }
								<span className="tw-font-normal tw-text-[var(--wppo-text-muted)] tw-ml-2">
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
							</span>
						</div>
						<div className="tw-flex tw-flex-wrap tw-items-center tw-gap-2 tw-shrink-0">
							<Badge
								tone={
									effectiveMode === 'litespeed'
										? 'warning'
										: 'good'
								}
							>
								{ __(
									'Effective:',
									'performance-optimisation'
								) }{ ' ' }
								{ effectiveLabel }
							</Badge>
							<Badge tone={ lscacheActive ? 'error' : 'good' }>
								{ lscacheActive
									? 'LSCache Active'
									: 'LSCache Inactive' }
							</Badge>
						</div>
					</div>
					{ lscacheActive && effectiveMode === 'litespeed' && (
						<p className="tw-text-[12.5px] tw-text-[var(--wppo-text-muted)] tw-mt-2 tw-leading-5">
							{ __(
								'WPPO optimisation is paused in this mode.',
								'performance-optimisation'
							) }
						</p>
					) }
				</Alert>
			) }
			<SectionHeader
				title={ __( 'System Health', 'performance-optimisation' ) }
				description={ __(
					'At a glance — what is healthy, what needs attention, and what you can do next.',
					'performance-optimisation'
				) }
			/>

			<WelcomePanel />

			{ isCacheMissing && (
				<Alert
					tone="warning"
					title={ __(
						'Cache directory not found.',
						'performance-optimisation'
					) }
				>
					<div className="tw-flex tw-flex-wrap tw-items-center tw-gap-3 tw-mt-2">
						<span className="tw-text-[13.5px] tw-leading-5">
							{ __(
								'The cache folder is missing. Re-save settings or check file permissions.',
								'performance-optimisation'
							) }
						</span>
						<Button
							variant="secondary"
							size="sm"
							onClick={ () => onNavigate( 'fileOptimization' ) }
						>
							{ __( 'Fix Now', 'performance-optimisation' ) }
						</Button>
					</div>
				</Alert>
			) }
			{ /* Metrics — consistent cards, responsive grid */ }
			<div className="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 lg:tw-grid-cols-4 tw-gap-4">
				{ /* Cache Size */ }
				<Card
					className="tw-flex tw-flex-col tw-p-5 tw-min-h-[168px]"
					hover
				>
					<div className="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
						<span className="tw-text-[11px] tw-font-bold tw-tracking-[0.08em] tw-uppercase tw-text-[var(--wppo-text-muted)]">
							{ __( 'Cache Size', 'performance-optimisation' ) }
						</span>
						<span
							className="tw-w-8 tw-h-8 tw-rounded-full tw-bg-[var(--wppo-primary-soft)] tw-flex tw-items-center tw-justify-center tw-text-[var(--wppo-primary)] tw-flex-shrink-0"
							aria-hidden="true"
						>
							<FontAwesomeIcon
								icon={ faServer }
								className="tw-text-[14px]"
							/>
						</span>
					</div>
					<span
						className={
							isCacheMissing
								? 'tw-text-[22px] tw-font-extrabold tw-tracking-tight tw-text-[var(--wppo-text-light)] tw-leading-none'
								: 'tw-text-[26px] tw-font-extrabold tw-tracking-tight tw-text-[var(--wppo-text-main)] tw-leading-none'
						}
					>
						{ cacheSizeValue }
					</span>
					<span className="tw-text-[13px] tw-text-[var(--wppo-text-muted)] tw-mt-1 tw-flex tw-flex-wrap tw-items-center tw-gap-1.5">
						{ cacheSizeSubtext }
					</span>
					<div className="tw-mt-auto tw-pt-4">
						<Button
							variant="ghost"
							size="sm"
							onClick={ () => onNavigate( 'speed' ) }
							className="tw-w-full sm:tw-w-auto"
						>
							{ __( 'Manage →', 'performance-optimisation' ) }
						</Button>
					</div>
				</Card>
				{ /* Optimized Files */ }
				<Card
					className="tw-flex tw-flex-col tw-p-5 tw-min-h-[168px]"
					hover
				>
					<div className="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
						<span className="tw-text-[11px] tw-font-bold tw-tracking-[0.08em] tw-uppercase tw-text-[var(--wppo-text-muted)]">
							{ __(
								'Optimized Files',
								'performance-optimisation'
							) }
						</span>
						<span
							className="tw-w-8 tw-h-8 tw-rounded-full tw-bg-[#f5f3ff] tw-flex tw-items-center tw-justify-center tw-text-[#8b5cf6] tw-flex-shrink-0"
							aria-hidden="true"
						>
							<FontAwesomeIcon
								icon={ faFileCode }
								className="tw-text-[14px]"
							/>
						</span>
					</div>
					<span className="tw-text-[26px] tw-font-extrabold tw-tracking-tight tw-text-[var(--wppo-text-main)] tw-leading-none">
						{ optimizedFilesCount }
					</span>
					<span className="tw-text-[13px] tw-text-[var(--wppo-text-muted)] tw-mt-1">
						{ __( 'files', 'performance-optimisation' ) }
					</span>
					<div className="tw-mt-auto tw-pt-4">
						<Button
							variant="ghost"
							size="sm"
							onClick={ () => onNavigate( 'speed' ) }
							className="tw-w-full sm:tw-w-auto"
						>
							{ __( 'Configure →', 'performance-optimisation' ) }
						</Button>
					</div>
				</Card>
				{ /* DB Overhead */ }
				<Card
					className="tw-flex tw-flex-col tw-p-5 tw-min-h-[168px]"
					hover
				>
					<div className="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
						<span className="tw-text-[11px] tw-font-bold tw-tracking-[0.08em] tw-uppercase tw-text-[var(--wppo-text-muted)]">
							{ __( 'DB Overhead', 'performance-optimisation' ) }
						</span>
						<span
							className="tw-w-8 tw-h-8 tw-rounded-full tw-bg-[#fffbeb] tw-flex tw-items-center tw-justify-center tw-text-[#d97706] tw-flex-shrink-0"
							aria-hidden="true"
						>
							<FontAwesomeIcon
								icon={ faDatabase }
								className="tw-text-[14px]"
							/>
						</span>
					</div>
					<span className="tw-text-[26px] tw-font-extrabold tw-tracking-tight tw-text-[var(--wppo-text-main)] tw-leading-none">
						{ dbOverheadCount }
					</span>
					<span className="tw-text-[13px] tw-text-[var(--wppo-text-muted)] tw-mt-1 tw-flex tw-flex-wrap tw-items-center tw-gap-1.5">
						{ __( 'items', 'performance-optimisation' ) }
						<Badge tone={ dbBadgeTone }>{ dbBadgeLabel }</Badge>
					</span>
					<div className="tw-mt-auto tw-pt-4">
						<Button
							variant="ghost"
							size="sm"
							onClick={ () => onNavigate( 'data' ) }
							className="tw-w-full sm:tw-w-auto"
						>
							{ __( 'Optimize →', 'performance-optimisation' ) }
						</Button>
					</div>
				</Card>
				{ /* Images Optimized */ }
				<Card
					className="tw-flex tw-flex-col tw-p-5 tw-min-h-[168px]"
					hover
				>
					<div className="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
						<span className="tw-text-[11px] tw-font-bold tw-tracking-[0.08em] tw-uppercase tw-text-[var(--wppo-text-muted)]">
							{ __(
								'Images Optimized',
								'performance-optimisation'
							) }
						</span>
						<span
							className="tw-w-8 tw-h-8 tw-rounded-full tw-bg-[#ecfdf5] tw-flex tw-items-center tw-justify-center tw-text-[#059669] tw-flex-shrink-0"
							aria-hidden="true"
						>
							<FontAwesomeIcon
								icon={ faImages }
								className="tw-text-[14px]"
							/>
						</span>
					</div>
					<span
						className={
							totalOptimizedPercent === null
								? 'tw-text-[22px] tw-font-extrabold tw-tracking-tight tw-text-[var(--wppo-text-light)] tw-leading-none'
								: 'tw-text-[26px] tw-font-extrabold tw-tracking-tight tw-text-[var(--wppo-text-main)] tw-leading-none'
						}
					>
						{ totalOptimizedPercent !== null
							? `${ totalOptimizedPercent.toFixed( 0 ) }%`
							: '—' }
					</span>
					<span className="tw-text-[13px] tw-text-[var(--wppo-text-muted)] tw-mt-1">
						{ totalOptimizedPercent !== null
							? __( 'optimized', 'performance-optimisation' )
							: __( 'No images', 'performance-optimisation' ) }
					</span>
					<div className="tw-mt-auto tw-pt-4">
						<Button
							variant="ghost"
							size="sm"
							onClick={ () => onNavigate( 'media' ) }
							className="tw-w-full sm:tw-w-auto"
						>
							{ __( 'View →', 'performance-optimisation' ) }
						</Button>
					</div>
				</Card>
			</div>

			{ /* Page cache — Tailwind Card (Overview migrated) */ }
			<Card>
				<div className="tw-flex tw-items-center tw-gap-2.5 tw-mb-4">
					<span
						className="tw-w-8 tw-h-8 tw-rounded-full tw-bg-[var(--wppo-primary-soft)] tw-flex tw-items-center tw-justify-center tw-text-[var(--wppo-primary)]"
						aria-hidden="true"
					>
						<FontAwesomeIcon
							icon={ faBroom }
							className="tw-text-[14px]"
						/>
					</span>
					<h3 className="tw-text-[15px] tw-font-bold tw-text-[var(--wppo-text-main)] tw-tracking-tight">
						{ __( 'Page Cache', 'performance-optimisation' ) }
					</h3>
					<Badge tone="good">
						{ __( 'Recommended', 'performance-optimisation' ) }
					</Badge>
				</div>
				<p className="tw-text-[13.5px] tw-leading-5 tw-text-[var(--wppo-text-muted)] tw-mb-4">
					{ __(
						'Generate static HTML copies of your pages and serve them to visitors without running WordPress. Recommended for faster TTFB on non-logged-in traffic.',
						'performance-optimisation'
					) }
				</p>
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
				<div className="tw-flex tw-flex-col tw-gap-1.5">
					<label
						className="tw-text-[11px] tw-font-bold tw-tracking-[0.06em] tw-uppercase tw-text-[var(--wppo-text-muted)] tw-mb-1"
						htmlFor="wppoCacheLife"
					>
						{ __( 'Cache Lifespan', 'performance-optimisation' ) }
					</label>
					<select
						className="tw-w-full tw-bg-white tw-border tw-border-[var(--wppo-border)] tw-rounded-[8px] tw-px-3 tw-py-2.5 sm:tw-py-2 tw-text-[13.5px] tw-text-[var(--wppo-text-main)] tw-leading-5 focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-[var(--wppo-primary)] focus:tw-border-[var(--wppo-primary)] tw-transition"
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
				</div>
				<div className="tw-flex tw-justify-end tw-pt-4 tw-mt-4 tw-border-t tw-border-[var(--wppo-border)]">
					<Button
						variant="primary"
						onClick={ savePageCacheSettings }
						isLoading={ savingPageCache }
						loadingLabel={ __(
							'Saving…',
							'performance-optimisation'
						) }
					>
						{ __(
							'Save Page Cache Settings',
							'performance-optimisation'
						) }
					</Button>
				</div>
			</Card>

			{ /* CDN cache purge — Tailwind Card (Overview migrated) */ }
			<Card>
				<div className="tw-flex tw-items-center tw-gap-2.5 tw-mb-4">
					<span
						className="tw-w-8 tw-h-8 tw-rounded-full tw-bg-[var(--wppo-info-bg)] tw-flex tw-items-center tw-justify-center tw-text-[var(--wppo-info)]"
						aria-hidden="true"
					>
						<FontAwesomeIcon
							icon={ faServer }
							className="tw-text-[14px]"
						/>
					</span>
					<h3 className="tw-text-[15px] tw-font-bold tw-text-[var(--wppo-text-main)] tw-tracking-tight">
						{ __( 'CDN Cache Purge', 'performance-optimisation' ) }
					</h3>
				</div>
				<div className="tw-flex tw-flex-col tw-gap-1.5">
					<label
						className="tw-text-[11px] tw-font-bold tw-tracking-[0.06em] tw-uppercase tw-text-[var(--wppo-text-muted)] tw-mb-1"
						htmlFor="cdnPurgeService"
					>
						{ __(
							'CDN Purge Service',
							'performance-optimisation'
						) }
					</label>
					<select
						className="tw-w-full tw-bg-white tw-border tw-border-[var(--wppo-border)] tw-rounded-[8px] tw-px-3 tw-py-2.5 sm:tw-py-2 tw-text-[13.5px] tw-text-[var(--wppo-text-main)] tw-leading-5 focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-[var(--wppo-primary)] focus:tw-border-[var(--wppo-primary)] tw-transition"
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
						className="tw-text-[12.5px] tw-text-[var(--wppo-text-muted)] tw-leading-5"
					>
						{ __(
							'Purge the edge cache whenever the plugin cache is cleared.',
							'performance-optimisation'
						) }
					</p>
				</div>

				{ cdnPurgeService === 'cloudflare' && (
					<div className="tw-flex tw-flex-col tw-gap-1.5">
						<label
							className="tw-text-[11px] tw-font-bold tw-tracking-[0.06em] tw-uppercase tw-text-[var(--wppo-text-muted)] tw-mb-1"
							htmlFor="cloudflareZoneId"
						>
							{ __(
								'Cloudflare Zone ID',
								'performance-optimisation'
							) }
						</label>
						<input
							className="tw-w-full tw-bg-white tw-border tw-border-[var(--wppo-border)] tw-rounded-[8px] tw-px-3 tw-py-2.5 sm:tw-py-2 tw-text-[13.5px] tw-text-[var(--wppo-text-main)] tw-leading-5 focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-[var(--wppo-primary)] focus:tw-border-[var(--wppo-primary)] tw-transition"
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
							className="tw-text-[12.5px] tw-text-[var(--wppo-text-muted)] tw-leading-5"
						>
							{ __(
								'Define WPPO_CLOUDFLARE_API_TOKEN in wp-config.php with an API token that has Zone > Cache Purge permission. The token is never stored in the database.',
								'performance-optimisation'
							) }
						</p>
					</div>
				) }

				{ cdnPurgeService === 'varnish' && (
					<div className="tw-flex tw-flex-col tw-gap-1.5">
						<label
							className="tw-text-[11px] tw-font-bold tw-tracking-[0.06em] tw-uppercase tw-text-[var(--wppo-text-muted)] tw-mb-1"
							htmlFor="varnishPurgeUrls"
						>
							{ __(
								'Varnish Purge Endpoints',
								'performance-optimisation'
							) }
						</label>
						<textarea
							className="tw-w-full tw-bg-white tw-border tw-border-[var(--wppo-border)] tw-rounded-[8px] tw-px-3 tw-py-2.5 sm:tw-py-2 tw-text-[13.5px] tw-text-[var(--wppo-text-main)] tw-leading-5 focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-[var(--wppo-primary)] focus:tw-border-[var(--wppo-primary)] tw-transition"
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
							className="tw-text-[12.5px] tw-text-[var(--wppo-text-muted)] tw-leading-5"
						>
							{ __(
								'One URL per line. Each receives a PURGE request on cache clear.',
								'performance-optimisation'
							) }
						</p>
					</div>
				) }

				<div className="tw-flex tw-justify-end tw-pt-4 tw-mt-4 tw-border-t tw-border-[var(--wppo-border)]">
					<Button
						variant="primary"
						onClick={ saveCdnPurgeSettings }
						isLoading={ savingCdnPurge }
						loadingLabel={ __(
							'Saving…',
							'performance-optimisation'
						) }
					>
						{ __( 'Save CDN Purge', 'performance-optimisation' ) }
					</Button>
				</div>
			</Card>

			{ /* Logged-in cache — Tailwind Card (Overview migrated) */ }
			<Card>
				<div className="tw-flex tw-items-center tw-gap-2.5 tw-mb-4">
					<span
						className="tw-w-8 tw-h-8 tw-rounded-full tw-bg-[#f0fdf4] tw-flex tw-items-center tw-justify-center tw-text-[#059669]"
						aria-hidden="true"
					>
						<FontAwesomeIcon
							icon={ faServer }
							className="tw-text-[14px]"
						/>
					</span>
					<h3 className="tw-text-[15px] tw-font-bold tw-text-[var(--wppo-text-main)] tw-tracking-tight">
						{ __(
							'Cache for Logged-in Users',
							'performance-optimisation'
						) }
					</h3>
				</div>
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
					<div className="tw-space-y-3 tw-mt-3">
						<p className="tw-text-[13.5px] tw-text-[var(--wppo-text-muted)]">
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
						<p className="tw-text-[13px] tw-text-[var(--wppo-text-muted)] tw-mt-2.5">
							{ __(
								'When no roles are selected, caching applies to all logged-in users.',
								'performance-optimisation'
							) }
						</p>
					</div>
				) }
				<div className="tw-flex tw-justify-end tw-pt-4 tw-mt-4 tw-border-t tw-border-[var(--wppo-border)]">
					<Button
						variant="primary"
						onClick={ saveLoggedInCacheSettings }
						isLoading={ savingLoggedInCache }
						loadingLabel={ __(
							'Saving…',
							'performance-optimisation'
						) }
					>
						{ __( 'Save Settings', 'performance-optimisation' ) }
					</Button>
				</div>
			</Card>

			<DiagnosticsAnchor />
			{ /* Phase 1 — Performance Audit & System Info (v1.5.0) */ }
			<div className="tw-space-y-5">
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
			<div className="tw-space-y-5 tw-mt-6">
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
