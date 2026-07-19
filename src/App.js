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
import { fetchRecentActivities, fetchServerRules } from './lib/apiRequest';

import { __ } from '@wordpress/i18n';

const SIDEBAR_BREAKPOINT = 992;

const App = () => {
	const [ activeTab, setActiveTab ] = useState( 'dashboard' );
	const [ transition, setTransition ] = useState( false );
	const sidebarCollapsed = false;
	const [ mobileMenuOpen, setMobileMenuOpen ] = useState( false );
	const [ recentActivities, setRecentActivities ] = useState( [] );
	const [ serverRules, setServerRules ] = useState( null );
	const hasFetchedActivities = useRef( false );

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
				label: __( 'File Optimization', 'performance-optimisation' ),
			},
			{
				name: 'preload',
				icon: faBullseye,
				label: __( 'Preload', 'performance-optimisation' ),
			},
			{
				name: 'imageOptimization',
				icon: faImages,
				label: __( 'Image Optimization', 'performance-optimisation' ),
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
					serverRules={ serverRules }
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
	}, [ activeTab, recentActivities, serverRules ] );

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

	const hasFetchedRules = useRef( false );

	useEffect( () => {
		const abortController = new AbortController();

		if (
			( activeTab === 'dashboard' || recentActivities.length === 0 ) &&
			! hasFetchedActivities.current
		) {
			const fetchActivities = async () => {
				try {
					const data = await fetchRecentActivities(
						1,
						abortController.signal
					);
					if ( ! abortController.signal.aborted ) {
						setRecentActivities( data );
						hasFetchedActivities.current = true;
					}
				} catch ( error ) {
					if ( ! abortController.signal.aborted ) {
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
				const res = await fetchServerRules( abortController.signal );
				if ( ! abortController.signal.aborted ) {
					if ( res.success ) {
						setServerRules( res.data );
					} else {
						hasFetchedRules.current = false;
					}
				} else {
					hasFetchedRules.current = false;
				}
			} catch ( err ) {
				hasFetchedRules.current = false;
				if ( ! abortController.signal.aborted ) {
					console.error( 'Failed to fetch server rules', err );
				}
			}
		};
		fetchRules();

		setTransition( true );
		const timeout = setTimeout( () => setTransition( false ), 400 );
		return () => {
			clearTimeout( timeout );
			abortController.abort();
		};
	}, [ activeTab, recentActivities.length, serverRules ] );

	return (
		<div className="wppo-container">
			{ /* Mobile Top Header */ }
			<div className="wppo-mobile-header">
				<div className="wppo-mobile-brand">
					<div className="wppo-mobile-logo">
						<FontAwesomeIcon icon={ faBolt } />
					</div>
					{ __(
						'Performance Optimisation',
						'performance-optimisation'
					) }
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
					aria-label={ __(
						'Close Menu',
						'performance-optimisation'
					) }
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
						{ __( 'Performance', 'performance-optimisation' ) }
						<span>
							{ __( 'Optimisation', 'performance-optimisation' ) }
						</span>
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
					<div className="wppo-sidebar-version">
						{ __( 'v1.6.0', 'performance-optimisation' ) }
					</div>
				</div>
			</div>

			<div className="wppo-content">
				<div className="wppo-main">
					<div className={ transition ? 'fadeIn' : undefined }>
						{ renderContent }
					</div>
				</div>
			</div>
		</div>
	);
};

export default App;
