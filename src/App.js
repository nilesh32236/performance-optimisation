import {
	useState,
	useEffect,
	useRef,
	useMemo,
	useCallback,
	lazy,
	Suspense,
} from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import ConfirmDialog from './components/common/ConfirmDialog';
import UnsavedChangesContext from './lib/UnsavedChangesContext';
import {
	faTachometerAlt,
	faFileCode,
	faBullseye,
	faImages,
	faDatabase,
	faTools,
	faBars,
	faTimes,
	faServer,
	faBolt,
	faSpinner,
} from '@fortawesome/free-solid-svg-icons';
import {
	apiCall,
	fetchRecentActivities,
	fetchServerRules,
	getErrorLogMessage,
	getWppoSettings,
} from './lib/apiRequest';
import ErrorBoundary from './components/common/ErrorBoundary';

import { __ } from '@wordpress/i18n';

const Dashboard = lazy( () =>
	import( /* webpackChunkName: "tab-dashboard" */ './components/Dashboard' )
);
const FileOptimization = lazy( () =>
	import(
		/* webpackChunkName: "tab-file-optimization" */ './components/FileOptimization'
	)
);
const PreloadSettings = lazy( () =>
	import(
		/* webpackChunkName: "tab-preload-settings" */ './components/PreloadSettings'
	)
);
const ImageOptimization = lazy( () =>
	import(
		/* webpackChunkName: "tab-image-optimization" */ './components/ImageOptimization'
	)
);
const DatabaseCleanup = lazy( () =>
	import(
		/* webpackChunkName: "tab-database-cleanup" */ './components/DatabaseCleanup'
	)
);
const ObjectCache = lazy( () =>
	import(
		/* webpackChunkName: "tab-object-cache" */ './components/ObjectCache'
	)
);
const PluginSettings = lazy( () =>
	import(
		/* webpackChunkName: "tab-plugin-setting" */ './components/PluginSetting'
	)
);

// Audit #1420: announced loading state.
const TabFallback = () => (
	<div
		className="wppo-loading-placeholder wppo-loading-placeholder--fallback"
		role="status"
		aria-live="polite"
	>
		<FontAwesomeIcon icon={ faSpinner } spin aria-hidden="true" />
		<span>{ __( 'Loading…', 'performance-optimisation' ) }</span>
	</div>
);

const SIDEBAR_BREAKPOINT = 992;

/**
 * Whether a server-provided theme color is safe to pass into the
 * style.setProperty CSS sink. Only plain hex colors are accepted; anything
 * else (url(), expression(), semicolons, overlong strings) is skipped.
 *
 * @since 2.3.0
 * @param {*} value Raw theme color value.
 * @return {boolean} True when the value is a safe hex color.
 */
export const isSafeCssColor = ( value ) =>
	typeof value === 'string' &&
	/^#[0-9a-fA-F]{3,4}$|^#[0-9a-fA-F]{6}$|^#[0-9a-fA-F]{8}$/.test( value );

