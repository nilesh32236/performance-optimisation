import {
	useState,
	useEffect,
	useCallback,
	useRef,
	useMemo,
} from '@wordpress/element';
import {
	apiCall,
	fetchWooCacheSelfTest,
	getErrorLogMessage,
	getWppoSettings,
} from '../lib/apiRequest';
import {
	WOO_SELF_TEST_TIMEOUT_MS,
	formatWooSummary,
	getWooCheckState,
	getWooRuleRemediation,
	getWooSelfTestNotice,
	shouldShowWooFixCta,
} from '../lib/wooSelfTest';
import { getDbCounts } from '../lib/dbCounts';
import { formatBytes } from '../lib/util';
import useNotice from '../lib/useNotice';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import ConfirmDialog from './common/ConfirmDialog';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import SwitchField from './common/SwitchField';
import NoticeBanner from './common/NoticeBanner';
import PerformanceAudit from './PerformanceAudit';
import PageSpeedPanel from './PageSpeedPanel';
import WebVitalsTrends from './WebVitalsTrends';
import WebVitalsRum from './WebVitalsRum';
import SuggestionsPanel from './SuggestionsPanel';
import GuidedNextStep from './GuidedNextStep';
import OptimizationPresets from './OptimizationPresets';
import SystemInfo from './SystemInfo';
import AutoloadedOptions from './AutoloadedOptions';
import LlmsPanel from './LlmsPanel';
import AiPanel from './AiPanel';
import EdgeCachePanel from './EdgeCachePanel';
import ImageOptimizationCard from './ImageOptimizationCard';
import RecentActivityCard from './RecentActivityCard';
import LoggedInCacheCard from './dashboard/LoggedInCacheCard';
import WelcomePanel, { scrollToWooSafeMode } from './WelcomePanel';
import { __, sprintf, _n } from '@wordpress/i18n';
import { modeLabel } from '../lib/litespeed';
import { isSafeHttpUrl } from '../lib/urls';
import useUpgradePurgeStatus from '../lib/useUpgradePurgeStatus';
import UpgradePurgeBanner from './common/UpgradePurgeBanner';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faServer,
	faFileCode,
	faDatabase,
	faImages,
	faExclamationTriangle,
	faBroom,
	faBolt,
	faGlobe,
} from '@fortawesome/free-solid-svg-icons';

/**
 * Polling interval for image_job_status ticks.
 */
const POLL_INTERVAL_MS = 5000;

/**
 * Maximum number of image_job_status poll attempts before giving up
 * (~5 minutes at 5s), matching PageSpeedPanel's cap.
 */
const MAX_POLL_ATTEMPTS = 60;

/**
 * Maximum delay between image_job_status poll ticks.
 *
 * Polling backs off (5s for the first 10 attempts, then +5s per 10
 * attempts) so deep queues do not hammer admin-ajax at the same rate as
 * near-complete ones.
 *
 * @since 2.3.0
 */
const MAX_POLL_DELAY_MS = 15000;

/**
 * Delay before the next poll tick, backing off with the attempt count.
 *
 * @since 2.3.0
 * @param {number} attempts 1-based poll attempt count.
 * @return {number} Milliseconds to wait before the next tick.
 */
const getPollDelay = ( attempts ) =>
	Math.min(
		POLL_INTERVAL_MS * Math.max( 1, Math.ceil( attempts / 10 ) ),
		MAX_POLL_DELAY_MS
	);

/**
 * Coerce a TTL override select value to a finite number, or undefined when
 * the override should be omitted. Guards against tampered non-numeric option
 * values: Number('abc') is NaN and JSON.stringify(NaN) becomes null, which
 * the server could misread as an explicit clear / never-expire.
 *
 * @param {*} value Raw select value ('' | number | string | null | undefined).
 * @return {number|undefined} Finite number, or undefined to omit.
 */
const toTtlOverride = ( value ) => {
	if ( '' === value || null === value || undefined === value ) {
		return undefined;
	}
	const n = Number( value );
	return Number.isFinite( n ) ? n : undefined;
};

/**
 * Allowed CDN purge services (client-side allowlist; server allowlists too).
 */
const CDN_PURGE_SERVICES = [ 'none', 'cloudflare', 'varnish' ];

/**
 * Copy for a Woo self-test check row with explicit tri-state handling.
 *
 * A malformed entry with pass missing must never render FAIL copy with no
 * evidence — it renders an inconclusive label instead.
 *
 * @since 2.3.0
 * @param {*}      pass     Raw pass value from a check entry.
 * @param {string} passCopy Pass label.
 * @param {string} failCopy Fail label.
 * @return {string} Row label.
 */
const getWooCheckCopy = ( pass, passCopy, failCopy ) => {
	const state = getWooCheckState( pass );
	if ( state === 'pass' ) {
		return passCopy;
	}
	if ( state === 'fail' ) {
		return failCopy;
	}
	return __( 'Inconclusive (re-run)', 'performance-optimisation' );
};

/**
 * Parse the Varnish purge-endpoint textarea into validated http(s) URLs.
 * Each URL later receives a server-side PURGE request, so shape-check here
 * as defense-in-depth (server allowlisting remains authoritative).
 *
 * @param {string} raw Raw textarea value (one URL per line).
 * @return {string[]} Validated http(s) URLs.
 */
const parseVarnishPurgeUrls = ( raw ) => {
	if ( typeof raw !== 'string' || '' === raw.trim() ) {
		return [];
	}
	return raw
		.split( /\n|,/ )
		.map( ( url ) => url.trim() )
		.filter( ( url ) => url && isSafeHttpUrl( url ) );
};

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

