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
} from '@fortawesome/free-solid-svg-icons';
import FileOptimization from './components/FileOptimization';
import PreloadSettings from './components/PreloadSettings';
import ImageOptimization from './components/ImageOptimization';
import PluginSettings from './components/PluginSetting';
import Dashboard from './components/Dashboard';
import DatabaseCleanup from './components/DatabaseCleanup';
import { fetchRecentActivities } from './lib/apiRequest';

const translations = wppoSettings.translations;

const SIDEBAR_BREAKPOINT = 992;

const App = () => {
	const [ activeTab, setActiveTab ] = useState( 'dashboard' );
	const [ transition, setTransition ] = useState( false );
	const sidebarCollapsed = false;
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
				<Dashboard activities={ recentActivities?.activities } />
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
			databaseCleanup: <DatabaseCleanup />,
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
		<div className="container" style={ { margin: '20px auto' } }>
			{ /* Mobile Top Header */ }
			<div className="mobile-header">
				<div className="mobile-brand">Performance Optimize</div>
				<button
					className="mobile-toggle"
					onClick={ toggleMobileMenu }
					aria-label={ mobileMenuOpen ? ( translations.closeMenu || 'Close Menu' ) : ( translations.openMenu || 'Open Menu' ) }
					aria-expanded={ mobileMenuOpen }
				>
					<FontAwesomeIcon
						icon={ mobileMenuOpen ? faTimes : faBars }
					/>
				</button>
			</div>

			{ /* Sidebar Overlay */ }
			{ mobileMenuOpen && (
				<div
					className="sidebar-overlay"
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
				className={ `sidebar ${ sidebarCollapsed ? 'collapsed' : '' } ${
					mobileMenuOpen ? 'mobile-open' : ''
				}` }
			>
				<div className="sidebar-header">
					<h3>Performance Optimize</h3>
				</div>
				<nav aria-label="Main Navigation">
					<ul>
						{ sidebarItems.map( ( item ) => (
							<li key={ item.name }>
								<button
									className={
										activeTab === item.name ? 'active' : ''
									}
									onClick={ () => {
										setActiveTab( item.name );
										setMobileMenuOpen( false );
									} }
								>
									<FontAwesomeIcon
										className="sidebar-icon"
										icon={ item.icon }
									/>
									<span className="sidebar-label">
										{ item.label }
									</span>
								</button>
							</li>
						) ) }
					</ul>
				</nav>
			</div>

			<div className={ `content ${ transition ? 'fadeIn' : '' }` }>
				{ renderContent }
			</div>
		</div>
	);
};

export default App;
