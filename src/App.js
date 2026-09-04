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
		<FontAwesomeIcon icon={ faSpinner } spin />
		<span>{ __( 'Loading…', 'performance-optimisation' ) }</span>
	</div>
);

const SIDEBAR_BREAKPOINT = 992;

const App = () => {
	const [ activeTab, setActiveTab ] = useState( 'dashboard' );
	const [ transition, setTransition ] = useState( false );
	const [ mobileMenuOpen, setMobileMenuOpen ] = useState( false );
	const [ recentActivities, setRecentActivities ] = useState( [] );
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

	const renderContent = () => {
		const settings =
			typeof wppoSettings !== 'undefined'
				? wppoSettings?.settings ?? {}
				: {};
		const components = {
			dashboard: (
				<Dashboard
					activities={ recentActivities?.activities }
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
		const themeColors = wppoSettings?.themeColors;
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
		if ( activitiesControllerRef.current ) {
			activitiesControllerRef.current.abort();
		}
		const activitiesController = new AbortController();
		activitiesControllerRef.current = activitiesController;

		if ( rulesControllerRef.current ) {
			rulesControllerRef.current.abort();
		}
		const rulesController = new AbortController();
		rulesControllerRef.current = rulesController;

		if ( ccssControllerRef.current ) {
			ccssControllerRef.current.abort();
		}
		const ccssController = new AbortController();
		ccssControllerRef.current = ccssController;

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
					hasFetchedActivities.current = true;
				}
			} catch ( error ) {
				if ( ! activitiesController.signal.aborted ) {
					console.error(
						__(
							'Failed to fetch activities:',
							'performance-optimisation'
						),
						error
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
			} catch {
				hasFetchedRules.current = false;
				if ( ! rulesController.signal.aborted ) {
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
			} catch {
				if ( ! ccssController.signal.aborted ) {
					hasFetchedCcss.current = false;
					setCcssError( true );
				}
			}
		};

		Promise.allSettled( [
			fetchActivities(),
			fetchRules(),
			fetchCcssStatus(),
		] );

		return () => {
			activitiesController.abort();
			rulesController.abort();
			ccssController.abort();
		};
	}, [ activeTab, rulesRetryTrigger, ccssRefreshTrigger ] );

	useEffect( () => {
		setTransition( true );
		const timeout = setTimeout( () => setTransition( false ), 400 );
		return () => clearTimeout( timeout );
	}, [ activeTab ] );

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
					<div
						className="wppo-sidebar-overlay"
						onClick={ toggleMobileMenu }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								toggleMobileMenu();
							}
						} }
						role="button"
						tabIndex="0"
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
							{ wppoSettings?.version
								? `v${ wppoSettings.version }`
								: '' }
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
