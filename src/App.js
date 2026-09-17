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

const TabFallback = () => (
	<div className="wppo-loading-placeholder wppo-loading-placeholder--fallback">
		<FontAwesomeIcon icon={ faSpinner } spin aria-hidden="true" />
		<span>{ __( 'Loading…', 'performance-optimisation' ) }</span>
	</div>
);

const SIDEBAR_BREAKPOINT = 992;

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
	// Last ccssRefreshTrigger value actually handled: the trigger stays at its
	// incremented value after a manual refresh, so gating on
	// `0 === ccssRefreshTrigger` alone refetches on every tab switch.
	const lastCcssTrigger = useRef( 0 );

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
					onRetryServerRules={ () => {
						hasFetchedRules.current = false;
						setServerRulesError( false );
						setServerRules( null );
						setRulesRetryTrigger( ( c ) => c + 1 );
					} }
					onCcssRefresh={ () => {
						hasFetchedCcss.current = false;
						setCcssError( false );
						setCcssRefreshTrigger( ( c ) => c + 1 );
					} }
					onCcssRetry={ () => {
						hasFetchedCcss.current = false;
						setCcssError( false );
						setCcssRefreshTrigger( ( c ) => c + 1 );
					} }
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

		const handleKeyDown = ( e ) => {
			if ( e.key !== 'Tab' ) {
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

		sidebar.addEventListener( 'keydown', handleKeyDown );

		return () => {
			sidebar.removeEventListener( 'keydown', handleKeyDown );
			if ( toggleBtn ) {
				toggleBtn.focus();
			}
		};
	}, [ mobileMenuOpen ] );

	// Inject frontend theme accent colors as CSS custom properties.
	useEffect( () => {
		const themeColors = getWppoSettings()?.themeColors;
		if ( ! themeColors ) {
			return;
		}

		const root = document.documentElement;
		if ( themeColors.primary ) {
			root.style.setProperty(
				'--wppo-frontend-primary',
				themeColors.primary
			);
		}
		if ( themeColors.secondary ) {
			root.style.setProperty(
				'--wppo-frontend-secondary',
				themeColors.secondary
			);
		}
		if ( themeColors.text ) {
			root.style.setProperty( '--wppo-frontend-text', themeColors.text );
		}
	}, [] );

	useEffect( () => {
		// Only create/abort the controller for a fetch that will actually
		// run (audit #1354): recreating all three on every tab change
		// aborted unrelated in-flight rules/CCSS requests even when the
		// hasFetched guards made the other fetches no-ops.
		// recentActivities changes type from [] to { activities } after a
		// successful fetch, so emptiness needs a type-aware check — plain
		// .length is undefined (not 0) on the object shape.
		const activitiesEmpty = Array.isArray( recentActivities )
			? recentActivities.length === 0
			: ! ( recentActivities?.activities?.length > 0 );
		const willFetchActivities =
			( activeTab === 'dashboard' || activitiesEmpty ) &&
			! hasFetchedActivities.current;
		const willFetchRules = ! ( serverRules || hasFetchedRules.current );
		const willFetchCcss =
			! hasFetchedCcss.current ||
			lastCcssTrigger.current !== ccssRefreshTrigger;

		let activitiesController = null;
		if ( willFetchActivities ) {
			if ( activitiesControllerRef.current ) {
				activitiesControllerRef.current.abort();
			}
			activitiesController = new AbortController();
			activitiesControllerRef.current = activitiesController;
		} else {
			activitiesController = activitiesControllerRef.current;
		}

		let rulesController = null;
		if ( willFetchRules ) {
			if ( rulesControllerRef.current ) {
				rulesControllerRef.current.abort();
			}
			rulesController = new AbortController();
			rulesControllerRef.current = rulesController;
		} else {
			rulesController = rulesControllerRef.current;
		}

		let ccssController = null;
		if ( willFetchCcss ) {
			if ( ccssControllerRef.current ) {
				ccssControllerRef.current.abort();
			}
			ccssController = new AbortController();
			ccssControllerRef.current = ccssController;
		} else {
			ccssController = ccssControllerRef.current;
		}

		const fetchActivities = async () => {
			const emptyNow = Array.isArray( recentActivities )
				? recentActivities.length === 0
				: ! ( recentActivities?.activities?.length > 0 );
			if (
				! (
					( activeTab === 'dashboard' || emptyNow ) &&
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
			// The flag is only cleared when the settling controller is still
			// current: if the effect re-ran while fetch A was in flight,
			// A's rejection must not clear the flag for fetch B.
			const isCurrent = () =>
				rulesControllerRef.current === rulesController;
			try {
				const res = await fetchServerRules( rulesController.signal );
				if ( ! rulesController.signal.aborted ) {
					if ( res.success ) {
						setServerRules( res.data );
						setServerRulesError( false );
					} else if ( isCurrent() ) {
						hasFetchedRules.current = false;
						setServerRulesError( true );
					}
				} else if ( isCurrent() ) {
					hasFetchedRules.current = false;
				}
			} catch {
				if ( isCurrent() ) {
					hasFetchedRules.current = false;
				}
				if ( ! rulesController.signal.aborted ) {
					setServerRulesError( true );
				}
			}
		};

		const fetchCcssStatus = async () => {
			if (
				hasFetchedCcss.current &&
				lastCcssTrigger.current === ccssRefreshTrigger
			) {
				return;
			}
			hasFetchedCcss.current = true;
			// Only the still-current controller may clear the flag (see
			// fetchRules above): a superseded fetch settling late must not
			// invalidate its replacement.
			const isCurrent = () =>
				ccssControllerRef.current === ccssController;
			try {
				const res = await apiCall(
					'ccss_status',
					{},
					'GET',
					ccssController.signal
				);
				if ( ccssController.signal.aborted ) {
					if ( isCurrent() ) {
						hasFetchedCcss.current = false;
					}
					return;
				}
				if ( res.success ) {
					setCcssStatus( res.data );
					setCcssError( false );
					lastCcssTrigger.current = ccssRefreshTrigger;
				} else if ( isCurrent() ) {
					hasFetchedCcss.current = false;
					setCcssError( true );
				}
			} catch {
				if ( ! ccssController.signal.aborted ) {
					if ( isCurrent() ) {
						hasFetchedCcss.current = false;
					}
					setCcssError( true );
				}
			}
		};

		void Promise.allSettled( [
			fetchActivities(),
			fetchRules(),
			fetchCcssStatus(),
		] );

		// Abort by ref identity: when willFetch is false no new controller
		// is created, so aborting only this run's controllers would leave an
		// earlier in-flight fetch un-aborted on re-run/unmount.
		return () => {
			activitiesControllerRef.current?.abort();
			rulesControllerRef.current?.abort();
			ccssControllerRef.current?.abort();
		};
		// Intentionally minimal deps: hasFetched* refs (not state) gate
		// re-fetches, so effect-written state (recentActivities, serverRules)
		// is read but not depended on — depending on it would cause an extra
		// effect run plus AbortController teardown/recreation after each
		// fetch and could abort the parallel CCSS request when serverRules
		// resolves first.
		// TODO (tech-debt, audit #1354): replace the hasFetched* ref gates with
		// a query-key useCallback so the exhaustive-deps exception below is no
		// longer needed; same minimal-deps pattern exists across components.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ activeTab, rulesRetryTrigger, ccssRefreshTrigger ] );

	useEffect( () => {
		setTransition( true );
		const timeout = setTimeout( () => setTransition( false ), 400 );
		return () => clearTimeout( timeout );
	}, [ activeTab ] );

	const wppoVersion = getWppoSettings()?.version ?? '';

	return (
		<UnsavedChangesContext.Provider value={ { isDirty, setIsDirty } }>
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
							<FontAwesomeIcon
								icon={ faBolt }
								aria-hidden="true"
							/>
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
							aria-hidden="true"
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
							<FontAwesomeIcon
								icon={ faBolt }
								aria-hidden="true"
							/>
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
											aria-hidden="true"
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
