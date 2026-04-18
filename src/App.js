import { useState, useEffect, useRef, useMemo } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
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
} from '@fortawesome/free-solid-svg-icons';
import FileOptimization from './components/FileOptimization';
import PreloadSettings from './components/PreloadSettings';
import ImageOptimization from './components/ImageOptimization';
import PluginSettings from './components/PluginSetting';
import Dashboard from './components/Dashboard';
import DatabaseCleanup from './components/DatabaseCleanup';
import ObjectCache from './components/ObjectCache';
import { fetchRecentActivities } from './lib/apiRequest';

const translations = wppoSettings.translations;

const SIDEBAR_BREAKPOINT = 992;

const App = () => {
	const [ activeTab, setActiveTab ] = useState( 'dashboard' );
	const [ transition, setTransition ] = useState( false );
	const [ sidebarCollapsed ] = useState( false );
	const [ mobileMenuOpen, setMobileMenuOpen ] = useState( false );
	const [ recentActivities, setRecentActivities ] = useState( [] );
	const hasFetchedActivities = useRef( false );

	const sidebarItems = useMemo(
		() => [
			{
				name: 'dashboard',
				icon: faTachometerAlt,
				label: translations.dashboard,
			},
			{
				name: 'fileOptimization',
				icon: faFileCode,
				label: translations.fileOptimization,
			},
			{
				name: 'preload',
				icon: faBullseye,
				label: translations.preload,
			},
			{
				name: 'imageOptimization',
				icon: faImages,
				label: translations.imageOptimization,
			},
			{
				name: 'databaseCleanup',
				icon: faDatabase,
				label: translations.databaseOptimization || 'Database',
			},
			{
				name: 'objectCache',
				icon: faServer,
				label: translations.objectCache || 'Object Cache',
			},
			{
				name: 'tools',
				icon: faTools,
				label: translations.tools,
			},
		],
		[]
	);

	const renderContent = useMemo( () => {
		const components = {
			dashboard: (
				<Dashboard
					activities={ recentActivities?.activities }
					onNavigate={ setActiveTab }
				/>
			),
			fileOptimization: (
				<FileOptimization
					options={ wppoSettings.settings.file_optimisation }
				/>
			),
			preload: (
				<PreloadSettings
					options={ wppoSettings.settings.preload_settings }
				/>
			),
			imageOptimization: (
				<ImageOptimization
					options={ wppoSettings.settings.image_optimisation }
				/>
			),
			databaseCleanup: (
				<DatabaseCleanup
					options={ wppoSettings.settings.database_cleanup }
				/>
			),
			objectCache: (
				<ObjectCache options={ wppoSettings.settings.object_cache } />
			),
			tools: <PluginSettings options={ wppoSettings.settings } />,
		};

		return components[ activeTab ] || components.dashboard;
	}, [ activeTab, recentActivities ] );

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
		if (
			( activeTab === 'dashboard' || recentActivities.length === 0 ) &&
			! hasFetchedActivities.current
		) {
			const fetchActivities = async () => {
				try {
					const data = await fetchRecentActivities();
					setRecentActivities( data );
					hasFetchedActivities.current = true;
				} catch ( error ) {
					console.error( translations.failedFetchActivities, error );
				}
			};

			fetchActivities();
		}

		setTransition( true );
		const timeout = setTimeout( () => setTransition( false ), 400 );
		return () => clearTimeout( timeout );
	}, [ activeTab, recentActivities.length ] );

	return (
		<div className="wppo-container">
			{ /* Mobile Top Header */ }
			<div className="wppo-mobile-header">
				<div className="wppo-mobile-brand">
					<div className="wppo-mobile-logo">
						<FontAwesomeIcon icon={ faBolt } />
					</div>
					Performance Optimisation
				</div>
				<button
					className="wppo-mobile-toggle"
					onClick={ toggleMobileMenu }
					aria-label="Toggle Menu"
					aria-expanded={ mobileMenuOpen }
					aria-controls="mobile-sidebar"
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
					aria-label={ translations.closeMenu || 'Close Menu' }
				/>
			) }

			<div
				id="mobile-sidebar"
				className={ `wppo-sidebar ${
					sidebarCollapsed ? 'wppo-sidebar--collapsed' : ''
				} ${ mobileMenuOpen ? 'wppo-sidebar--mobile-open' : '' }` }
			>
				<div className="wppo-sidebar-header">
					<div className="wppo-sidebar-logo">
						<FontAwesomeIcon icon={ faBolt } />
					</div>
					<h3>
						Performance
						<span>Optimisation</span>
					</h3>
				</div>
				<nav aria-label="Main Navigation">
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
									onClick={ () => {
										setActiveTab( item.name );
										setMobileMenuOpen( false );
									} }
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
					<div className="wppo-sidebar-version">v1.4.0</div>
				</div>
			</div>

			<div className="wppo-content">
				<div className="wppo-main">
					<div className={ transition ? 'fadeIn' : '' }>
						{ renderContent }
					</div>
				</div>
			</div>
		</div>
	);
};

export default App;
