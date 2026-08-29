import {
	useState,
	useEffect,
	useRef,
	useMemo,
	lazy,
	Suspense,
} from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faTachometerAlt,
	faRocket,
	faImages,
	faDatabase,
	faTools,
	faBars,
	faTimes,
	faBolt,
	faSpinner,
	faSearch,
} from '@fortawesome/free-solid-svg-icons';
import {
	apiCall,
	fetchRecentActivities,
	fetchServerRules,
} from './lib/apiRequest';
import ErrorBoundary from './components/common/ErrorBoundary';
import SetupWizard from './components/SetupWizard';

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

const TAB_ALIASES = {
	dashboard: 'overview',
	fileOptimization: 'speed',
	preload: 'speed',
	imageOptimization: 'media',
	databaseCleanup: 'data',
	objectCache: 'data',
	tools: 'manage',
	overview: 'overview',
	speed: 'speed',
	media: 'media',
	data: 'data',
	manage: 'manage',
};

const getInitialTab = () => {
	if ( typeof window !== 'undefined' ) {
		const params = new URLSearchParams( window.location.search );
		const tabParam = params.get( 'tab' );
		if ( tabParam && TAB_ALIASES[ tabParam ] ) {
			return TAB_ALIASES[ tabParam ];
		}
		if ( window.location.hash ) {
			const hash = window.location.hash.replace( /^#/, '' );
			if ( TAB_ALIASES[ hash ] ) {
				return TAB_ALIASES[ hash ];
			}
		}
	}
	return 'overview';
};

const App = () => {
	const [ activeTab, setActiveTab ] = useState( getInitialTab );
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ transition, setTransition ] = useState( false );
	const [ mobileMenuOpen, setMobileMenuOpen ] = useState( false );
	const [ recentActivities, setRecentActivities ] = useState( [] );
	const [ serverRules, setServerRules ] = useState( null );
	const [ serverRulesError, setServerRulesError ] = useState( false );
	const [ rulesRetryTrigger, setRulesRetryTrigger ] = useState( 0 );
	const [ ccssStatus, setCcssStatus ] = useState( {} );
	const [ ccssError, setCcssError ] = useState( false );
	const [ ccssRefreshTrigger, setCcssRefreshTrigger ] = useState( 0 );
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
				name: 'overview',
				icon: faTachometerAlt,
				label: __( 'Overview', 'performance-optimisation' ),
				description: __(
					'Health & quick actions',
					'performance-optimisation'
				),
			},
			{
				name: 'speed',
				icon: faRocket,
				label: __( 'Speed', 'performance-optimisation' ),
				description: __(
					'Make pages load faster',
					'performance-optimisation'
				),
			},
			{
				name: 'media',
				icon: faImages,
				label: __( 'Media', 'performance-optimisation' ),
				description: __(
					'Images & videos',
					'performance-optimisation'
				),
			},
			{
				name: 'data',
				icon: faDatabase,
				label: __( 'Data & System', 'performance-optimisation' ),
				description: __(
					'Database & caching',
					'performance-optimisation'
				),
			},
			{
				name: 'manage',
				icon: faTools,
				label: __( 'Manage', 'performance-optimisation' ),
				description: __(
					'Tools & diagnostics',
					'performance-optimisation'
				),
			},
		],
		[]
	);

	// Sync activeTab to URL ?tab= for deep-link & migration.
	useEffect( () => {
		if ( typeof window === 'undefined' || ! window.history?.pushState ) {
			return;
		}
		const url = new URL( window.location.href );
		if ( url.searchParams.get( 'tab' ) !== activeTab ) {
			url.searchParams.set( 'tab', activeTab );
			window.history.pushState( {}, '', url.toString() );
		}
	}, [ activeTab ] );

	useEffect( () => {
		const onPopState = () => {
			const params = new URLSearchParams( window.location.search );
			const tab = params.get( 'tab' );
			if (
				tab &&
				TAB_ALIASES[ tab ] &&
				TAB_ALIASES[ tab ] !== activeTab
			) {
				setActiveTab( TAB_ALIASES[ tab ] );
			}
		};
		window.addEventListener( 'popstate', onPopState );
		return () => window.removeEventListener( 'popstate', onPopState );
	}, [ activeTab ] );

	const renderContent = () => {
		const settings =
			typeof wppoSettings !== 'undefined'
				? wppoSettings?.settings ?? {}
				: {};
		const components = {
			overview: (
				<Dashboard
					activities={ recentActivities?.activities }
					cacheSettings={ settings.cache_settings }
					userRoles={
						typeof wppoSettings !== 'undefined'
							? wppoSettings?.userRoles ?? {}
							: {}
					}
					onNavigate={ setActiveTab }
					searchQuery={ searchQuery }
				/>
			),
			speed: (
				<div className="wppo-pillar wppo-pillar--speed">
					<FileOptimization
						options={ settings.file_optimisation }
						serverRules={ serverRules }
						serverRulesError={ serverRulesError }
						ccssStatus={ ccssStatus }
						ccssError={ ccssError }
						compact={ searchQuery }
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
					<PreloadSettings
						options={ settings.preload_settings }
						compact={ searchQuery }
					/>
				</div>
			),
			media: (
				<ImageOptimization
					options={ settings.image_optimisation }
					compact={ searchQuery }
				/>
			),
			data: (
				<div className="wppo-pillar wppo-pillar--data">
					<DatabaseCleanup
						options={ settings.database_cleanup }
						compact={ searchQuery }
					/>
					<ObjectCache
						options={ settings.object_cache }
						compact={ searchQuery }
					/>
				</div>
			),
			manage: (
				<PluginSettings options={ settings } compact={ searchQuery } />
			),
			dashboard: (
				<Dashboard
					activities={ recentActivities?.activities }
					cacheSettings={ settings.cache_settings }
					userRoles={
						typeof wppoSettings !== 'undefined'
							? wppoSettings?.userRoles ?? {}
							: {}
					}
					onNavigate={ setActiveTab }
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

		const activeComponent = components[ activeTab ] || components.overview;

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

		if (
			( activeTab === 'overview' ||
				activeTab === 'dashboard' ||
				recentActivities.length === 0 ) &&
			! hasFetchedActivities.current
		) {
			const fetchActivities = async () => {
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

			fetchActivities();
		}

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
		fetchRules();

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
				if ( ! ccssController.signal.aborted && res.success ) {
					setCcssStatus( res.data );
					setCcssError( false );
				} else if ( ! ccssController.signal.aborted ) {
					hasFetchedCcss.current = false;
					setCcssError( true );
				}
			} catch {
				if ( ! ccssController.signal.aborted ) {
					hasFetchedCcss.current = false;
					setCcssError( true );
				}
			} finally {
				if ( ! ccssController.signal.aborted ) {
					setCcssRefreshTrigger( 0 );
				}
			}
		};
		fetchCcssStatus();

		return () => {
			activitiesController.abort();
			rulesController.abort();
			ccssController.abort();
		};
	}, [
		activeTab,
		recentActivities.length,
		serverRules,
		rulesRetryTrigger,
		ccssRefreshTrigger,
	] );

	useEffect( () => {
		setTransition( true );
		const timeout = setTimeout( () => setTransition( false ), 400 );
		return () => clearTimeout( timeout );
	}, [ activeTab ] );

	return (
		<div className="wppo-container">
			<SetupWizard />
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
							{ __( 'Optimisation', 'performance-optimisation' ) }
						</span>
					</h3>
				</div>
				<div className="wppo-sidebar-search">
					<FontAwesomeIcon
						icon={ faSearch }
						className="wppo-sidebar-search__icon"
						aria-hidden="true"
					/>
					<input
						type="search"
						className="wppo-sidebar-search__input"
						placeholder={ __(
							'Search settings…',
							'performance-optimisation'
						) }
						aria-label={ __(
							'Search settings',
							'performance-optimisation'
						) }
						value={ searchQuery }
						onChange={ ( e ) => setSearchQuery( e.target.value ) }
					/>
				</div>
				<nav
					aria-label={ __(
						'Main Navigation',
						'performance-optimisation'
					) }
				>
					<ul role="tablist">
						{ sidebarItems.map( ( item ) => (
							<li key={ item.name } role="presentation">
								<button
									role="tab"
									className={
										activeTab === item.name
											? 'wppo-is-active'
											: ''
									}
									aria-selected={ activeTab === item.name }
									aria-current={
										activeTab === item.name
											? 'page'
											: undefined
									}
									aria-describedby={ `wppo-desc-${ item.name }` }
									onClick={ () => {
										setActiveTab( item.name );
										setMobileMenuOpen( false );
									} }
								>
									<FontAwesomeIcon
										className="wppo-sidebar-icon"
										icon={ item.icon }
										aria-hidden="true"
									/>
									<span className="wppo-sidebar-label">
										{ item.label }
									</span>
									<span
										id={ `wppo-desc-${ item.name }` }
										className="wppo-sidebar-desc"
									>
										{ item.description }
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
					<div className={ transition ? 'wppo-fadeIn' : undefined }>
						<ErrorBoundary>{ renderContent() }</ErrorBoundary>
					</div>
				</div>
			</div>
		</div>
	);
};

export default App;