/**
 * One Woo self-test check list (audit #1401).
 *
 * The five lists (routes/fragments/editor/preload/cart) shared one shape
 * with different data + remediation kinds; a tri-state fix in 4/5 copies
 * used to leave one list mislabeling FAIL as pass.
 *
 * @since 2.3.0
 * @param {Object} props             Component props.
 * @param {Array}  props.items       Check entries ({ path|key, pass }).
 * @param {string} props.kind        Remediation kind for getWooRuleRemediation.
 * @param {string} props.idPrefix    Key prefix.
 * @param {string} props.pathKey     Entry field holding the label ('path'|'key').
 * @param {string} [props.listLabel] Accessible label for the list.
 * @return {Element} Check list.
 */
const WooCheckList = ( { items, kind, idPrefix, pathKey, listLabel } ) => (
	<ul
		className="wppo-woo-self-test"
		{ ...( listLabel ? { 'aria-label': listLabel } : {} ) }
	>
		{ items.map( ( check, index ) => (
			<li key={ `${ check?.[ pathKey ] ?? idPrefix }-${ index }` }>
				<span>{ check?.[ pathKey ] }</span>
				{ ' — ' }
				<span>
					{ getWooCheckCopy(
						check?.pass,
						__( 'Bypassed (pass)', 'performance-optimisation' ),
						__( 'Cacheable (fail)', 'performance-optimisation' )
					) }
				</span>
				{ check?.pass === false && (
					<>
						{ ' — ' }
						<span className="wppo-text-muted wppo-text-small">
							{ getWooRuleRemediation( kind, check?.pass ) }
						</span>
					</>
				) }
			</li>
		) ) }
	</ul>
);

/**
 * Shared TTL duration options (audit #1401).
 *
 * The Posts/Pages/Products selects shared one 7-option list copy-pasted
 * 3×; a duration added to 2 of 3 silently diverged per-type expiry.
 *
 * @since 2.3.0
 * @return {Element} Option elements.
 */
const TTL_DURATIONS = [
	{ value: '', label: __( 'Inherit global', 'performance-optimisation' ) },
	{ value: 0, label: __( 'Never expire', 'performance-optimisation' ) },
	{ value: 1, label: __( '1 hour', 'performance-optimisation' ) },
	{ value: 6, label: __( '6 hours', 'performance-optimisation' ) },
	{ value: 12, label: __( '12 hours', 'performance-optimisation' ) },
	{ value: 24, label: __( '24 hours', 'performance-optimisation' ) },
	{ value: 48, label: __( '48 hours', 'performance-optimisation' ) },
	{ value: 168, label: __( '1 week', 'performance-optimisation' ) },
];
const TtlDurationOptions = () => (
	<>
		{ TTL_DURATIONS.map( ( opt ) => (
			<option key={ String( opt.value ) } value={ opt.value }>
				{ opt.label }
			</option>
		) ) }
	</>
);

