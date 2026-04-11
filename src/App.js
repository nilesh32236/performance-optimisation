import React, { useState, useEffect, useRef, useMemo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faAngleLeft,
	faAngleRight,
	faTachometerAlt,
	faFileAlt,
	faTools,
	faBullseye,
	faCog,
	faDatabase,
} from '@fortawesome/free-solid-svg-icons';
import FileOptimization from './components/FileOptimization';
import PreloadSettings from './components/PreloadSettings';
import ImageOptimization from './components/ImageOptimization';
import PluginSettings from './components/PluginSetting';
import Dashboard from './components/Dashboard';
import DatabaseCleanup from './components/DatabaseCleanup';
import { fetchRecentActivities } from './lib/apiRequest';

const translations = wppoSettings.translations;
const t = ( key ) => ( translations && translations[ key ] ) || key;

const SIDEBAR_BREAKPOINT = 992;

const App = () => {
	const [ activeTab, setActiveTab ] = useState( 'dashboard' );
	const [ transition, setTransition ] = useState( false );
	const [ sidebarCollapsed, setSidebarCollapsed ] = useState( false );
	const [ sidebarHide, setSidebarHide ] = useState( false );
	const [ recentActivities, setRecentActivities ] = useState( [] );
	const hasFetchedActivities = useRef( false );

	// Memoize the sidebar items to avoid unnecessary re-calculation
	const sidebarItems = useMemo(
		() => [
			{
				name: 'dashboard',
				icon: faTachometerAlt,
				label: translations.dashboard,
			},
			{
				name: 'fileOptimization',
				icon: faFileAlt,
				label: translations.fileOptimization,
			},
			{ name: 'preload', icon: faBullseye, label: translations.preload },
			{
				name: 'imageOptimization',
				icon: faCog,
				label: translations.imageOptimization,
			},
			{
				name: 'databaseCleanup',
				icon: faDatabase,
				label:
					translations.databaseOptimization ||
					' Database Optimization',
			},
			{ name: 'tools', icon: faTools, label: translations.tools },
		],
		[ translations ]
	);

	// Memoize the renderContent function to avoid recalculating on each render
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

	// Handle sidebar toggle visibility
	const toggleSidebar = () => setSidebarHide( ( prevState ) => ! prevState );

	// Set sidebar collapse behavior based on screen width
	useEffect( () => {
		const handleResize = () => {
			const isMobile = window.innerWidth < SIDEBAR_BREAKPOINT;
			setSidebarHide( isMobile );
		};

		handleResize(); // Initial check
		window.addEventListener( 'resize', handleResize );
		return () => window.removeEventListener( 'resize', handleResize );
	}, [] );

	// Fetch recent activities when the tab changes to "dashboard"
	useEffect( () => {
		if ( activeTab === 'dashboard' && ! hasFetchedActivities.current ) {
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
		const timeout = setTimeout( () => setTransition( false ), 500 );
		return () => clearTimeout( timeout );
	}, [ activeTab, translations.failedFetchActivities ] );

	return (
		<div className="container">
			<button
				className="hamburger-menu"
				onClick={ toggleSidebar }
				aria-label={
					sidebarHide
						? t( 'sidebar.expand' )
						: t( 'sidebar.collapse' )
				}
				aria-expanded={ ! sidebarHide }
			>
				<FontAwesomeIcon
					icon={ sidebarHide ? faAngleRight : faAngleLeft }
				/>
			</button>

			<div
				className={ `sidebar ${ sidebarCollapsed ? 'collapsed' : '' } ${
					sidebarHide ? 'hide' : ''
				}` }
			>
				<div className="sidebar-header">
					<h3>{ translations.performanceSettings }</h3>
				</div>
				<nav aria-label={ translations.performanceSettings }>
					<ul>
						{ sidebarItems.map( ( item ) => {
							return (
								<li key={ item.name }>
									<button
										aria-current={
											activeTab === item.name ? 'page' : undefined
										}
										aria-label={ sidebarCollapsed ? item.label : undefined }
										className={
											activeTab === item.name ? 'active' : ''
										}
										onClick={ () => {
											setActiveTab( item.name );
											if ( window.innerWidth < SIDEBAR_BREAKPOINT ) {
												setSidebarHide( true );
											}
										} }
									>
										<FontAwesomeIcon
											className="sidebar-icon"
											icon={ item.icon }
										/>
										{ ! sidebarCollapsed && item.label && (
											<span className="sidebar-label">{ item.label }</span>
										) }
									</button>
								</li>
							);
						} ) }
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
