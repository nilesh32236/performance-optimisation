import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faAngleLeft, faAngleRight, faTachometerAlt, faFileAlt, faImage, faDatabase, faBullseye, faCog } from '@fortawesome/free-solid-svg-icons';
import FileOptimization from './components/FileOptimization';
import MediaOptimization from './components/MediaOptimization';
import PreloadSettings from './components/PreloadSettings';
import DatabaseOptimization from './components/DatabaseOptimization';
import ImageOptimization from './components/ImageOptimization';
import Dashboard from './components/Dashboard';

const App = () => {
    const [activeTab, setActiveTab] = useState('dashboard');
    const [transition, setTransition] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);

    useEffect(() => {
        const handleResize = () => {
            if (window.innerWidth < 768) {
                setSidebarCollapsed(true);
            } else {
                setSidebarCollapsed(false);
            }
        };

        handleResize(); // Call it initially
        window.addEventListener('resize', handleResize);
        return () => window.removeEventListener('resize', handleResize);
    }, []);

    // Animate tab changes
    useEffect(() => {
        // Add transition when tab changes
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
            case 'media':
                return <MediaOptimization options={qtpoSettings.settings.media_optimisation} />;
            case 'preload':
                return <PreloadSettings options={qtpoSettings.settings.preload_settings} />;
            case 'database':
                return <DatabaseOptimization options={qtpoSettings.settings.database_optimization} />;
            case 'imageOptimization':
                return <ImageOptimization options={qtpoSettings.settings.image_optimisation} />;
            default:
                return <Dashboard />;
        }
    };

    return (
        <div className="container">
            <div className={`sidebar ${sidebarCollapsed ? 'collapsed' : ''}`}>
                <button className="toggle-sidebar" onClick={() => setSidebarCollapsed(!sidebarCollapsed)}>
                    <FontAwesomeIcon icon={sidebarCollapsed ? faAngleRight : faAngleLeft} />
                </button>
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
                    <li className={activeTab === 'media' ? 'active' : ''} onClick={() => setActiveTab('media')}>
                        <FontAwesomeIcon className="sidebar-icon" icon={faImage} />
                        {!sidebarCollapsed && ' Media Optimization'}
                    </li>
                    <li className={activeTab === 'preload' ? 'active' : ''} onClick={() => setActiveTab('preload')}>
                        <FontAwesomeIcon className="sidebar-icon" icon={faBullseye} />
                        {!sidebarCollapsed && ' Preload'}
                    </li>
                    <li className={activeTab === 'database' ? 'active' : ''} onClick={() => setActiveTab('database')}>
                        <FontAwesomeIcon className="sidebar-icon" icon={faDatabase} />
                        {!sidebarCollapsed && ' Database Optimization'}
                    </li>
                    <li className={activeTab === 'imageOptimization' ? 'active' : ''} onClick={() => setActiveTab('imageOptimization')}>
                        <FontAwesomeIcon className="sidebar-icon" icon={faCog} />
                        {!sidebarCollapsed && ' Image Optimization'}
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
