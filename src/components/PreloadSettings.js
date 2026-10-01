import { __, sprintf } from '@wordpress/i18n';
import {
	useState,
	useEffect,
	useContext,
	useMemo,
	useCallback,
	useRef,
} from '@wordpress/element';
import { useIsMounted, runAbortable } from '../lib/useAbortableFetch';
import { handleChange } from '../lib/util';
import { apiCall, getErrorLogMessage } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import useUnsavedChanges from '../lib/useUnsavedChanges';
import UnsavedChangesContext from '../lib/UnsavedChangesContext';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import SwitchField from './common/SwitchField';
import SettingField from './common/SettingField';
import SettingRow from './common/SettingRow';
import { subjectFor } from './preload/subjects';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faHourglassStart,
	faLink,
	faFont,
	faRocket,
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import NoticeBanner from './common/NoticeBanner';

const PreloadSettings = ( { options = {} } ) => {
	const defaultSettings = {
		enablePreloadCache: false,
		excludePreloadCache: 'my-account/(.*)\ncart/(.*)\ncheckout/(.*)',
		preloadSitemap: false,
		preconnect: false,
		preconnectOrigins: '',
		prefetchDNS: false,
		dnsPrefetchOrigins: '',
		preloadFonts: false,
		preloadFontsUrls: '',
		autoDiscoverFonts: false,
		autoLcpPreload: false,
		preloadCSS: false,
		preloadCSSUrls: '',
		enableSpeculationRules: false,
		speculationMode: 'prefetch',
		speculationEagerness: 'conservative',
		speculationExcludeUrls: '',
		speculationRumGating: true,
		speculationTopUrlsLimit: 2,
		speculationPrerenderList: false,
		...options,
	};

	const [ settings, setSettings ] = useState( defaultSettings );
	const [ isLoading, setIsLoading ] = useState( false );
	const { notice, notify, dismiss } = useNotice();
	const { setIsDirty } = useContext( UnsavedChangesContext );
	const [ baseline, setBaseline ] = useState( defaultSettings );
	const [ preload, setPreload ] = useState( null );
	const [ cacheCap, setCacheCap ] = useState( null );
	const [ isResuming, setIsResuming ] = useState( false );
	// Keep baseline in sync with incoming options on mount / prop change.
	// Per-key deps (not object identity) so parent re-renders with an
	// identical payload do not reset the baseline.
	// Keep baseline + form in sync with incoming options on mount / prop
	// change (audit #1401: was two effects with hand-duplicated 20-key
	// dep arrays — one array now, so a new key cannot update one sync
	// and miss the other). Per-key deps (not object identity) so parent
	// re-renders with an identical payload do not reset state.
	useEffect( () => {
		setBaseline( { ...defaultSettings, ...options } );
		if ( options && Object.keys( options ).length > 0 ) {
			setSettings( ( prev ) => ( { ...prev, ...options } ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		options.enablePreloadCache,
		options.excludePreloadCache,
		options.preloadSitemap,
		options.preconnect,
		options.preconnectOrigins,
		options.prefetchDNS,
		options.dnsPrefetchOrigins,
		options.preloadFonts,
		options.preloadFontsUrls,
		options.autoDiscoverFonts,
		options.autoLcpPreload,
		options.preloadCSS,
		options.preloadCSSUrls,
		options.enableSpeculationRules,
		options.speculationMode,
		options.speculationEagerness,
		options.speculationExcludeUrls,
		options.speculationRumGating,
		options.speculationTopUrlsLimit,
		options.speculationPrerenderList,
	] );
	useUnsavedChanges( settings, baseline );

	// Audit #1354: single memoized change handler instead of a new
	// closure per input per render (mirrors FileOptimization).
	const onFieldChange = useMemo(
		() => handleChange( setSettings ),
		[ setSettings ]
	);

	// Audit #1401: shared abortable helper (useAbortableFetch) owns
	// controller lifecycle + cancellation instead of ad-hoc flags.
	const isMountedRef = useIsMounted();
	const resumeControllerRef = useRef( null );
	const saveControllerRef = useRef( null );
	useEffect( () => {
		return () => {
			if ( resumeControllerRef.current ) {
				resumeControllerRef.current.abort();
			}
			if ( saveControllerRef.current ) {
				saveControllerRef.current.abort();
			}
		};
	}, [] );
	// Audit #1420: useCallback so the mount effect lists exhaustive deps.
	const fetchPreloadStatus = useCallback(
		async ( signal ) => {
			try {
				const res = await apiCall(
					'preload_status',
					{},
					'GET',
					signal
				);
				if ( ! isMountedRef.current || ( signal && signal.aborted ) ) {
					return;
				}
				const payload = res && res.data ? res.data : res;
				if ( payload && payload.preload ) {
					setPreload( payload.preload );
				}
				if ( payload && payload.cache ) {
					setCacheCap( payload.cache );
				}
			} catch ( err ) {
				console.error(
					'Failed fetching preload status',
					getErrorLogMessage( err )
				);
			}
		},
		[ isMountedRef ]
	);

	useEffect( () => {
		const { cancel } = runAbortable( ( signal ) =>
			fetchPreloadStatus( signal ).catch( ( fetchError ) => {
				// Audit #1420: log instead of swallowing (inner handler
				// already fails open; this covers abort rejections).
				if ( fetchError?.name !== 'AbortError' ) {
					console.error( 'Preload status fetch failed:', fetchError );
				}
			} )
		);
		return cancel;
	}, [ fetchPreloadStatus ] );

	const handleResume = async () => {
		if ( isResuming ) {
			return;
		}
		if ( resumeControllerRef.current ) {
			resumeControllerRef.current.abort();
		}
		resumeControllerRef.current = new AbortController();
		const signal = resumeControllerRef.current.signal;
		setIsResuming( true );
		try {
			const res = await apiCall( 'preload_resume', {}, 'POST', signal );
			if ( signal.aborted || ! isMountedRef.current ) {
				return;
			}
			// Audit #1354: only celebrate actual success.
			if ( ! res.success ) {
				throw new Error(
					res.message ||
						__(
							'Failed resuming preload queue.',
							'performance-optimisation'
						)
				);
			}
			const payload = res && res.data ? res.data : res;
			if ( payload && payload.preload ) {
				setPreload( payload.preload );
			} else {
				await fetchPreloadStatus( signal );
			}
			if ( signal.aborted || ! isMountedRef.current ) {
				return;
			}
			notify( {
				type: 'success',
				message:
					res.message ||
					__( 'Preload queue resumed.', 'performance-optimisation' ),
				durationMs: 5000,
			} );
		} catch ( err ) {
			if ( signal.aborted || err?.name === 'AbortError' ) {
				return;
			}
			if ( ! isMountedRef.current ) {
				return;
			}
			console.error(
				'Failed resuming preload queue',
				getErrorLogMessage( err )
			);
			notify( {
				type: 'error',
				message:
					( err && err.message ) ||
					__(
						'An unexpected error occurred.',
						'performance-optimisation'
					),
				durationMs: 5000,
			} );
		} finally {
			resumeControllerRef.current = null;
			if ( ! signal.aborted && isMountedRef.current ) {
				setIsResuming( false );
			}
		}
	};

	const speculationRules =
		typeof wppoSettings !== 'undefined'
			? wppoSettings.speculation_rules || {}
			: {};
	const eagernessOverride = speculationRules.eagerness_override || null;
	const modeOverride = speculationRules.mode_override || null;
	const staticCacheActive = speculationRules.static_cache_active || false;

	const handleSubmit = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		if ( isLoading ) {
			return;
		}
		if ( saveControllerRef.current ) {
			saveControllerRef.current.abort();
		}
		saveControllerRef.current = new AbortController();
		const submitSignal = saveControllerRef.current.signal;
		setIsLoading( true );
		dismiss();

		try {
			const res = await apiCall(
				'update_settings',
				{
					tab: 'preload_settings',
					settings,
				},
				'POST',
				submitSignal
			);
			if ( submitSignal.aborted || ! isMountedRef.current ) {
				return;
			}

			if ( res.success ) {
				setBaseline( { ...settings } );
				setIsDirty( false );
				notify( {
					type: 'success',
					message:
						res.message ||
						__(
							'Settings updated successfully.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Failed to update settings.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( err ) {
			if ( submitSignal.aborted || err?.name === 'AbortError' ) {
				return;
			}
			if ( ! isMountedRef.current ) {
				return;
			}
			console.error(
				'Failed updating preload settings',
				getErrorLogMessage( err )
			);
			notify( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			saveControllerRef.current = null;
			if ( ! submitSignal.aborted && isMountedRef.current ) {
				setIsLoading( false );
			}
		}
	};

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'Preload Settings', 'performance-optimisation' ) }
				description={ __(
					'Improve perceived performance by pre-connecting to domains and preloading critical assets.',
					'performance-optimisation'
				) }
				actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						isLoading={ isLoading }
						onClick={ handleSubmit }
						label={ __(
							'Save Settings',
							'performance-optimisation'
						) }
					/>
				}
			>
				{ notice && (
					<NoticeBanner
						type={ notice.type }
						message={ notice.message }
						className="wppo-mb-20"
						onDismiss={ dismiss }
					/>
				) }
			</FeatureHeader>

			<form onSubmit={ handleSubmit } className="wppo-stacked-cards">
				<FeatureCard
					title={ __( 'Cache Warm-up', 'performance-optimisation' ) }
					icon={ <FontAwesomeIcon icon={ faHourglassStart } /> }
				>
					<div className="wppo-field-group">
						<SettingRow
							subject={ subjectFor(
								'enablePreloadCache',
								settings
							) }
						>
							<SwitchField
								label={ __(
									'Enable Preload Cache',
									'performance-optimisation'
								) }
								description={ __(
									'Automatically visit all pages to pre-generate the cache. The first real visitor gets a fast cached response instead of waiting for a cold page build.',
									'performance-optimisation'
								) }
								name="enablePreloadCache"
								checked={ settings.enablePreloadCache }
								onChange={ onFieldChange }
							/>
						</SettingRow>
						{ settings.enablePreloadCache && (
							<SettingField
								name="excludePreloadCache"
								type="textarea"
								label={ __(
									'Exclude URLs from Cache Warm-up',
									'performance-optimisation'
								) }
								description={ __(
									'One URL or path per line. Use this for a search page, a cart, or anything that shows one person one thing.',
									'performance-optimisation'
								) }
								rows={ 3 }
								mono
								nested
								subject={ subjectFor(
									'excludePreloadCache',
									settings
								) }
								value={ settings.excludePreloadCache }
								onChange={ onFieldChange }
							/>
						) }
						<SwitchField
							label={ __(
								'Preload from Sitemap',
								'performance-optimisation'
							) }
							description={ __(
								'Also warm up URLs discovered in your sitemap, so pages outside standard post queries get cached too.',
								'performance-optimisation'
							) }
							name="preloadSitemap"
							checked={ settings.preloadSitemap }
							onChange={ onFieldChange }
						/>
						{ cacheCap && 'warn' === cacheCap.state && (
							<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
								{ __(
									'Cache is approaching its cap (size or file count). Oldest entries will be evicted once the cap is reached.',
									'performance-optimisation'
								) }
							</p>
						) }
						{ cacheCap && 'over' === cacheCap.state && (
							<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
								{ __(
									'Cache cap reached (size or file count). Enforcing by evicting oldest entries.',
									'performance-optimisation'
								) }
							</p>
						) }
						{ cacheCap && typeof cacheCap.files !== 'undefined' && (
							<p className="wppo-text-muted wppo-text-small">
								{ sprintf(
									/* translators: 1: cached file count, 2: file-count cap. */
									__(
										'Cached files: %1$d of %2$d.',
										'performance-optimisation'
									),
									cacheCap.files || 0,
									cacheCap.cap_files || 0
								) }
							</p>
						) }
						{ preload && (
							<div className="wppo-field wppo-mt-20">
								<p className="wppo-text-muted wppo-text-small">
									{ sprintf(
										/* translators: 1: queued count, 2: done count, 3: failed count. Audit #1420: count-independent string, no _n. */
										__(
											'Preload progress — queued: %1$d, done: %2$d, failed: %3$d.',
											'performance-optimisation'
										),
										preload.queued || 0,
										preload.done || 0,
										preload.failed || 0
									) }
								</p>
								{ !! preload.stalled && (
									<p className="wppo-text-muted wppo-text-small">
										{ __(
											'Preload looks stalled (no progress for 30+ minutes). Resume to retry pending URLs.',
											'performance-optimisation'
										) }
									</p>
								) }
								{ ( preload.queued > 0 ||
									preload.failed > 0 ||
									preload.stalled ) && (
									<LoadingSubmitButton
										className="wppo-button wppo-button--secondary"
										isLoading={ isResuming }
										onClick={ handleResume }
										type="button"
										label={ __(
											'Resume Preload',
											'performance-optimisation'
										) }
									/>
								) }
							</div>
						) }
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Third-Party Connections',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faLink } /> }
				>
					<div className="wppo-field-group">
						<SettingRow
							subject={ subjectFor( 'preconnect', settings ) }
						>
							<SwitchField
								label={ __(
									'Preconnect',
									'performance-optimisation'
								) }
								description={ __(
									'Open a TCP/TLS connection to third-party origins before the browser needs them. Eliminates connection setup latency for fonts, analytics, and CDN resources.',
									'performance-optimisation'
								) }
								name="preconnect"
								checked={ settings.preconnect }
								onChange={ onFieldChange }
							/>
						</SettingRow>
						{ settings.preconnect && (
							<SettingField
								name="preconnectOrigins"
								type="textarea"
								label={ __(
									'Preconnect Origins',
									'performance-optimisation'
								) }
								description={ __(
									'One full origin per line, including the scheme. List only hosts that are genuinely on the critical path.',
									'performance-optimisation'
								) }
								rows={ 3 }
								mono
								nested
								subject={ subjectFor(
									'preconnectOrigins',
									settings
								) }
								value={ settings.preconnectOrigins }
								onChange={ onFieldChange }
							/>
						) }

						<SettingRow
							subject={ subjectFor( 'prefetchDNS', settings ) }
						>
							<SwitchField
								label={ __(
									'DNS Prefetch',
									'performance-optimisation'
								) }
								description={ __(
									'Resolve domain names in the background before the browser requests resources from them. Faster than preconnect but only handles DNS — useful for domains you do not need a full connection to immediately.',
									'performance-optimisation'
								) }
								name="prefetchDNS"
								checked={ settings.prefetchDNS }
								onChange={ onFieldChange }
							/>
						</SettingRow>
						{ settings.prefetchDNS && (
							<SettingField
								name="dnsPrefetchOrigins"
								type="textarea"
								label={ __(
									'DNS Prefetch Origins',
									'performance-optimisation'
								) }
								description={ __(
									'One origin per line, including the scheme.',
									'performance-optimisation'
								) }
								rows={ 3 }
								mono
								nested
								subject={ subjectFor(
									'dnsPrefetchOrigins',
									settings
								) }
								value={ settings.dnsPrefetchOrigins }
								onChange={ onFieldChange }
							/>
						) }
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Critical Assets Preloading',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faFont } /> }
				>
					<div className="wppo-stacked-cards">
						<div className="wppo-field-group">
							<SettingRow
								subject={ subjectFor(
									'preloadFonts',
									settings
								) }
							>
								<SwitchField
									label={ __(
										'Preload Fonts',
										'performance-optimisation'
									) }
									description={ __(
										'Inject preload hints for critical font files so the browser fetches them at the highest priority. Eliminates the flash of invisible text (FOIT) on first load.',
										'performance-optimisation'
									) }
									name="preloadFonts"
									checked={ settings.preloadFonts }
									onChange={ onFieldChange }
								/>
							</SettingRow>
							{ settings.preloadFonts && (
								<SettingField
									name="preloadFontsUrls"
									type="textarea"
									label={ __(
										'Font URLs to Preload',
										'performance-optimisation'
									) }
									description={ __(
										'One URL per line, and prefer the .woff2 format — it is the one every current browser understands.',
										'performance-optimisation'
									) }
									rows={ 3 }
									mono
									nested
									subject={ subjectFor(
										'preloadFontsUrls',
										settings
									) }
									value={ settings.preloadFontsUrls }
									onChange={ onFieldChange }
								/>
							) }
						</div>
						<div className="wppo-field-group">
							<SettingRow
								subject={ subjectFor(
									'autoDiscoverFonts',
									settings
								) }
							>
								<SwitchField
									label={ __(
										'Automatically Discover Fonts',
										'performance-optimisation'
									) }
									description={ __(
										'Scan enqueued stylesheets for @font-face files and preload up to 2 same-origin fonts with crossorigin. Manual font URLs always win on conflict.',
										'performance-optimisation'
									) }
									name="autoDiscoverFonts"
									checked={ settings.autoDiscoverFonts }
									onChange={ onFieldChange }
								/>
							</SettingRow>
							<SettingRow
								subject={ subjectFor(
									'autoLcpPreload',
									settings
								) }
							>
								<SwitchField
									label={ __(
										'Automatically Preload LCP Hero',
										'performance-optimisation'
									) }
									description={ __(
										'Resolve the template hero from real-visit data, then stored PageSpeed data, then the in-viewport heuristic, and emit one fetchpriority-high eager preload. Requires Real-User Measurement; manual preload lists win and the hero is never lazy-loaded.',
										'performance-optimisation'
									) }
									name="autoLcpPreload"
									checked={ settings.autoLcpPreload }
									onChange={ onFieldChange }
								/>
							</SettingRow>
						</div>
						<div className="wppo-field-group">
							<SettingRow
								subject={ subjectFor( 'preloadCSS', settings ) }
							>
								<SwitchField
									label={ __(
										'Preload Critical CSS',
										'performance-optimisation'
									) }
									description={ __(
										'Inject preload hints for above-the-fold stylesheets. Ensures critical styles are fetched before the browser renders the page, reducing render-blocking delays.',
										'performance-optimisation'
									) }
									name="preloadCSS"
									checked={ settings.preloadCSS }
									onChange={ onFieldChange }
								/>
							</SettingRow>
							{ settings.preloadCSS && (
								<SettingField
									name="preloadCSSUrls"
									type="textarea"
									label={ __(
										'CSS URLs to Preload',
										'performance-optimisation'
									) }
									description={ __(
										'One URL per line, as they appear in your page markup.',
										'performance-optimisation'
									) }
									rows={ 3 }
									mono
									nested
									subject={ subjectFor(
										'preloadCSSUrls',
										settings
									) }
									value={ settings.preloadCSSUrls }
									onChange={ onFieldChange }
								/>
							) }
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Speculative Loading',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faRocket } /> }
				>
					<div className="wppo-field-group">
						<p className="wppo-text-muted wppo-text-small">
							{ __(
								'Effective defaults are prefetch + conservative unless overridden. Host overrides WP_SPECULATIVE_LOADING_DEFAULT_MODE / WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS are superseded by the wp_speculation_rules_configuration filter — this plugin takes precedence when enabled. No auto-elevation to moderate occurs; choose eagerness explicitly.',
								'performance-optimisation'
							) }
						</p>
						<SettingRow
							subject={ subjectFor(
								'enableSpeculationRules',
								settings
							) }
						>
							<SwitchField
								label={ __(
									'Enable Speculative Loading',
									'performance-optimisation'
								) }
								description={ __(
									'When enabled, browsers may prerender or prefetch linked pages before navigation for near-instant load times.',
									'performance-optimisation'
								) }
								name="enableSpeculationRules"
								checked={ settings.enableSpeculationRules }
								onChange={ onFieldChange }
							/>
						</SettingRow>
						{ ! settings.enableSpeculationRules &&
							( eagernessOverride || modeOverride ) && (
								<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
									{ sprintf(
										/* translators: %1$s: Comma-separated list of pinned WP_SPECULATIVE_LOADING_DEFAULT_* constants/environment variables. */
										__(
											'WordPress core\u2019s speculation-rules default is pinned via %1$s. This plugin respects that pinned default whenever speculative loading is disabled here.',
											'performance-optimisation'
										),
										[
											eagernessOverride &&
												`WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS (${ eagernessOverride })`,
											modeOverride &&
												`WP_SPECULATIVE_LOADING_DEFAULT_MODE (${ modeOverride })`,
										]
											.filter( Boolean )
											.join( ', ' )
									) }
								</p>
							) }
						{ ! settings.enableSpeculationRules &&
							! eagernessOverride &&
							! modeOverride &&
							staticCacheActive && (
								<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
									{ __(
										'While speculative loading is disabled here, this plugin keeps WordPress\u2019s conservative eagerness default. If WordPress core escalates that default (for example, to moderate) when it detects a caching solution, this plugin keeps your preference from being overridden. To pin a different default, set the WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS constant or environment variable (for example, to "moderate").',
										'performance-optimisation'
									) }
								</p>
							) }
						{ settings.enableSpeculationRules && (
							<>
								<SettingRow
									subject={ subjectFor(
										'speculationMode',
										settings
									) }
								>
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="speculationMode"
										>
											{ __(
												'Speculation Mode',
												'performance-optimisation'
											) }
										</label>
										<select
											className="wppo-select"
											id="speculationMode"
											name="speculationMode"
											value={ settings.speculationMode }
											onChange={ onFieldChange }
											aria-describedby="speculationMode-desc"
										>
											<option value="prefetch">
												{ __(
													'Prefetch (faster navigation, lower resource usage)',
													'performance-optimisation'
												) }
											</option>
											<option value="prerender">
												{ __(
													'Prerender (instant navigation, higher resource usage)',
													'performance-optimisation'
												) }
											</option>
										</select>
										<p
											id="speculationMode-desc"
											className="wppo-text-muted wppo-mt-10 wppo-text-small"
										>
											{ __(
												'Prerender executes JavaScript on hover and may inflate analytics and origin load on uncached routes — use only with caching verified.',
												'performance-optimisation'
											) }
										</p>
									</div>
								</SettingRow>
								<SettingRow
									subject={ subjectFor(
										'speculationEagerness',
										settings
									) }
								>
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="speculationEagerness"
										>
											{ __(
												'Eagerness',
												'performance-optimisation'
											) }
										</label>
										<select
											className="wppo-select"
											id="speculationEagerness"
											name="speculationEagerness"
											value={
												settings.speculationEagerness
											}
											onChange={ onFieldChange }
											aria-describedby="speculationEagerness-desc"
										>
											<option value="conservative">
												{ __(
													'Conservative (only on hover/touch)',
													'performance-optimisation'
												) }
											</option>
											<option value="moderate">
												{ __(
													'Moderate (on hover + nearby links)',
													'performance-optimisation'
												) }
											</option>
											<option value="eager">
												{ __(
													'Eager (immediately on page load)',
													'performance-optimisation'
												) }
											</option>
										</select>
										<p
											id="speculationEagerness-desc"
											className="wppo-text-muted wppo-mt-10 wppo-text-small"
										>
											{ __(
												'Conservative waits for hover, Moderate prefetches nearby links, Eager loads immediately on page load.',
												'performance-optimisation'
											) }
										</p>
									</div>
								</SettingRow>
								<div className="wppo-field-group">
									<SettingRow
										subject={ subjectFor(
											'speculationPrerenderList',
											settings
										) }
									>
										<SwitchField
											label={ __(
												'Prerender High-Value URLs',
												'performance-optimisation'
											) }
											description={ __(
												'Prerender the home page plus top visited URLs for near-instant navigation. Only safe same-origin pages are prerendered; cart, checkout, account, and logged-in views stay on prefetch or nothing.',
												'performance-optimisation'
											) }
											name="speculationPrerenderList"
											checked={ Boolean(
												settings.speculationPrerenderList
											) }
											onChange={ onFieldChange }
										/>
									</SettingRow>
								</div>
								<SettingField
									name="speculationExcludeUrls"
									type="textarea"
									label={ __(
										'Exclude URLs from Speculation',
										'performance-optimisation'
									) }
									description={ __(
										'One URL or path per line. A plain substring matches anything containing it; #regex# matches exactly that pattern.',
										'performance-optimisation'
									) }
									rows={ 3 }
									mono
									nested
									subject={ subjectFor(
										'speculationExcludeUrls',
										settings
									) }
									value={ settings.speculationExcludeUrls }
									onChange={ onFieldChange }
								/>
							</>
						) }
					</div>
				</FeatureCard>
			</form>
		</div>
	);
};

export default PreloadSettings;
