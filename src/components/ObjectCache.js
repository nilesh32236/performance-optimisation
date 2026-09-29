import {
	useState,
	useEffect,
	useId,
	useCallback,
	useContext,
	useMemo,
	useRef,
} from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall, getErrorLogMessage } from '../lib/apiRequest';
import {
	invalidateObjectCacheStatus,
	shouldInvalidateObjectCache,
} from '../lib/objectCacheStatus';
import useNotice from '../lib/useNotice';
import useUnsavedChanges from '../lib/useUnsavedChanges';
import UnsavedChangesContext from '../lib/UnsavedChangesContext';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faBroom,
	faLink,
	faCheckCircle,
	faExclamationCircle,
	faInfoCircle,
	faShieldAlt,
	faTimes,
	faNetworkWired,
	faMemory,
	faChartBar,
	faUsers,
	faServer,
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import SwitchField from './common/SwitchField';
import NoticeBanner from './common/NoticeBanner';
import ConfirmDialog from './common/ConfirmDialog';

import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Object-cache REST actions that authenticate against Redis and therefore may
 * carry connection settings (including the password) in the request body.
 * 'status', 'flush' and 'disable' only need the deployment mode, so the
 * password is never sent for them.
 *
 * @type {string[]}
 */
const CREDENTIALS_REQUIRED_ACTIONS = [
	'enable',
	'ping',
	'authenticate',
	'test-connection',
];

const getCompressionLabel = ( statusLoaded, supported, key ) => {
	if ( ! statusLoaded ) {
		return '';
	}
	if ( ! supported?.[ key ] ) {
		return ` (${ __( '(Disabled)', 'performance-optimisation' ) })`;
	}
	if ( key === 'zstd' ) {
		return ` (${ __( '(Recommended)', 'performance-optimisation' ) })`;
	}
	return '';
};

/**
 * The single definition of the object-cache settings shape.
 *
 * The defaults and the dirty-state baseline used to be two *independent*
 * hard-coded lists, and they had already drifted: the plugin schema
 * (`includes/class-util.php`) declares **13** keys, while both lists carried
 * **10** — `timeout`, `prefix` and `outage_bypassed` were in neither.
 *
 * Because `defaultSettings` spread `...options`, a server value such as
 * `outage_bypassed` landed in `settings` but could never land in the baseline.
 * The comparison then differed on first render, so the screen reported itself
 * dirty before the user had touched anything, and **every** attempt to navigate
 * away raised a false "You have unsaved changes — Discard?" dialog. The modal
 * covered the sidebar, so the screen was a trap until the user found Discard.
 *
 * One frozen table, used for both, so the key sets cannot diverge again.
 *
 * The table lists only the keys the plugin actually reads. The schema in
 * `includes/class-util.php` declares 13, but it declares *types* and no
 * values, and `Settings_Store` defaults `object_cache` to an empty array for
 * back-compat. `timeout` and `prefix` are declared and yet have **no
 * consumer**: the connect timeout is hardcoded `0.5` in
 * `Redis_Connect_Helper` and `templates/object-cache.php`, and the drop-in
 * builds its blog prefix from `$table_prefix`, not from settings. Adding
 * invented values here would have started writing two keys the plugin never
 * wrote before. Keys missing from this table are still carried through
 * `withServerValues`, so nothing the server sends is dropped.
 */
const OBJECT_CACHE_DEFAULTS = Object.freeze( {
	mode: 'standalone',
	host: '127.0.0.1',
	port: 6379,
	password: '',
	database: 0,
	nodes: '',
	master_name: 'mymaster',
	use_tls: false,
	persistent: false,
	compression: 'none',
	outage_bypassed: false,
} );

/**
 * Merge server values over the defaults.
 *
 * Every key the server sends is carried, including ones this table does not
 * list, so a future schema addition is never silently dropped. An explicit
 * `undefined` is skipped rather than assigned, so it cannot clobber a real
 * default with a missing one.
 *
 * @param {Object} options Values from the server.
 * @return {Object} A complete settings object.
 */

const withServerValues = ( options ) => {
	const merged = { ...OBJECT_CACHE_DEFAULTS };
	for ( const key of Object.keys( options ) ) {
		if ( options[ key ] !== undefined ) {
			merged[ key ] = options[ key ];
		}
	}
	return merged;
};