const Dashboard = ( {
	activities,
	activitiesError = false,
	cacheSettings: propCacheSettings,
	userRoles: propUserRoles,
	onNavigate,
} ) => {
	// Phase 2 — suggestions state (populated by telemetry scan + PageSpeed scan).
	const [ telemetrySuggestions, setTelemetrySuggestions ] = useState( [] );
	const [ pagespeedSuggestions, setPagespeedSuggestions ] = useState( [] );
	const [ auditUrl, setAuditUrl ] = useState(
		getWppoSettings( 'performance_audit.homeUrl', '' )
	);
	// Merge telemetry and PageSpeed suggestions, deduplicating by metric key.
	// Malformed entries (null, non-objects, missing metric) pass through
	// without participating in dedup so one bad row can never throw or
	// collapse distinct rows onto a shared `undefined` key.
	const allSuggestions = useMemo( () => {
		const seen = new Set();
		const merged = [];
		for ( const s of [
			...pagespeedSuggestions,
			...telemetrySuggestions,
		] ) {
			if ( ! s || typeof s !== 'object' || s.metric === undefined ) {
				merged.push( s );
				continue;
			}
			if ( ! seen.has( s.metric ) ) {
				seen.add( s.metric );
				merged.push( s );
			}
		}
		return merged;
	}, [ telemetrySuggestions, pagespeedSuggestions ] );

	// Clear stale suggestions when the audited URL changes, synchronously with
	// the URL update (not as an effect on auditUrl): PerformanceAudit calls
	// onUrlChange mid-scan and onSuggestionsReady after the suggestions fetch
	// resolves, so an effect would wipe just-received results for the new URL.
	const handleAuditUrlChange = useCallback( ( url ) => {
		setTelemetrySuggestions( [] );
		setPagespeedSuggestions( [] );
		setAuditUrl( url );
	}, [] );

	// Initialize state
	const [ state, setState ] = useState( {
		// Audit #1354: localized zero instead of hardcoded '0 B'.
		totalCacheSize: getWppoSettings( 'cache_size', formatBytes( 0 ) ),
		cachedPages: getWppoSettings( 'cache_count', 0 ),
		totalJs: getWppoSettings( 'total_js_css.js', 0 ),
		totalCss: getWppoSettings( 'total_js_css.css', 0 ),
		imageInfo: normalizeImageInfo( getWppoSettings( 'image_info', {} ) ),
		dbCounts: {},
		loading: {
			clear_cache: false,
			optimize_images: false,
			remove_images: false,
			db_counts: true,
		},
	} );

	// Logged-in user cache settings — prefer props from App.js, fallback to global for direct mounts/tests.
	// The live global is read via getWppoSettings() so every mount sees the
	// same snapshot apiCall() froze on the last save; globalCacheSettings is a
	// memo dep so saves from sibling panels re-sync instead of going stale.
	const globalCacheSettings = getWppoSettings(
		'settings.cache_settings',
		{}
	);
	// Memoized so the save*Settings useCallbacks below keep stable deps.
	const cacheSettings = useMemo(
		() => propCacheSettings ?? globalCacheSettings ?? {},
		[ propCacheSettings, globalCacheSettings ]
	);
	const userRoles = propUserRoles ?? getWppoSettings( 'userRoles', {} );
	const [ pageCacheEnabled, setPageCacheEnabled ] = useState(
		!! cacheSettings.enableCache
	);
	const [ savingPageCache, setSavingPageCache ] = useState( false );
	const [ cacheLife, setCacheLife ] = useState( () => {
		const n = Number( cacheSettings.cacheLife ?? 0 );
		return Number.isFinite( n ) ? n : 0;
	} );
	const [ ttlPost, setTtlPost ] = useState(
		cacheSettings.ttlOverrides?.post ?? ''
	);
	const [ ttlPage, setTtlPage ] = useState(
		cacheSettings.ttlOverrides?.page ?? ''
	);
	const [ ttlProduct, setTtlProduct ] = useState(
		cacheSettings.ttlOverrides?.product ?? ''
	);
	const [ wooSafeMode, setWooSafeMode ] = useState(
		cacheSettings.wooSafeMode ?? true
	);
	const [ wooSelfTest, setWooSelfTest ] = useState( null );
	const [ wooSelfTestLoading, setWooSelfTestLoading ] = useState( false );
	// Upgrade auto-purge status (issue #1276): last-purge reason + safe
	// preview link bypassing minify. Shared hook seeded from
	// wppoSettings.upgradePurge (audit #1515).
	const { upgradePurge, refreshUpgradePurgeStatus } = useUpgradePurgeStatus();
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
		const life = Number( cacheSettings.cacheLife ?? 0 );
		setCacheLife( Number.isFinite( life ) ? life : 0 );
		const ov =
			cacheSettings.ttlOverrides &&
			typeof cacheSettings.ttlOverrides === 'object'
				? cacheSettings.ttlOverrides
				: {};
		setTtlPost( ov?.post ?? '' );
		setTtlPage( ov?.page ?? '' );
		setTtlProduct( ov?.product ?? '' );
		setWooSafeMode( cacheSettings.wooSafeMode ?? true );
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
		cacheSettings.wooSafeMode,
		cacheSettings.cdnPurgeService,
		cacheSettings.cloudflareZoneId,
		cacheSettings.varnishPurgeUrls,
	] );

	const [ bgProcessing, setBgProcessing ] = useState( false );
	const [ bgJobsQueued, setBgJobsQueued ] = useState( 0 );
	const [ imgSavings, setImgSavings ] = useState( null );
	const pollingRef = useRef( null );
	const pollAbortRef = useRef( null );
	const pollRetryRef = useRef( 0 );
	const pollAttemptsRef = useRef( 0 );
	const submittingRef = useRef( false );
	const wooAbortRef = useRef( null );
	const wooTimedOutRef = useRef( false );
	const wooTimeoutRef = useRef( null );
	const actionControllersRef = useRef( new Set() );
	const [ confirmRemove, setConfirmRemove ] = useState( false );
	const { notice, notify, dismiss } = useNotice();

	const {
		imageInfo,
		loading,
		totalCacheSize,
		cachedPages,
		totalJs,
		totalCss,
		dbCounts,
	} = state;
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

	const fetchDbCounts = useCallback(
		async ( signal ) => {
			handleLoading( 'db_counts', true );
			try {
				const data = await getDbCounts( signal );
				if ( signal?.aborted ) {
					return;
				}
				updateState( { dbCounts: data } );
			} catch ( error ) {
				if ( error?.name === 'AbortError' || signal?.aborted ) {
					return;
				}
				console.error(
					'Error fetching db counts:',
					getErrorLogMessage( error )
				);
				notify( {
					type: 'error',
					message: __(
						'Failed to load database counts.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} finally {
				if ( ! signal?.aborted ) {
					handleLoading( 'db_counts', false );
				}
			}
		},
		[ handleLoading, updateState, notify ]
	);

	useEffect( () => {
		const controller = new AbortController();
		fetchDbCounts( controller.signal );
		return () => controller.abort();
	}, [ fetchDbCounts ] );

	// Abort any in-flight Woo self-test on unmount so a slow request can
	// never call setWooSelfTest/notify after the component is gone.
	useEffect( () => {
		return () => {
			if ( wooTimeoutRef.current ) {
				clearTimeout( wooTimeoutRef.current );
				wooTimeoutRef.current = null;
			}
			if ( wooAbortRef.current ) {
				wooAbortRef.current.abort();
			}
		};
	}, [] );

	const dbOverheadCount = useMemo( () => {
		return Object.entries( dbCounts ).reduce( ( sum, [ , val ] ) => {
			// Skip object-valued payloads (e.g. action_scheduler_health)
			// so they can never pollute the numeric total.
			if ( val !== null && typeof val === 'object' ) {
				return sum;
			}
			return sum + ( parseInt( val, 10 ) || 0 );
		}, 0 );
	}, [ dbCounts ] );

	const pollJobStatus = useCallback( async () => {
		const currentTimeout = pollingRef.current;
		pollAttemptsRef.current += 1;
		if ( pollAttemptsRef.current >= MAX_POLL_ATTEMPTS ) {
			setBgProcessing( false );
			pollingRef.current = null;
			notify( {
				type: 'error',
				message: __(
					'Image optimisation timed out. Please try again.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
			return;
		}
		// Polls are strictly sequential: the next tick is only scheduled
		// after the previous await settles, so there is no overlapping
		// in-flight request to abort here. A fresh controller per tick lets
		// the unmount cleanup cancel the current poll.
		pollAbortRef.current = new AbortController();
		const signal = pollAbortRef.current.signal;
		try {
			const response = await apiCall(
				'image_job_status',
				{},
				'GET',
				signal
			);
			// The unmount/stop cleanup may have aborted this tick while the
			// request was in flight — bail before any setState/notify.
			if ( signal.aborted ) {
				return;
			}
			pollRetryRef.current = 0;
			if ( ! response.success || ! response.data ) {
				// Malformed payload: treat as a retryable failure with backoff
				// instead of silently rescheduling until the 5-minute timeout.
				throw new Error( response.message || 'Status check failed' );
			}
			// Coerce: the endpoint may omit queued_jobs or encode it as a
			// string ("0"). Strict === 0 would then never detect completion
			// and poll until MAX_POLL_ATTEMPTS; NaN stays retryable below.
			const queuedJobs = Number( response.data.queued_jobs ?? NaN );
			setBgJobsQueued( Number.isFinite( queuedJobs ) ? queuedJobs : 0 );
			setImgSavings( response.data.savings ?? null );

			// Reuse the mount/sync-path normalizer so array-of-paths payloads
			// (like wppoSettings.image_info) never store an Array where a
			// count is expected (which would coerce totals to strings).
			updateState( {
				imageInfo: normalizeImageInfo( response.data ),
			} );

			if ( Number.isFinite( queuedJobs ) && queuedJobs === 0 ) {
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
				pollAttemptsRef.current = 0;
				return;
			}
		} catch ( error ) {
			if ( signal.aborted || error?.name === 'AbortError' ) {
				return;
			}
			console.error(
				'Error polling job status:',
				getErrorLogMessage( error )
			);
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
			// Audit #1354 review: back off while hidden (mirrors PageSpeed).
			const delay = getPollDelay( pollAttemptsRef.current );
			pollingRef.current = setTimeout(
				pollJobStatus,
				typeof document !== 'undefined' && document.hidden
					? Math.max( delay, 30000 )
					: delay
			);
		}
	}, [ updateState, notify ] );

	useEffect( () => {
		const pendingActions = actionControllersRef.current;
		return () => {
			if ( pollingRef.current ) {
				clearTimeout( pollingRef.current );
			}
			if ( pollAbortRef.current ) {
				pollAbortRef.current.abort();
			}
			pendingActions.forEach( ( c ) => c.abort() );
			pendingActions.clear();
		};
	}, [] );

	const onClearCache = useCallback(
		( e ) => {
			if ( e && typeof e.preventDefault === 'function' ) {
				e.preventDefault();
			}
			handleLoading( 'clear_cache', true );
			const controller = new AbortController();
			actionControllersRef.current.add( controller );
			const signal = controller.signal;
			apiCall( 'clear_cache', { action: 'clear_cache' }, 'POST', signal )
				.then( ( data ) => {
					if ( signal.aborted ) {
						return;
					}
					if ( data.success ) {
						notify( {
							type: 'success',
							message: __(
								'Cache cleared successfully.',
								'performance-optimisation'
							),
							durationMs: 5000,
						} );
						refreshUpgradePurgeStatus();
						updateState( {
							totalCacheSize: formatBytes( 0 ),
							cachedPages: 0,
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
				// Audit #1354: log rejections like the other handlers do.
				.catch( ( clearError ) => {
					if ( signal.aborted || clearError?.name === 'AbortError' ) {
						return;
					}
					console.error(
						'Failed to clear cache.',
						getErrorLogMessage( clearError )
					);
					notify( {
						type: 'error',
						message: __(
							'Failed to clear cache.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
				} )
				.finally( () => {
					actionControllersRef.current.delete( controller );
					if ( ! signal.aborted ) {
						handleLoading( 'clear_cache', false );
					}
				} );
		},
		[ handleLoading, updateState, notify, refreshUpgradePurgeStatus ]
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
		const controller = new AbortController();
		actionControllersRef.current.add( controller );
		const signal = controller.signal;

		apiCall( 'optimise_image', {}, 'POST', signal )
			.then( ( response ) => {
				if ( signal.aborted ) {
					return;
				}
				if ( response.data?.background ) {
					// Background (Action Scheduler) path.
					setBgProcessing( true );
					const jobsQueued = Number( response.data.jobs_queued ?? 0 );
					setBgJobsQueued(
						Number.isFinite( jobsQueued ) ? jobsQueued : 0
					);
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
					pollAttemptsRef.current = 0;
					pollRetryRef.current = 0;
					pollingRef.current = setTimeout(
						pollJobStatus,
						POLL_INTERVAL_MS
					);
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
			// Audit #1354: log rejections like pollJobStatus does.
			.catch( ( optimizeError ) => {
				if ( signal.aborted || optimizeError?.name === 'AbortError' ) {
					return;
				}
				console.error(
					'Image optimisation failed.',
					getErrorLogMessage( optimizeError )
				);
				notify( {
					type: 'error',
					message: __(
						'Image optimisation failed.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} )
			.finally( () => {
				actionControllersRef.current.delete( controller );
				submittingRef.current = false;
				if ( ! signal.aborted ) {
					handleLoading( 'optimize_images', false );
				}
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
		const controller = new AbortController();
		actionControllersRef.current.add( controller );
		const signal = controller.signal;
		apiCall( 'delete_optimised_image', {}, 'POST', signal )
			.then( ( data ) => {
				if ( signal.aborted ) {
					return;
				}
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
			.catch( ( dashboardError ) => {
				if ( signal.aborted || dashboardError?.name === 'AbortError' ) {
					return;
				}
				// Audit #1420: log before notify.
				console.error(
					'Dashboard request failed:',
					getErrorLogMessage( dashboardError )
				);
				notify( {
					type: 'error',
					message: __(
						'Failed to remove optimized images.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} )
			.finally( () => {
				actionControllersRef.current.delete( controller );
				if ( ! signal.aborted ) {
					handleLoading( 'remove_images', false );
				}
			} );
	}, [ handleLoading, notify ] );

	/**
	 * Single save helper for the cache_settings tab: re-reads the live global
	 * at call-time (avoiding stale closures after a prior save froze it),
	 * merges the patch, and notifies success/failure. One place to fix means
	 * the error branch can no longer be missed in one saver but not others.
	 */
	const saveCacheTab = useCallback(
		( patch, setSaving, successMessage, failureMessage ) => {
			setSaving( true );
			// Re-read global wppoSettings at call-time to avoid stale closure
			// after prior save mutated it via apiCall's freeze.
			const currentSettings =
				getWppoSettings( 'settings.cache_settings', null ) ??
				cacheSettings ??
				{};
			const controller = new AbortController();
			actionControllersRef.current.add( controller );
			const signal = controller.signal;
			return apiCall(
				'update_settings',
				{
					tab: 'cache_settings',
					settings: {
						...currentSettings,
						...patch,
					},
				},
				'POST',
				signal
			)
				.then( ( response ) => {
					if ( signal.aborted ) {
						return;
					}
					if ( response.success && response.data ) {
						notify( {
							type: 'success',
							message: successMessage,
							durationMs: 5000,
						} );
					} else {
						notify( {
							type: 'error',
							message: response.message || failureMessage,
							durationMs: 5000,
						} );
					}
				} )
				.catch( ( dashboardError ) => {
					if (
						signal.aborted ||
						dashboardError?.name === 'AbortError'
					) {
						return;
					}
					// Audit #1420: log before notify.
					console.error(
						'Dashboard request failed:',
						getErrorLogMessage( dashboardError )
					);
					notify( {
						type: 'error',
						message: failureMessage,
						durationMs: 5000,
					} );
				} )
				.finally( () => {
					actionControllersRef.current.delete( controller );
					if ( ! signal.aborted ) {
						setSaving( false );
					}
				} );
		},
		[ cacheSettings, notify ]
	);

	const savePageCacheSettings = useCallback( () => {
		const overrides = {};
		const post = toTtlOverride( ttlPost );
		const page = toTtlOverride( ttlPage );
		const product = toTtlOverride( ttlProduct );
		if ( post !== undefined ) {
			overrides.post = post;
		}
		if ( page !== undefined ) {
			overrides.page = page;
		}
		if ( product !== undefined ) {
			overrides.product = product;
		}
		const life = Number( cacheLife );
		return saveCacheTab(
			{
				enableCache: pageCacheEnabled,
				cacheLife: Number.isFinite( life ) ? life : 0,
				ttlOverrides: overrides,
				wooSafeMode,
			},
			setSavingPageCache,
			__( 'Page cache settings saved.', 'performance-optimisation' ),
			__(
				'Failed to save page cache settings.',
				'performance-optimisation'
			)
		);
	}, [
		pageCacheEnabled,
		cacheLife,
		ttlPost,
		ttlPage,
		ttlProduct,
		wooSafeMode,
		saveCacheTab,
	] );

	const runWooCacheSelfTest = useCallback( () => {
		setWooSelfTestLoading( true );
		// Abort any previous in-flight test and clear its timeout first so
		// a rapid re-run can never let a stale timer misclassify the new
		// run's abort as a 5s timeout, nor let stale results win.
		if ( wooTimeoutRef.current ) {
			clearTimeout( wooTimeoutRef.current );
			wooTimeoutRef.current = null;
		}
		if ( wooAbortRef.current ) {
			wooAbortRef.current.abort();
		}
		const controller =
			typeof AbortController !== 'undefined'
				? new AbortController()
				: null;
		wooAbortRef.current = controller;
		wooTimedOutRef.current = false;
		if ( controller ) {
			wooTimeoutRef.current = setTimeout( () => {
				wooTimedOutRef.current = true;
				controller.abort();
			}, WOO_SELF_TEST_TIMEOUT_MS );
		}
		fetchWooCacheSelfTest( controller?.signal )
			.then( ( response ) => {
				// Bail when this run is no longer current (a newer re-run
				// replaced it) or its signal was aborted — stale results
				// must never overwrite the latest run.
				if (
					wooAbortRef.current !== controller ||
					wooAbortRef.current?.signal?.aborted
				) {
					return;
				}
				if ( response.success && response.data ) {
					setWooSelfTest( response.data );
					const descriptor = getWooSelfTestNotice( response.data );
					notify( { ...descriptor, durationMs: 5000 } );
				} else {
					notify( {
						type: 'error',
						message: __(
							'Failed to run the WooCommerce self-test.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
				}
			} )
			.catch( ( error ) => {
				// An abort from unmount cleanup (not the 5s timeout) must
				// stay silent: notifying after unmount is spurious. The
				// timeout sets wooTimedOutRef before aborting, so an aborted
				// signal without the flag means unmount (or a superseded run).
				if ( controller?.signal?.aborted && ! wooTimedOutRef.current ) {
					return;
				}
				if (
					error?.name === 'AbortError' ||
					controller?.signal?.aborted
				) {
					console.error(
						'Woo self-test timed out:',
						getErrorLogMessage( error )
					);
					notify( {
						type: 'error',
						message: __(
							'The WooCommerce self-test timed out after 5 seconds. Please retry.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
					return;
				}
				console.error(
					'Woo self-test failed:',
					getErrorLogMessage( error )
				);
				notify( {
					type: 'error',
					message: __(
						'Failed to run the WooCommerce self-test.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} )
			.finally( () => {
				if ( wooTimeoutRef.current ) {
					clearTimeout( wooTimeoutRef.current );
					wooTimeoutRef.current = null;
				}
				if ( wooAbortRef.current === controller ) {
					wooAbortRef.current = null;
					setWooSelfTestLoading( false );
				}
			} );
	}, [ notify ] );

	const saveLoggedInCacheSettings = useCallback( () => {
		return saveCacheTab(
			{
				enableLoggedInCache: loggedInCacheEnabled,
				loggedInCacheRoles,
				// Carry the live safe-mode switch so saving this card can
				// never silently reset a staged fix back to the last
				// committed value via the global-settings sync effect.
				wooSafeMode,
			},
			setSavingLoggedInCache,
			__( 'Logged-in cache settings saved.', 'performance-optimisation' ),
			__(
				'Failed to save logged-in cache settings.',
				'performance-optimisation'
			)
		);
	}, [
		loggedInCacheEnabled,
		loggedInCacheRoles,
		wooSafeMode,
		saveCacheTab,
	] );

	const saveCdnPurgeSettings = useCallback( () => {
		const service = CDN_PURGE_SERVICES.includes( cdnPurgeService )
			? cdnPurgeService
			: 'none';
		return saveCacheTab(
			{
				cdnPurgeService: service,
				cloudflareZoneId,
				varnishPurgeUrls: parseVarnishPurgeUrls( varnishPurgeUrls ),
				// Carry the live safe-mode switch so saving this card can
				// never silently reset a staged fix back to the last
				// committed value via the global-settings sync effect.
				wooSafeMode,
			},
			setSavingCdnPurge,
			__( 'CDN purge settings saved.', 'performance-optimisation' ),
			__(
				'Failed to save CDN purge settings.',
				'performance-optimisation'
			)
		);
	}, [
		cdnPurgeService,
		cloudflareZoneId,
		varnishPurgeUrls,
		wooSafeMode,
		saveCacheTab,
	] );

	const handleLoggedInCacheToggle = useCallback( ( e ) => {
		setLoggedInCacheEnabled( e.target.checked );
	}, [] );

	const handleRoleCheckbox = useCallback(
		( e ) => {
			const role = e.target.name;
			const checked = e.target.checked;
			// Defense-in-depth: intersect DOM-supplied role slugs against the
			// known roles map (server allowlists too); tampered names never
			// reach the payload.
			if (
				! userRoles ||
				typeof userRoles !== 'object' ||
				! Object.hasOwnProperty.call( userRoles, role )
			) {
				return;
			}
			setLoggedInCacheRoles( ( prev ) =>
				checked ? [ ...prev, role ] : prev.filter( ( r ) => r !== role )
			);
		},
		[ userRoles ]
	);

	// Stable form handlers so the 5s image_job_status poll re-render does not
	// hand fresh inline closures to every SwitchField/select/input child.
	const handlePageCacheToggle = useCallback( ( e ) => {
		setPageCacheEnabled( e.target.checked );
	}, [] );
	const handleCacheLifeChange = useCallback( ( e ) => {
		const n = Number( e.target.value );
		setCacheLife( Number.isFinite( n ) ? n : 0 );
	}, [] );
	const handleTtlPostChange = useCallback( ( e ) => {
		setTtlPost( '' === e.target.value ? '' : Number( e.target.value ) );
	}, [] );
	const handleTtlPageChange = useCallback( ( e ) => {
		setTtlPage( '' === e.target.value ? '' : Number( e.target.value ) );
	}, [] );
	const handleTtlProductChange = useCallback( ( e ) => {
		setTtlProduct( '' === e.target.value ? '' : Number( e.target.value ) );
	}, [] );
	const handleWooSafeModeToggle = useCallback( ( e ) => {
		setWooSafeMode( e.target.checked );
	}, [] );
	// Stage WooCommerce safe mode on from a FAIL self-test result.
	//
	// Staging only: the existing Save Page Cache Settings flow remains the
	// commit path, so the button label and helper copy say so explicitly
	// and a notice reminds the user to save. Focus moves to the safe-mode
	// switch (shared scrollToWooSafeMode helper) so keyboard and
	// screen-reader users land on the staged fix. Sibling save cards carry
	// the live wooSafeMode value so a staged fix survives saving another
	// card; switching tabs still discards unstaged changes.
	const handleReenableWooSafeMode = useCallback( () => {
		setWooSafeMode( true );
		notify( {
			type: 'info',
			message: __(
				'WooCommerce safe mode staged on — click Save Page Cache Settings below to apply.',
				'performance-optimisation'
			),
			durationMs: 5000,
		} );
		scrollToWooSafeMode();
	}, [ notify ] );
	const handleCdnPurgeServiceChange = useCallback( ( e ) => {
		setCdnPurgeService(
			CDN_PURGE_SERVICES.includes( e.target.value )
				? e.target.value
				: 'none'
		);
	}, [] );
	const handleCloudflareZoneIdChange = useCallback( ( e ) => {
		setCloudflareZoneId( e.target.value );
	}, [] );
	const handleVarnishPurgeUrlsChange = useCallback( ( e ) => {
		setVarnishPurgeUrls( e.target.value );
	}, [] );
	const handleRemoveRequest = useCallback( () => {
		setConfirmRemove( true );
	}, [] );
	const handleRemoveConfirm = useCallback( () => {
		setConfirmRemove( false );
		removeImages();
	}, [ removeImages ] );
	const handleRemoveCancel = useCallback( () => {
		setConfirmRemove( false );
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
	const rawCachedPages = Number( cachedPages ?? 0 );
	const cachedPagesCount = Number.isFinite( rawCachedPages )
		? Math.max( 0, Math.floor( rawCachedPages ) )
		: 0;
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
	const litespeedInfo = getWppoSettings( 'litespeed', null );
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
					role="status"
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
								? __(
										'LSCache Active',
										'performance-optimisation'
								  )
								: __(
										'LSCache Inactive',
										'performance-optimisation'
								  ) }
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

			<WelcomePanel onNavigate={ onNavigate } />

			{ /* One-click presets with diff preview + restore-point undo (NEXT) */ }
			<OptimizationPresets />

			<UpgradePurgeBanner upgradePurge={ upgradePurge } />

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
						{ ! isCacheMissing && (
							<>
								{ ' • ' }
								{ sprintf(
									/* translators: %d: cached page file count. */
									__(
										'%d pages',
										'performance-optimisation'
									),
									cachedPagesCount
								) }
							</>
						) }
					</span>
					<div className="wppo-stat-footer">
						<LoadingSubmitButton
							className="wppo-button wppo-button--secondary wppo-button--sm wppo-stat-link"
							isLoading={ !! loading.clear_cache }
							onClick={ onClearCache }
							type="button"
							label={ __(
								'Purge Cache',
								'performance-optimisation'
							) }
						/>
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
						{ _n(
							'file',
							'files',
							optimizedFilesCount,
							'performance-optimisation'
						) }
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
						{ _n(
							'item',
							'items',
							dbOverheadCount,
							'performance-optimisation'
						) }
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
				icon={ <FontAwesomeIcon icon={ faBolt } aria-hidden="true" /> }
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
					onChange={ handlePageCacheToggle }
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
						onChange={ handleCacheLifeChange }
						aria-describedby="wppoCacheLife-desc"
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
					<p
						id="wppoCacheLife-desc"
						className="wppo-text-muted wppo-text-small"
					>
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
						onChange={ handleTtlPostChange }
						aria-describedby="wppoTtlOverrides-desc"
					>
						<TtlDurationOptions />
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
						onChange={ handleTtlPageChange }
						aria-describedby="wppoTtlOverrides-desc"
					>
						<TtlDurationOptions />
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
						onChange={ handleTtlProductChange }
						aria-describedby="wppoTtlOverrides-desc"
					>
						<TtlDurationOptions />
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
				<div id="wppoWooSafeMode" tabIndex="-1">
					<SwitchField
						label={ __(
							'WooCommerce safe mode',
							'performance-optimisation'
						) }
						description={ __(
							'Always bypass the static cache for cart, checkout, account pages and Store API routes. Custom Woo slugs stay excluded even without WooCommerce conditional tags.',
							'performance-optimisation'
						) }
						name="wooSafeMode"
						checked={ wooSafeMode }
						onChange={ handleWooSafeModeToggle }
					/>
				</div>
				<div className="wppo-field">
					<LoadingSubmitButton
						type="button"
						className="wppo-button wppo-button--secondary"
						onClick={ runWooCacheSelfTest }
						isLoading={ wooSelfTestLoading }
						aria-describedby="wppo-woo-cache-self-test-desc"
						label={ __(
							'Run Woo Cache Self-Test',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Running…',
							'performance-optimisation'
						) }
					/>
					<p
						id="wppo-woo-cache-self-test-desc"
						className="wppo-text-muted wppo-text-small"
					>
						{ __(
							'Proves in one click that cart, checkout and account paths plus cart/checkout fragments (?wc-ajax=, ?add-to-cart=, plain-permalink Store API) bypass the static cache under path/query/safe-mode semantics, that faceted filter URLs are skipped by preload, and that a guest cart survives with page and object cache on (DONOTCACHEPAGE enforcement is assumed via Cache::is_not_cacheable(); the wppo_woo_cacheable override is out of scope). On failure, force-exclude dynamic routes plus cookie bypass (re-enable safe mode) and serve dynamic.',
							'performance-optimisation'
						) }
					</p>
				</div>
				{ wooSelfTest && (
					<div className="wppo-field">
						<div role="status" aria-live="polite">
							{ ! wooSelfTest.runnable && (
								<p className="wppo-text-muted wppo-text-small">
									{ __(
										'The self-test could not run. Default exclusion paths are shown read-only; dynamic pages fail open to uncached.',
										'performance-optimisation'
									) }
								</p>
							) }
							{ wooSelfTest.runnable &&
								! wooSelfTest.woo_active && (
									<p className="wppo-text-muted wppo-text-small">
										{ __(
											'WooCommerce is not active — showing default exclusion paths read-only. Dynamic pages fail open to uncached.',
											'performance-optimisation'
										) }
									</p>
								) }
							<p className="wppo-text-muted wppo-text-small">
								{ formatWooSummary(
									wooSelfTest.safe_mode,
									wooSelfTest.excluded_paths
								) }
							</p>
						</div>
						{ shouldShowWooFixCta( wooSelfTest ) && (
							<p className="wppo-text-muted wppo-text-small">
								{ __(
									'Self-test failed: force-excluding dynamic routes plus cookie bypass (fail-closed for commerce). Stage WooCommerce safe mode back on, then save below to apply — never a stale cart.',
									'performance-optimisation'
								) }{ ' ' }
								<button
									type="button"
									className="wppo-button wppo-button--secondary wppo-button--sm"
									onClick={ handleReenableWooSafeMode }
								>
									{ __(
										'Stage safe mode on',
										'performance-optimisation'
									) }
								</button>{ ' ' }
								<span>
									{ __(
										'Staging only — click Save Page Cache Settings to apply.',
										'performance-optimisation'
									) }
								</span>
							</p>
						) }
						{ Array.isArray( wooSelfTest.checks ) && (
							<WooCheckList
								items={ wooSelfTest.checks }
								kind="route"
								idPrefix="check"
								pathKey="path"
							/>
						) }
						{ Array.isArray( wooSelfTest.fragment_checks ) &&
							wooSelfTest.fragment_checks.length > 0 && (
								<>
									<p className="wppo-text-muted wppo-text-small">
										{ __(
											'Fragment probes (query-string):',
											'performance-optimisation'
										) }
									</p>
									<WooCheckList
										items={ wooSelfTest.fragment_checks }
										kind="fragment"
										idPrefix="fragment"
										pathKey="path"
										listLabel={ __(
											'Fragment probes (query-string)',
											'performance-optimisation'
										) }
									/>
								</>
							) }
						{ Array.isArray( wooSelfTest.editor_checks ) &&
							wooSelfTest.editor_checks.length > 0 && (
								<>
									<p className="wppo-text-muted wppo-text-small">
										{ __(
											'Editor bypass probes (admin + previews):',
											'performance-optimisation'
										) }
									</p>
									<WooCheckList
										items={ wooSelfTest.editor_checks }
										kind="editor"
										idPrefix="editor"
										pathKey="path"
										listLabel={ __(
											'Editor bypass probes (admin + previews)',
											'performance-optimisation'
										) }
									/>
								</>
							) }
						{ Array.isArray( wooSelfTest.preload_checks ) &&
							wooSelfTest.preload_checks.length > 0 && (
								<>
									<p className="wppo-text-muted wppo-text-small">
										{ __(
											'Preload probes (faceted URLs skipped):',
											'performance-optimisation'
										) }
									</p>
									<WooCheckList
										items={ wooSelfTest.preload_checks }
										kind="preload"
										idPrefix="preload"
										pathKey="path"
										listLabel={ __(
											'Preload probes (faceted URLs skipped)',
											'performance-optimisation'
										) }
									/>
								</>
							) }
						{ Array.isArray( wooSelfTest.cart_checks ) &&
							wooSelfTest.cart_checks.length > 0 && (
								<>
									<p className="wppo-text-muted wppo-text-small">
										{ __(
											'Guest-cart survival (page + object cache on):',
											'performance-optimisation'
										) }
									</p>
									<WooCheckList
										items={ wooSelfTest.cart_checks }
										kind="cart"
										idPrefix="cart"
										pathKey="key"
										listLabel={ __(
											'Guest-cart survival (page + object cache on)',
											'performance-optimisation'
										) }
									/>
								</>
							) }
					</div>
				) }
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
				icon={ <FontAwesomeIcon icon={ faGlobe } aria-hidden="true" /> }
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
						onChange={ handleCdnPurgeServiceChange }
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
							onChange={ handleCloudflareZoneIdChange }
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
							onChange={ handleVarnishPurgeUrlsChange }
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
			<LoggedInCacheCard
				enabled={ loggedInCacheEnabled }
				selectedRoles={ loggedInCacheRoles }
				saving={ savingLoggedInCache }
				userRoles={ userRoles }
				onToggle={ handleLoggedInCacheToggle }
				onRoleChange={ handleRoleCheckbox }
				onSave={ saveLoggedInCacheSettings }
			/>

			{ /* Phase 1 — Performance Audit & System Info (v1.5.0) */ }
			<div className="wppo-stacked-cards">
				{ /* Guided single RUM-driven next action + server-type note (NEXT) */ }
				<GuidedNextStep onNavigate={ onNavigate } />

				<PerformanceAudit
					onSuggestionsReady={ setTelemetrySuggestions }
					onUrlChange={ handleAuditUrlChange }
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
					onRemove={ handleRemoveRequest }
				/>

				<RecentActivityCard
					activities={ activities }
					activitiesError={ activitiesError }
					onNavigate={ onNavigate }
				/>
			</div>

			<ConfirmDialog
				isOpen={ confirmRemove }
				onConfirm={ handleRemoveConfirm }
				onCancel={ handleRemoveCancel }
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