const App = () => {
	const [ activeTab, setActiveTab ] = useState( 'dashboard' );
	const [ transition, setTransition ] = useState( false );
	const [ mobileMenuOpen, setMobileMenuOpen ] = useState( false );
	const [ recentActivities, setRecentActivities ] = useState( [] );
	const [ activitiesError, setActivitiesError ] = useState( false );
	const [ serverRules, setServerRules ] = useState( null );
	const [ serverRulesError, setServerRulesError ] = useState( false );
	const [ rulesRetryTrigger, setRulesRetryTrigger ] = useState( 0 );
	const [ ccssStatus, setCcssStatus ] = useState( {} );
	const [ ccssError, setCcssError ] = useState( false );
	const [ ccssRefreshTrigger, setCcssRefreshTrigger ] = useState( 0 );
	const [ isDirty, setIsDirty ] = useState( false );
	const [ pendingTab, setPendingTab ] = useState( null );
	const [ showGuard, setShowGuard ] = useState( false );
	const hasFetchedActivities = useRef( false );
	const hasFetchedRules = useRef( false );
	const hasFetchedCcss = useRef( false );

	const activitiesControllerRef = useRef( null );
	const rulesControllerRef = useRef( null );
	const ccssControllerRef = useRef( null );

	const sidebarRef = useRef( null );
	const toggleBtnRef = useRef( null );

	const sidebarItems = useMemo(
		() => [
			{
				name: 'dashboard',
				icon: faTachometerAlt,
				label: __( 'Dashboard', 'performance-optimisation' ),
			},
			{
				name: 'fileOptimization',
				icon: faFileCode,
				label: __( 'File Optimisation', 'performance-optimisation' ),
			},
			{
				name: 'preload',
				icon: faBullseye,
				label: __( 'Preload', 'performance-optimisation' ),
			},
			{
				name: 'imageOptimization',
				icon: faImages,
				label: __( 'Image Optimisation', 'performance-optimisation' ),
			},
			{
				name: 'databaseCleanup',
				icon: faDatabase,
				label: __( 'Database', 'performance-optimisation' ),
			},
			{
				name: 'objectCache',
				icon: faServer,
				label: __( 'Object Cache', 'performance-optimisation' ),
			},
			{
				name: 'tools',
				icon: faTools,
				label: __( 'Tools', 'performance-optimisation' ),
			},
		],
		[]
	);

	const handleTabChange = useCallback(
		( nextTab ) => {
			if ( isDirty && nextTab !== activeTab ) {
				setPendingTab( nextTab );
				setShowGuard( true );
				return;
			}
			setActiveTab( nextTab );
			setMobileMenuOpen( false );
		},
		[ isDirty, activeTab ]
	);

	const confirmDiscard = useCallback( () => {
		setShowGuard( false );
		setIsDirty( false );
		if ( pendingTab ) {
			setActiveTab( pendingTab );
			setMobileMenuOpen( false );
			setPendingTab( null );
		}
	}, [ pendingTab ] );

	const cancelGuard = useCallback( () => {
		setShowGuard( false );
		setPendingTab( null );
	}, [] );

	// Block browser unload when dirty — browsers show generic confirmation.
	useEffect( () => {
		if ( ! isDirty ) {
			return;
		}
		const handler = ( e ) => {
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener( 'beforeunload', handler );
		return () => window.removeEventListener( 'beforeunload', handler );
	}, [ isDirty ] );

	// Audit #1354 review: stable callbacks so heavy tabs keep prop
	// identity across App renders.
	const handleRetryRules = useCallback( () => {
		hasFetchedRules.current = false;
		setServerRulesError( false );
		setServerRules( null );
		setRulesRetryTrigger( ( c ) => c + 1 );
	}, [] );
	const handleCcssRefresh = useCallback( () => {
		hasFetchedCcss.current = false;
		setCcssError( false );
		setCcssRefreshTrigger( ( c ) => c + 1 );
	}, [] );

	const renderContent = () => {
		const settings =
			typeof wppoSettings !== 'undefined'
				? wppoSettings?.settings ?? {}
				: {};
		const components = {
			dashboard: (
				<Dashboard
					activities={ recentActivities?.activities }
					activitiesError={ activitiesError }
					cacheSettings={ settings.cache_settings }
					userRoles={
						typeof wppoSettings !== 'undefined'
							? wppoSettings?.userRoles ?? {}
							: {}
					}
					onNavigate={ handleTabChange }
				/>
			),
			fileOptimization: (
				<FileOptimization
					options={ settings.file_optimisation }
					serverRules={ serverRules }
					serverRulesError={ serverRulesError }
					ccssStatus={ ccssStatus }
					ccssError={ ccssError }
					onRetryServerRules={ handleRetryRules }
					onCcssRefresh={ handleCcssRefresh }
					onCcssRetry={ handleCcssRefresh }
				/>
			),
			preload: <PreloadSettings options={ settings.preload_settings } />,
			imageOptimization: (
				<ImageOptimization options={ settings.image_optimisation } />
			),
			databaseCleanup: (
				<DatabaseCleanup options={ settings.database_cleanup } />
			),
			objectCache: <ObjectCache options={ settings.object_cache } />,
			tools: <PluginSettings options={ settings } />,
		};

		const activeComponent = components[ activeTab ] || components.dashboard;

		return (
			<Suspense fallback={ <TabFallback /> }>
				{ activeComponent }
			</Suspense>
		);
	};

	const toggleMobileMenu = () =>
		setMobileMenuOpen( ( prevState ) => ! prevState );

	useEffect( () => {
		const handleResize = () => {
			if ( window.innerWidth >= SIDEBAR_BREAKPOINT ) {
				setMobileMenuOpen( false );
			}
		};

		window.addEventListener( 'resize', handleResize );
		return () => window.removeEventListener( 'resize', handleResize );
	}, [] );

	// Focus trap for mobile sidebar.
	useEffect( () => {
		if ( ! mobileMenuOpen ) {
			return;
		}

		const sidebar = sidebarRef.current;
		const toggleBtn = toggleBtnRef.current;
		if ( ! sidebar ) {
			return;
		}

		const focusable = sidebar.querySelectorAll(
			'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
		);
		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];
		const doc = sidebar.ownerDocument;

		if ( first ) {
			first.focus();
		}

		// Audit #1420: document-level trap (overlay button + outside focus
		// cannot escape) with Escape-to-close returning focus to the toggle.
		const handleKeyDown = ( e ) => {
			if ( e.key === 'Escape' ) {
				setMobileMenuOpen( false );
				if ( toggleBtn ) {
					toggleBtn.focus();
				}
				return;
			}
			if ( e.key !== 'Tab' ) {
				return;
			}
			if ( ! sidebar.contains( doc.activeElement ) ) {
				e.preventDefault();
				if ( first ) {
					first.focus();
				}
				return;
			}
			if ( ! first || ! last ) {
				return;
			}
			if ( e.shiftKey && doc.activeElement === first ) {
				e.preventDefault();
				last.focus();
				return;
			}
			if ( ! e.shiftKey && doc.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		};

		doc.addEventListener( 'keydown', handleKeyDown );

		return () => {
			doc.removeEventListener( 'keydown', handleKeyDown );
			if ( toggleBtn ) {
				toggleBtn.focus();
			}
		};
	}, [ mobileMenuOpen ] );

	// Inject frontend theme accent colors as CSS custom properties.
	// Server-provided values are validated against a strict hex-color
	// pattern before reaching the setProperty CSS sink so a tampered
	// settings payload cannot inject arbitrary declarations (e.g. url()
	// exfiltration) into the admin page.
	useEffect( () => {
		const themeColors = getWppoSettings()?.themeColors;
		if ( ! themeColors ) {
			return;
		}

		const root = document.documentElement;
		if ( isSafeCssColor( themeColors.primary ) ) {
			root.style.setProperty(
				'--wppo-frontend-primary',
				themeColors.primary
			);
		}
		if ( isSafeCssColor( themeColors.secondary ) ) {
			root.style.setProperty(
				'--wppo-frontend-secondary',
				themeColors.secondary
			);
		}
		if ( isSafeCssColor( themeColors.text ) ) {
			root.style.setProperty( '--wppo-frontend-text', themeColors.text );
		}
	}, [] );

	useEffect( () => {
		// Audit #1354: only (re)create a controller for a fetch that will
		// actually run — the hasFetched guards below make the other two
		// no-ops, and aborting their controllers would kill unrelated
		// in-flight requests on every tab change.
		const wantActivities =
			( activeTab === 'overview' ||
				activeTab === 'dashboard' ||
				recentActivities.length === 0 ) &&
			! hasFetchedActivities.current;
		const wantRules = ! serverRules && ! hasFetchedRules.current;
		const wantCcss = ! hasFetchedCcss.current || 0 !== ccssRefreshTrigger;
		const refreshController = ( ref ) => {
			if ( ref.current ) {
				ref.current.abort();
			}
			ref.current = new AbortController();
			return ref.current;
		};
		const activitiesController = wantActivities
			? refreshController( activitiesControllerRef )
			: activitiesControllerRef.current;
		const rulesController = wantRules
			? refreshController( rulesControllerRef )
			: rulesControllerRef.current;
		const ccssController = wantCcss
			? refreshController( ccssControllerRef )
			: ccssControllerRef.current;

		const fetchActivities = async () => {
			if (
				! (
					( activeTab === 'overview' ||
						activeTab === 'dashboard' ||
						recentActivities.length === 0 ) &&
					! hasFetchedActivities.current
				)
			) {
				return;
			}
			try {
				const data = await fetchRecentActivities(
					1,
					activitiesController.signal
				);
				if ( ! activitiesController.signal.aborted ) {
					setRecentActivities( data );
					setActivitiesError( false );
					hasFetchedActivities.current = true;
				}
			} catch ( error ) {
				if ( ! activitiesController.signal.aborted ) {
					setActivitiesError( true );
					console.error(
						__(
							'Failed to fetch activities:',
							'performance-optimisation'
						),
						getErrorLogMessage( error )
					);
				}
			}
		};

		const fetchRules = async () => {
			if ( serverRules || hasFetchedRules.current ) {
				return;
			}
			hasFetchedRules.current = true;
			try {
				const res = await fetchServerRules( rulesController.signal );
				if ( ! rulesController.signal.aborted ) {
					if ( res.success ) {
						setServerRules( res.data );
						setServerRulesError( false );
					} else {
						hasFetchedRules.current = false;
						setServerRulesError( true );
					}
				} else {
					hasFetchedRules.current = false;
				}
			} catch ( rulesError ) {
				hasFetchedRules.current = false;
				if ( ! rulesController.signal.aborted ) {
					console.error(
						'Failed fetching server rules',
						getErrorLogMessage( rulesError )
					);
					setServerRulesError( true );
				}
			}
		};

		const fetchCcssStatus = async () => {
			if ( hasFetchedCcss.current && 0 === ccssRefreshTrigger ) {
				return;
			}
			hasFetchedCcss.current = true;
			try {
				const res = await apiCall(
					'ccss_status',
					{},
					'GET',
					ccssController.signal
				);
				if ( ccssController.signal.aborted ) {
					hasFetchedCcss.current = false;
					return;
				}
				if ( res.success ) {
					setCcssStatus( res.data );
					setCcssError( false );
				} else {
					hasFetchedCcss.current = false;
					setCcssError( true );
				}
			} catch ( ccssFetchError ) {
				if ( ! ccssController.signal.aborted ) {
					console.error(
						'Failed fetching CCSS status',
						getErrorLogMessage( ccssFetchError )
					);
					hasFetchedCcss.current = false;
					setCcssError( true );
				}
			}
		};

		void Promise.allSettled( [
			fetchActivities(),
			fetchRules(),
			fetchCcssStatus(),
		] );

		// Audit #1354 review: locals may be null when their want* flag
		// was false. Snapshot the live controllers so cleanup never
		// dereferences a null local or a stale ref.
		const liveActivities = activitiesControllerRef.current;
		const liveRules = rulesControllerRef.current;
		const liveCcss = ccssControllerRef.current;
		return () => {
			liveActivities?.abort();
			liveRules?.abort();
			liveCcss?.abort();
		};
		// Intentionally minimal deps: hasFetched* refs (not state) gate
		// re-fetches, so effect-written state (recentActivities, serverRules)
		// is read but not depended on — depending on it would cause an extra
		// effect run plus AbortController teardown/recreation after each
		// fetch and could abort the parallel CCSS request when serverRules
		// resolves first.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ activeTab, rulesRetryTrigger, ccssRefreshTrigger ] );

	useEffect( () => {
		setTransition( true );
		const timeout = setTimeout( () => setTransition( false ), 400 );
		return () => clearTimeout( timeout );
	}, [ activeTab ] );

	const wppoVersion = getWppoSettings()?.version ?? '';

	// Audit #1420: stable context value so consumers do not re-render
	// on every App render.
	const unsavedContextValue = useMemo(
		() => ( { isDirty, setIsDirty } ),
		[ isDirty, setIsDirty ]
	);

	return (
		<UnsavedChangesContext.Provider value={ unsavedContextValue }>
			<div className="wppo-container">
				{ /* Mobile Top Header */ }
				<div className="wppo-mobile-header">
					<div
						className="wppo-mobile-brand"
						title={ __(
							'Performance Optimisation',
							'performance-optimisation'
						) }
					>
						<div className="wppo-mobile-logo">
							<FontAwesomeIcon icon={ faBolt } />
						</div>
						<span className="wppo-mobile-brand__text">
							{ __(
								'Performance Optimisation',
								'performance-optimisation'
							) }
						</span>
					</div>
					<button
						className="wppo-mobile-toggle"
						onClick={ toggleMobileMenu }
						aria-label={ __(
							'Toggle Menu',
							'performance-optimisation'
						) }
						aria-expanded={ mobileMenuOpen }
						aria-controls="mobile-sidebar"
						ref={ toggleBtnRef }
					>
						<FontAwesomeIcon
							icon={ mobileMenuOpen ? faTimes : faBars }
						/>
					</button>
				</div>

				{ /* Sidebar Overlay */ }
				{ mobileMenuOpen && (
					<button
						type="button"
						className="wppo-sidebar-overlay"
						onClick={ toggleMobileMenu }
						aria-label={ __(
							'Close Menu',
							'performance-optimisation'
						) }
					/>
				) }

				<div
					id="mobile-sidebar"
					ref={ sidebarRef }
					className={ `wppo-sidebar ${
						mobileMenuOpen ? 'wppo-sidebar--mobile-open' : ''
					}` }
				>
					<div className="wppo-sidebar-header">
						<div className="wppo-sidebar-logo">
							<FontAwesomeIcon icon={ faBolt } />
						</div>
						<h3>
							{ __( 'Performance', 'performance-optimisation' ) }
							<span>
								{ __(
									'Optimisation',
									'performance-optimisation'
								) }
							</span>
						</h3>
					</div>
					<nav
						aria-label={ __(
							'Main Navigation',
							'performance-optimisation'
						) }
					>
						<ul>
							{ sidebarItems.map( ( item ) => (
								<li key={ item.name }>
									<button
										className={
											activeTab === item.name
												? 'wppo-is-active'
												: ''
										}
										aria-current={
											activeTab === item.name
												? 'page'
												: undefined
										}
										onClick={ () =>
											handleTabChange( item.name )
										}
									>
										<FontAwesomeIcon
											className="wppo-sidebar-icon"
											icon={ item.icon }
										/>
										<span className="wppo-sidebar-label">
											{ item.label }
										</span>
									</button>
								</li>
							) ) }
						</ul>
					</nav>
					<div className="wppo-sidebar-footer">
						<div className="wppo-sidebar-version">
							{ wppoVersion ? `v${ wppoVersion }` : '' }
						</div>
					</div>
				</div>

				<div className="wppo-content">
					<div className="wppo-main">
						<div
							className={ transition ? 'wppo-fadeIn' : undefined }
						>
							<ErrorBoundary>{ renderContent() }</ErrorBoundary>
						</div>
					</div>
				</div>
				<ConfirmDialog
					isOpen={ showGuard }
					onConfirm={ confirmDiscard }
					onCancel={ cancelGuard }
					title={ __(
						'Unsaved changes — Discard?',
						'performance-optimisation'
					) }
					message={ __(
						'You have unsaved changes. Leave without saving?',
						'performance-optimisation'
					) }
					confirmLabel={ __( 'Discard', 'performance-optimisation' ) }
					cancelLabel={ __( 'Cancel', 'performance-optimisation' ) }
					variant="warning"
				/>
			</div>
		</UnsavedChangesContext.Provider>
	);
};

export default App;
