import React, { useState, useEffect, useRef } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faAngleLeft, faAngleRight, faTachometerAlt, faFileAlt, faTools, faBullseye, faCog, faBars } from '@fortawesome/free-solid-svg-icons';
import FileOptimization from './components/FileOptimization';
import PreloadSettings from './components/PreloadSettings';
import ImageOptimization from './components/ImageOptimization';
import PluginSettings from './components/PluginSetting';
import Dashboard from './components/Dashboard';
import { fetchRecentActivities } from './lib/apiRequest';

const App = () => {
	const [activeTab, setActiveTab] = useState('dashboard');
	const [transition, setTransition] = useState(false);
	const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
	const [sidebarHide, setSidebarHide] = useState(false);
	const [recentActivities, setRecentActivities] = useState([]);
	const hasFetchedActivities = useRef(false);

	useEffect(() => {
		const handleResize = () => {
			if (window.innerWidth < 768) {
				setSidebarCollapsed(true);  // Collapse sidebar on mobile initially
				setSidebarHide(true)
			} else {
				setSidebarCollapsed(false);  // Expand sidebar on desktop
				setSidebarHide(false)
			}
		};

		handleResize(); // Call it initially
		window.addEventListener('resize', handleResize);
		return () => window.removeEventListener('resize', handleResize);
	}, []);

	// Animate tab changes
	useEffect(() => {
		const fetchActivities = async () => {
			if (activeTab === 'dashboard' && !hasFetchedActivities.current) {
				try {
					const data = await fetchRecentActivities(); // Await the returned data
					setRecentActivities(data); // Set the state with the fetched data
					hasFetchedActivities.current = true; // Mark as fetched
				} catch (error) {
					console.error('Failed to fetch activities:', error);
				}
			}
		};
		fetchActivities();
		setTransition(true);
		const timeout = setTimeout(() => {
			setTransition(false);
		}, 500);
		return () => clearTimeout(timeout);
	}, [activeTab]);

	const renderContent = () => {
		switch (activeTab) {
			case 'fileOptimization':
				return <FileOptimization options={qtpoSettings.settings.file_optimisation} />;
			case 'preload':
				return <PreloadSettings options={qtpoSettings.settings.preload_settings} />;
			case 'imageOptimization':
				return <ImageOptimization options={qtpoSettings.settings.image_optimisation} />;
			case 'tools':
				return <PluginSettings options={qtpoSettings.settings} />;
			default:
				return <Dashboard activities={recentActivities?.activities} />;
		}
	};

	return (
		<div className="container">
			{/* Mobile menu icon */}
			{/* <button className="hamburger-menu" onClick={() => setSidebarCollapsed(!sidebarCollapsed)}>
				<FontAwesomeIcon icon={sidebarCollapsed ? faAngleRight : faAngleLeft} />
			</button> */}
			<button className="hamburger-menu" onClick={() => setSidebarHide(!sidebarHide)} style={{ left: sidebarHide ? '0px' : '110px' }}>
				<FontAwesomeIcon icon={sidebarHide ? faAngleRight: faAngleLeft} />
			</button>
			<div className={`sidebar ${sidebarCollapsed ? 'collapsed' : ''} ${sidebarHide ? 'hide' : ''}`}>
				{/* <button className="toggle-sidebar" onClick={() => setSidebarCollapsed(!sidebarCollapsed)}>
					<FontAwesomeIcon icon={sidebarCollapsed ? faAngleRight : faAngleLeft} />
				</button> */}
				<h3>Performance Settings</h3>
				<ul>
					<li className={activeTab === 'dashboard' ? 'active' : ''} onClick={() => setActiveTab('dashboard')}>
						<FontAwesomeIcon className="sidebar-icon" icon={faTachometerAlt} />
						{!sidebarCollapsed && ' Dashboard'}
					</li>
					<li className={activeTab === 'fileOptimization' ? 'active' : ''} onClick={() => setActiveTab('fileOptimization')}>
						<FontAwesomeIcon className="sidebar-icon" icon={faFileAlt} />
						{!sidebarCollapsed && ' File Optimization'}
					</li>
					<li className={activeTab === 'preload' ? 'active' : ''} onClick={() => setActiveTab('preload')}>
						<FontAwesomeIcon className="sidebar-icon" icon={faBullseye} />
						{!sidebarCollapsed && ' Preload'}
					</li>
					<li className={activeTab === 'imageOptimization' ? 'active' : ''} onClick={() => setActiveTab('imageOptimization')}>
						<FontAwesomeIcon className="sidebar-icon" icon={faCog} />
						{!sidebarCollapsed && ' Image Optimization'}
					</li>
					<li className={activeTab === 'Tools' ? 'active' : ''} onClick={() => setActiveTab('tools')}>
						<FontAwesomeIcon className="sidebar-icon" icon={faTools} />
						{!sidebarCollapsed && ' Tools'}
					</li>
				</ul>
			</div>

			<div className={`content ${transition ? 'fadeIn' : ''}`}>
				{renderContent()}
			</div>
		</div>
	);
};

export default App;