const ObjectCache = ( { options = {} } ) => {
	const hitRatioLabelId = useId();
	const defaultSettings = withServerValues( options );

	const [ settings, setSettings ] = useState( defaultSettings );
	const [ isLoading, setIsLoading ] = useState( false );
	const { setIsDirty } = useContext( UnsavedChangesContext );
	const [ baseline, setBaseline ] = useState( defaultSettings );
	// The baseline is built from the *same* table as the settings, so the two
	// can never hold different key sets — which is the whole fix. It is memoised
	// on the server values so its identity stays stable across renders (the
	// intent of audit #1420, which had introduced the duplicated list), and it
	// no longer re-lists the keys.
	//
	// The `options` reference is deliberately excluded: depending on it would
	// recompute the memo on every render, because `App` hands down a freshly
	// built object each time. `optionsKey` captures the *content*, which is what
	// the baseline actually depends on. The disable below silences the
	// `react-hooks/exhaustive-deps` warning about that missing reference; remove
	// it and the rule reports a missing `options` dep. It is a warning, not an
	// error — `lint:js` sets no `--max-warnings`, so CI would still pass.
	const optionsKey = JSON.stringify( options );
	const memoizedBaseline = useMemo(
		() => withServerValues( options ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ optionsKey ]
	);
	useEffect( () => {
		setBaseline( memoizedBaseline );
		// Audit #1420: memoized value is the only dep — no suppression.
	}, [ memoizedBaseline ] );
	useUnsavedChanges( settings, baseline );
	const [ activeAction, setActiveAction ] = useState( null );
	const isActionLoading = Boolean( activeAction );
	const [ cacheStatus, setCacheStatus ] = useState( {
		enabled: false,
		redis_missing: false,
		foreign_dropin: false,
		redis_reachable: false,
		circuit_open: false,
		circuit_tripped_at: 0,
		circuit_reason: '',
		circuit_error_code: '',
		failure_count: 0,
		statusLoaded: false,
		supported_compressors: null,
	} );
	const [ confirmDisable, setConfirmDisable ] = useState( false );
	const { notice, notify, dismiss } = useNotice();

	// Audit #1354: skip state updates after unmount.
	const isMountedRef = useRef( true );
	const submitControllerRef = useRef( null );
	useEffect( () => {
		return () => {
			isMountedRef.current = false;
			if ( submitControllerRef.current ) {
				submitControllerRef.current.abort();
			}
		};
	}, [] );

	const fetchStatus = useCallback(
		async ( signal ) => {
			try {
				const res = await apiCall(
					'object_cache',
					{ action: 'status' },
					'POST',
					signal
				);
				if ( signal?.aborted ) {
					return;
				}
				if ( res.success ) {
					setCacheStatus( ( prev ) => ( {
						...res.data,
						statusLoaded: true,
						supported_compressors: res.data
							?.supported_compressors ??
							prev.supported_compressors ?? { none: true },
					} ) );
				} else {
					setCacheStatus( ( prev ) => ( {
						...prev,
						statusLoaded: true,
						supported_compressors: prev.supported_compressors ?? {
							none: true,
						},
					} ) );
				}
			} catch ( error ) {
				// Audit #1354 review: signal-less callers (handleAction) still
				// need the mount check before notifying.
				if (
					signal?.aborted ||
					error?.name === 'AbortError' ||
					! isMountedRef.current
				) {
					return;
				}
				console.error(
					'Error fetching cache status',
					getErrorLogMessage( error )
				);
				setCacheStatus( ( prev ) => ( {
					...prev,
					statusLoaded: true,
					supported_compressors: prev.supported_compressors ?? {
						none: true,
					},
				} ) );
				notify( {
					type: 'error',
					message: __(
						'Failed to check cache status.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			}
		},
		[ notify ]
	);

	useEffect( () => {
		const controller = new AbortController();
		fetchStatus( controller.signal );
		return () => controller.abort();
	}, [ fetchStatus ] );

	const handleSubmit = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		if ( submitControllerRef.current ) {
			submitControllerRef.current.abort();
		}
		submitControllerRef.current = new AbortController();
		const signal = submitControllerRef.current.signal;
		setIsLoading( true );
		dismiss();

		try {
			// Avoid retransmitting the stored password on plain saves: send
			// the password only when the field is non-empty/changed, and
			// clear it from state after a successful save. Credential flows
			// (enable/ping/authenticate) use handleAction instead.
			const { password, ...rest } = settings;
			const payload = password ? settings : { ...rest, password: '' };
			const res = await apiCall(
				'update_settings',
				{
					tab: 'object_cache',
					settings: payload,
				},
				'POST',
				signal
			);
			if ( signal.aborted || ! isMountedRef.current ) {
				return;
			}
			if ( res.success ) {
				setSettings( ( prev ) => ( { ...prev, password: '' } ) );
				setBaseline( ( prev ) => ( { ...prev, password: '' } ) );
				setIsDirty( false );
				notify( {
					type: 'success',
					message: __(
						'Settings saved successfully.',
						'performance-optimisation'
					),
				} );
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Error saving settings.',
							'performance-optimisation'
						),
				} );
			}
		} catch ( err ) {
			if ( signal.aborted || err?.name === 'AbortError' ) {
				return;
			}
			if ( ! isMountedRef.current ) {
				return;
			}
			notify( {
				type: 'error',
				message: __(
					'Error saving settings.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
			console.error(
				'Error saving settings:',
				getErrorLogMessage( err )
			);
		} finally {
			submitControllerRef.current = null;
			if ( ! signal.aborted && isMountedRef.current ) {
				setIsLoading( false );
			}
		}
	};

	const handleAction = async ( action ) => {
		setActiveAction( action );
		dismiss();
		try {
			const payload = {
				action,
				...( CREDENTIALS_REQUIRED_ACTIONS.includes( action )
					? settings
					: { mode: settings.mode } ),
			};
			const res = await apiCall( 'object_cache', payload );
			if ( ! isMountedRef.current ) {
				return;
			}

			if ( ! res?.success ) {
				notify( {
					type: 'error',
					message:
						res?.message ||
						__( 'Action failed.', 'performance-optimisation' ),
					durationMs: 5000,
				} );

				// A *failed* action deliberately does not invalidate: nothing
				// changed, and the memo may be holding a perfectly good earlier
				// result. Burning it here would also spend one of the five calls a
				// minute this endpoint allows.
				return;
			}

			// The Overview memoises the object-cache status for the page session,
			// so navigating around does not re-hit a throttled endpoint. Every
			// action here changes exactly that status, so a successful action must
			// invalidate it: otherwise the user enables Redis here, goes back to
			// Overview, and is told the object cache is switched off until they
			// reload the page.
			//
			// This was previously on the *failure* branch, directly under a comment
			// saying the opposite — the exact call the comment contradicted. An
			// independent review caught it by spying on the module: a successful
			// flush invalidated 0 times and a failed one invalidated 1.
			//
			// The *rule* is pinned by `shouldInvalidateObjectCache` in
			// `objectCacheInvalidation.test.js`. **This call site is not.** Two
			// reviews verified that moving it back onto the failure branch leaves
			// the whole suite green, because the control that drives it is
			// disabled until the settings form is valid and so is unreachable
			// through the component in jsdom.
			//
			// I attempted to pin it by mocking this module and driving the button,
			// and it destabilised the existing `ObjectCache.test.js` suite. Rather
			// than ship a broken harness I reverted it and am recording the gap:
			// **moving this line to the wrong branch would not fail any test.** The
			// rule test makes the intent explicit, and the code comment keeps the
			// wiring visible, but neither is enforcement.
			if ( shouldInvalidateObjectCache( res ) ) {
				invalidateObjectCacheStatus();
			}

			// Minimise secret exposure: once the action that needed the
			// password has succeeded, drop it from state (and the dirty-state
			// baseline) so it does not linger in the JS heap or get re-sent
			// with unrelated requests. The input stays available for a new
			// password; stored settings never contain it (the server keeps a
			// `password_set` flag instead).
			if ( CREDENTIALS_REQUIRED_ACTIONS.includes( action ) ) {
				setSettings( ( prev ) => ( { ...prev, password: '' } ) );
				setBaseline( ( prev ) => ( { ...prev, password: '' } ) );
			}

			if (
				[ 'enable', 'disable', 'ping', 'recover' ].includes( action )
			) {
				await fetchStatus();
				if ( ! isMountedRef.current ) {
					return;
				}
			}
			notify( {
				type: 'success',
				message:
					res.message ||
					__( 'Action successful.', 'performance-optimisation' ),
				durationMs: 5000,
			} );
		} catch ( err ) {
			console.error(
				'Object cache action failed:',
				getErrorLogMessage( err )
			);
			if ( ! isMountedRef.current ) {
				return;
			}
			notify( {
				type: 'error',
				message: __( 'Action failed.', 'performance-optimisation' ),
				durationMs: 5000,
			} );
		} finally {
			if ( isMountedRef.current ) {
				setActiveAction( null );
			}
		}
	};

	const hitRatio = ( () => {
		if ( ! cacheStatus.telemetry ) {
			return '0.0';
		}
		const hits =
			Number.parseInt( cacheStatus.telemetry.keyspace_hits ?? '0', 10 ) ||
			0;
		const misses =
			Number.parseInt(
				cacheStatus.telemetry.keyspace_misses ?? '0',
				10
			) || 0;
		const total = hits + misses;
		return total > 0 ? ( ( hits / total ) * 100 ).toFixed( 1 ) : '0.0';
	} )();

	// Width bucket (5% steps) for the progress-bar fill SCSS modifier class,
	// so dynamic progress needs no inline style. The exact ratio stays
	// exposed via aria-valuenow/aria-valuetext on the progressbar.
	const hitRatioBucket = ( () => {
		const parsed = Number.parseFloat( hitRatio );
		if ( ! Number.isFinite( parsed ) ) {
			return 0;
		}
		return Math.min( 100, Math.max( 0, Math.round( parsed / 5 ) * 5 ) );
	} )();

	const connectionBadge = ( () => {
		if ( ! cacheStatus.statusLoaded ) {
			return null;
		}
		if ( ! cacheStatus.enabled ) {
			return {
				// `poor`, not `error`: the badge vocabulary is
				// good / warning / poor, and `--success` / `--error` have no
				// rules in the stylesheet, so "Connected" and "Disconnected"
				// rendered identically apart from their glyph and word.
				level: 'poor',
				text: `○ ${ __( 'Disconnected', 'performance-optimisation' ) }`,
			};
		}
		if ( cacheStatus.redis_reachable ) {
			return {
				level: 'good',
				text: `● ${ __( 'Connected', 'performance-optimisation' ) }`,
			};
		}
		return {
			level: 'warning',
			text: `○ ${ __( 'Unreachable', 'performance-optimisation' ) }`,
		};
	} )();

	const circuitDetail = ( () => {
		const parts = [];
		if ( cacheStatus.circuit_reason ) {
			parts.push( cacheStatus.circuit_reason );
		} else if ( cacheStatus.circuit_error_code ) {
			parts.push( cacheStatus.circuit_error_code );
		}
		if ( cacheStatus.failure_count > 0 ) {
			parts.push(
				`(${ sprintf(
					/* translators: %d: failure count. */
					_n(
						'%d failure',
						'%d failures',
						cacheStatus.failure_count,
						'performance-optimisation'
					),
					cacheStatus.failure_count
				) })`
			);
		}
		return parts.join( ' ' );
	} )();

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'Object Cache', 'performance-optimisation' ) }
				description={ __(
					'Enterprise-grade Redis object caching with Sentinel and Cluster support.',
					'performance-optimisation'
				) }
				actions={
					<div className="wppo-feature-header__actions">
						{ cacheStatus.enabled ? (
							<LoadingSubmitButton
								type="button"
								className="wppo-button wppo-button--secondary"
								onClick={ () => handleAction( 'flush' ) }
								disabled={ isActionLoading }
								isLoading={ activeAction === 'flush' }
								label={
									<>
										<FontAwesomeIcon icon={ faBroom } />{ ' ' }
										{ __(
											'Flush Cache',
											'performance-optimisation'
										) }
									</>
								}
							/>
						) : (
							<LoadingSubmitButton
								type="button"
								className="wppo-button wppo-button--primary"
								onClick={ () => handleAction( 'enable' ) }
								disabled={
									isActionLoading ||
									cacheStatus.redis_missing ||
									! cacheStatus.redis_reachable ||
									cacheStatus.foreign_dropin
								}
								isLoading={ activeAction === 'enable' }
								label={
									<>
										<FontAwesomeIcon
											icon={ faCheckCircle }
										/>{ ' ' }
										{ __(
											'Enable Object Cache',
											'performance-optimisation'
										) }
									</>
								}
							/>
						) }
					</div>
				}
			/>

			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }

			<div className="wppo-notices-container">
				<div className="wppo-notice wppo-notice--info">
					<FontAwesomeIcon icon={ faInfoCircle } />
					<div>
						<strong>
							{ __(
								'Nginx servers: protect the Redis config file',
								'performance-optimisation'
							) }
						</strong>
						<p>
							{ __(
								'Nginx ignores .htaccess rules, so the connection file stays fetchable unless denied at server level. The file never holds the password, but add this rule to hide topology:',
								'performance-optimisation'
							) }
						</p>
						<pre className="wppo-code-block">
							<code>
								{
									'location = /wp-content/wppo-redis-config.php { deny all; }'
								}
							</code>
						</pre>
					</div>
				</div>
				{ cacheStatus.redis_missing && (
					<div className="wppo-notice wppo-notice--error">
						<FontAwesomeIcon icon={ faExclamationCircle } />
						<div>
							<strong>
								{ __(
									'Extension Missing',
									'performance-optimisation'
								) }
							</strong>
							<p>
								{ __(
									'The PhpRedis extension is not installed. Native performance will be limited.',
									'performance-optimisation'
								) }
							</p>
						</div>
					</div>
				) }
				{ cacheStatus.foreign_dropin && (
					<div className="wppo-notice wppo-notice--warning">
						<FontAwesomeIcon icon={ faExclamationCircle } />
						<div>
							<strong>
								{ __(
									'Conflict Detected',
									'performance-optimisation'
								) }
							</strong>
							<p>
								{ __(
									'Another object cache plugin is currently active. Please disable it to avoid site crashes.',
									'performance-optimisation'
								) }
							</p>
						</div>
					</div>
				) }
				{ cacheStatus.circuit_open && (
					<div
						className="wppo-notice wppo-notice--warning"
						role="alert"
						aria-live="assertive"
					>
						<FontAwesomeIcon icon={ faExclamationCircle } />
						<div>
							<strong>
								{ __(
									'Object Cache Auto-Disabled',
									'performance-optimisation'
								) }
							</strong>
							<p>
								{ __(
									'The circuit breaker disabled the object cache after repeated Redis failures.',
									'performance-optimisation'
								) }{ ' ' }
								{ circuitDetail }
							</p>
							<p>
								<LoadingSubmitButton
									type="button"
									className="wppo-button wppo-button--primary"
									onClick={ () => handleAction( 'recover' ) }
									disabled={ isActionLoading }
									isLoading={ activeAction === 'recover' }
									label={ __(
										'Re-enable Object Cache',
										'performance-optimisation'
									) }
								/>
							</p>
						</div>
					</div>
				) }
			</div>

			{ cacheStatus.telemetry && cacheStatus.enabled && (
				<div className="wppo-stats-grid">
					<div className="wppo-stat-item">
						<span className="wppo-stat-label">
							<FontAwesomeIcon
								icon={ faMemory }
								className="wppo-stat-icon__spacer"
							/>
							{ __( 'Memory Usage', 'performance-optimisation' ) }
						</span>
						<span className="wppo-stat-value">
							{ cacheStatus.telemetry?.used_memory_human || '0B' }
						</span>
						<span className="wppo-text-muted">
							{ __( 'Peak:', 'performance-optimisation' ) }{ ' ' }
							{ cacheStatus.telemetry?.used_memory_peak_human ||
								'0B' }
						</span>
					</div>
					<div className="wppo-stat-item">
						<span
							className="wppo-stat-label"
							id={ hitRatioLabelId }
						>
							<FontAwesomeIcon
								icon={ faChartBar }
								className="wppo-stat-icon__spacer"
							/>
							{ __( 'Hit Ratio', 'performance-optimisation' ) }
						</span>
						<span className="wppo-stat-value">
							{ sprintf(
								/* translators: %s: hit ratio value. */
								__( '%s%%', 'performance-optimisation' ),
								hitRatio
							) }
						</span>
						<div
							className="wppo-progress-bar"
							role="progressbar"
							aria-labelledby={ hitRatioLabelId }
							aria-valuemin="0"
							aria-valuemax="100"
							aria-valuenow={ parseFloat( hitRatio ) }
							aria-valuetext={ `${ hitRatio }%` }
						>
							{ /* Width via SCSS bucket class; exact value in aria-valuetext. */ }
							<div
								className={ `wppo-progress-bar__fill wppo-progress-bar__fill--p${ hitRatioBucket }` }
							></div>
						</div>
						<span
							className="wppo-text-muted wppo-text-small"
							title={ __(
								'Cache hits vs total requests',
								'performance-optimisation'
							) }
						>
							{ sprintf(
								/* translators: 1: hit count, 2: total request count. */
								_n(
									'%1$s hit / %2$s total',
									'%1$s hits / %2$s total',
									Number(
										cacheStatus.telemetry?.keyspace_hits ||
											0
									) || 0,
									'performance-optimisation'
								),
								Number(
									cacheStatus.telemetry?.keyspace_hits || 0
								).toLocaleString(),
								(
									parseInt(
										cacheStatus.telemetry?.keyspace_hits ||
											0,
										10
									) +
									parseInt(
										cacheStatus.telemetry
											?.keyspace_misses || 0,
										10
									)
								).toLocaleString()
							) }
						</span>
					</div>
					<div className="wppo-stat-item">
						<span className="wppo-stat-label">
							<FontAwesomeIcon
								icon={ faUsers }
								className="wppo-stat-icon__spacer"
							/>
							{ __(
								'Active Clients',
								'performance-optimisation'
							) }
						</span>
						<span className="wppo-stat-value">
							{ cacheStatus.telemetry?.connected_clients || 0 }
						</span>
						<span
							className="wppo-text-muted"
							title={ __(
								'Cumulative connections handled since Redis started',
								'performance-optimisation'
							) }
						>
							{ __(
								'Total Connections:',
								'performance-optimisation'
							) }{ ' ' }
							{ Number(
								cacheStatus.telemetry
									?.total_connections_received || 0
							).toLocaleString() }
						</span>
					</div>
					<div className="wppo-stat-item">
						<span className="wppo-stat-label">
							<FontAwesomeIcon
								icon={ faServer }
								className="wppo-stat-icon__spacer"
							/>
							{ __(
								'Redis Version',
								'performance-optimisation'
							) }
						</span>
						<span className="wppo-stat-value">
							{ cacheStatus.telemetry?.redis_version ||
								__( 'N/A', 'performance-optimisation' ) }
						</span>
						<span className="wppo-text-muted">
							{ __( 'Uptime:', 'performance-optimisation' ) }{ ' ' }
							{ sprintf(
								/* translators: %s: uptime in hours. */
								__( '%s h', 'performance-optimisation' ),
								cacheStatus.telemetry?.uptime_in_seconds
									? (
											cacheStatus.telemetry
												.uptime_in_seconds / 3600
									  ).toFixed( 1 )
									: '0'
							) }
						</span>
					</div>
				</div>
			) }

			<form className="wppo-stacked-cards" onSubmit={ handleSubmit }>
				<FeatureCard
					title={ __(
						'Connection Settings',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faLink } /> }
					actions={
						connectionBadge ? (
							<span
								className={ `wppo-status-badge wppo-status-badge--${ connectionBadge.level } wppo-status-badge--sm` }
							>
								{ connectionBadge.text }
							</span>
						) : null
					}
				>
					<div className="wppo-field-group">
						<div className="wppo-field">
							<label className="wppo-field-label" htmlFor="mode">
								{ __(
									'Deployment Mode',
									'performance-optimisation'
								) }
							</label>
							<select
								className="wppo-select"
								id="mode"
								name="mode"
								value={ settings.mode }
								onChange={ handleChange( setSettings ) }
								aria-describedby="mode-desc"
							>
								<option value="standalone">
									{ __(
										'Standalone (Single Node)',
										'performance-optimisation'
									) }
								</option>
								<option value="sentinel">
									{ __(
										'Redis Sentinel (HA)',
										'performance-optimisation'
									) }
								</option>
								<option value="cluster">
									{ __(
										'Redis Cluster',
										'performance-optimisation'
									) }
								</option>
							</select>
							<p
								id="mode-desc"
								className="wppo-text-muted wppo-mt-10 wppo-text-small"
							>
								{ __(
									'Choose the Redis topology that matches your infrastructure.',
									'performance-optimisation'
								) }
							</p>
						</div>

						{ settings.mode === 'standalone' ? (
							<div className="wppo-grid-2-col wppo-mt-24">
								<div>
									<label
										className="wppo-field-label"
										htmlFor="host"
									>
										{ __(
											'Host',
											'performance-optimisation'
										) }
									</label>
									<input
										className="wppo-input wppo-input--mono"
										id="host"
										type="text"
										name="host"
										value={ settings.host }
										onChange={ handleChange( setSettings ) }
									/>
								</div>
								<div>
									<label
										className="wppo-field-label"
										htmlFor="port"
									>
										{ __(
											'Port',
											'performance-optimisation'
										) }
									</label>
									<input
										className="wppo-input wppo-input--mono"
										id="port"
										type="number"
										inputMode="numeric"
										name="port"
										value={ settings.port }
										onChange={ handleChange( setSettings ) }
									/>
								</div>
							</div>
						) : (
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="nodes"
								>
									{ __(
										'Server Nodes',
										'performance-optimisation'
									) }
								</label>
								<textarea
									className="wppo-textarea wppo-textarea--mono"
									id="nodes"
									name="nodes"
									rows="3"
									placeholder={ __(
										'host:port (one per line)',
										'performance-optimisation'
									) }
									value={ settings.nodes }
									onChange={ handleChange( setSettings ) }
								/>
								<p className="wppo-text-muted wppo-text-small wppo-mt-10">
									{ __(
										'One host:port per line. Example: 10.0.0.1:6379',
										'performance-optimisation'
									) }
								</p>
							</div>
						) }

						{ settings.mode === 'sentinel' && (
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="master_name"
								>
									{ __(
										'Sentinel Master Name',
										'performance-optimisation'
									) }
								</label>
								<input
									className="wppo-input wppo-input--mono"
									id="master_name"
									type="text"
									name="master_name"
									value={ settings.master_name }
									onChange={ handleChange( setSettings ) }
									aria-describedby="master_name-desc"
								/>
								<p
									id="master_name-desc"
									className="wppo-text-muted wppo-text-small wppo-mt-10"
								>
									{ __(
										'Name of the Redis master as configured in sentinel.conf.',
										'performance-optimisation'
									) }
								</p>
							</div>
						) }

						<div className="wppo-grid-2-col wppo-mt-24">
							<div>
								<label
									className="wppo-field-label"
									htmlFor="password"
								>
									{ __(
										'Auth Password',
										'performance-optimisation'
									) }
								</label>
								<input
									className="wppo-input"
									id="password"
									type="password"
									name="password"
									placeholder={ __(
										'Optional',
										'performance-optimisation'
									) }
									value={ settings.password }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
							<div>
								<label
									className="wppo-field-label"
									htmlFor="database"
								>
									{ __(
										'Database ID',
										'performance-optimisation'
									) }
								</label>
								<input
									className="wppo-input wppo-input--mono"
									id="database"
									type="number"
									inputMode="numeric"
									name="database"
									value={ settings.database }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						</div>

						<div className="wppo-mt-24 wppo-flex-gap-12">
							<LoadingSubmitButton
								type="button"
								className="wppo-button wppo-button--secondary"
								onClick={ () => handleAction( 'ping' ) }
								disabled={ isActionLoading }
								isLoading={ activeAction === 'ping' }
								label={
									<>
										<FontAwesomeIcon
											icon={ faNetworkWired }
										/>{ ' ' }
										{ __(
											'Test Connection',
											'performance-optimisation'
										) }
									</>
								}
							/>
							<LoadingSubmitButton
								type="submit"
								className="wppo-button wppo-button--primary"
								isLoading={ isLoading }
								label={ __(
									'Save Changes',
									'performance-optimisation'
								) }
							/>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Enterprise Performance',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faShieldAlt } /> }
				>
					<div className="wppo-field-group">
						<div>
							<label
								className="wppo-field-label"
								htmlFor="compression"
							>
								{ __(
									'Memory Compression',
									'performance-optimisation'
								) }
							</label>
							<select
								className="wppo-select"
								id="compression"
								name="compression"
								value={ settings.compression }
								onChange={ handleChange( setSettings ) }
								aria-describedby="compression-desc"
							>
								<option value="none">
									{ __(
										'None (Fastest)',
										'performance-optimisation'
									) }
								</option>
								<option
									value="lzf"
									disabled={
										cacheStatus.statusLoaded &&
										! cacheStatus.supported_compressors?.lzf
									}
								>
									{ __( 'LZF', 'performance-optimisation' ) }{ ' ' }
									{ cacheStatus.statusLoaded &&
									! cacheStatus.supported_compressors?.lzf
										? __(
												'(Disabled)',
												'performance-optimisation'
										  )
										: '' }
								</option>
								<option
									value="zstd"
									disabled={
										cacheStatus.statusLoaded &&
										! cacheStatus.supported_compressors
											?.zstd
									}
								>
									{ __( 'ZSTD', 'performance-optimisation' ) }
									{ getCompressionLabel(
										cacheStatus.statusLoaded,
										cacheStatus.supported_compressors,
										'zstd'
									) }
								</option>
								<option
									value="lz4"
									disabled={
										cacheStatus.statusLoaded &&
										! cacheStatus.supported_compressors?.lz4
									}
								>
									{ __( 'LZ4', 'performance-optimisation' ) }{ ' ' }
									{ cacheStatus.statusLoaded &&
									! cacheStatus.supported_compressors?.lz4
										? __(
												'(Disabled)',
												'performance-optimisation'
										  )
										: '' }
								</option>
							</select>
							<p
								id="compression-desc"
								className="wppo-text-muted wppo-mt-12 wppo-text-small"
							>
								{ __(
									'Reduces memory footprint for enterprise caches.',
									'performance-optimisation'
								) }
							</p>
						</div>

						<SwitchField
							label={ __(
								'Persistent Connections',
								'performance-optimisation'
							) }
							description={ __(
								'Keep connections alive between PHP requests.',
								'performance-optimisation'
							) }
							name="persistent"
							checked={ settings.persistent }
							onChange={ handleChange( setSettings ) }
						/>

						<SwitchField
							label={ __(
								'TLS / SSL Encryption',
								'performance-optimisation'
							) }
							description={ __(
								'Encrypt traffic between WordPress and Redis.',
								'performance-optimisation'
							) }
							name="use_tls"
							checked={ settings.use_tls }
							onChange={ handleChange( setSettings ) }
						/>
					</div>
				</FeatureCard>
			</form>

			{ cacheStatus.enabled && (
				<div className="wppo-feature-card wppo-danger-zone wppo-danger-zone--destructive">
					<div className="wppo-feature-card__header">
						<h3>
							<FontAwesomeIcon
								icon={ faExclamationCircle }
								className="wppo-text-danger"
							/>{ ' ' }
							{ __( 'Danger Zone', 'performance-optimisation' ) }
						</h3>
					</div>
					<div className="wppo-feature-card__body">
						<p className="wppo-text-muted wppo-text-small">
							{ __(
								'Disabling the object cache will remove the drop-in and flush all cached objects. This cannot be undone.',
								'performance-optimisation'
							) }
						</p>
					</div>
					<div className="wppo-feature-card__footer">
						<LoadingSubmitButton
							type="button"
							className="wppo-button wppo-button--danger"
							onClick={ () => setConfirmDisable( true ) }
							disabled={ isActionLoading }
							isLoading={ activeAction === 'disable' }
							label={
								<>
									<FontAwesomeIcon icon={ faTimes } />{ ' ' }
									{ __(
										'Disable Object Cache',
										'performance-optimisation'
									) }
								</>
							}
						/>
					</div>
				</div>
			) }

			<ConfirmDialog
				isOpen={ confirmDisable }
				onConfirm={ () => {
					setConfirmDisable( false );
					handleAction( 'disable' );
				} }
				onCancel={ () => setConfirmDisable( false ) }
				title={ __(
					'Disable Object Cache?',
					'performance-optimisation'
				) }
				message={ __(
					'This will remove the object-cache drop-in and clear all cached data. Are you sure?',
					'performance-optimisation'
				) }
				confirmLabel={ __( 'Disable', 'performance-optimisation' ) }
				variant="danger"
			/>
		</div>
	);
};

export default ObjectCache;
