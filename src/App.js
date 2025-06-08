import React, { useState, useEffect, useCallback } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faTachometerAlt,
  faFileCode,
  faImages,
  faRocket,
  faTools,
  faHistory,
  faBars,
  faTimes,
} from '@fortawesome/free-solid-svg-icons';

import Dashboard from './components/Dashboard/Dashboard';
import FileOptimization from './components/FileOptimization/FileOptimization';
import ImageOptimization from './components/ImageOptimization/ImageOptimization';
import PreloadSettings from './components/PreloadSettings/PreloadSettings';
import Tools from './components/Tools/Tools';
import ActivityLog from './components/ActivityLog/ActivityLog';
// You might want a dedicated Toast component or use a library like react-toastify
// import { ToastContainer, toast } from 'react-toastify';
// import 'react-toastify/dist/ReactToastify.css';


function App({ adminData }) {
  // Destructure with defaults to prevent errors if adminData is initially undefined
  const {
    settings: initialSettings = {},
    imageInfo: initialImageInfo = { pending: { webp: [], avif: [] }, completed: { webp: [], avif: [] }, failed: { webp: [], avif: [] }, skipped: { webp: [], avif: [] } },
    cacheSize: initialCacheSize = '0 B',
    minifiedAssets: initialMinifiedAssets = { js: 0, css: 0 },
    uiData = { availablePostTypes: [] },
    translations = {},
    apiUrl,
    nonce,
  } = adminData || {};

  const [currentView, setCurrentView] = useState('dashboard');
  const [settings, setSettings] = useState(initialSettings);
  const [imageInfo, setImageInfo] = useState(initialImageInfo);
  const [cacheSize, setCacheSize] = useState(initialCacheSize);
  const [minifiedAssets, setMinifiedAssets] = useState(initialMinifiedAssets);
  const [isLoading, setIsLoading] = useState(false); // General loading state for API calls
  const [sidebarOpen, setSidebarOpen] = useState(window.innerWidth >= 768); // Default open on desktop

  // Update local state if adminData props change
  useEffect(() => {
    setSettings(initialSettings);
    setImageInfo(initialImageInfo);
    setCacheSize(initialCacheSize);
    setMinifiedAssets(initialMinifiedAssets);
  }, [initialSettings, initialImageInfo, initialCacheSize, initialMinifiedAssets]);

  const handleSettingChange = useCallback((tabKey, settingKey, value) => {
    setSettings(prevSettings => ({
      ...prevSettings,
      [tabKey]: {
        ...(prevSettings[tabKey] || {}), // Ensure tabKey exists
        [settingKey]: value,
      },
    }));
  }, []);

  const saveSettingsForTab = useCallback(async (tabKey) => {
    if (!settings[tabKey]) {
      console.warn(`No settings found for tab: ${tabKey}`);
      // toast.warn(`No settings to save for this tab.`);
      return;
    }
    setIsLoading(true);
    try {
      const response = await fetch(`${apiUrl}settings`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({
          tab: tabKey,
          settings: settings[tabKey],
        }),
      });
      const result = await response.json();
      if (result.success) {
        // Update settings from response if backend modifies/confirms them
        if (result.data.new_settings) {
          setSettings(result.data.new_settings);
        }
        // toast.success(translations.settingsSaved || 'Settings saved!');
        console.log(translations.settingsSaved || 'Settings saved!');
      } else {
        // toast.error(result.data?.message || translations.errorSavingSettings || 'Error saving settings.');
        console.error(translations.errorSavingSettings || 'Error saving settings:', result.data?.message);
      }
    } catch (error) {
      // toast.error(translations.errorSavingSettings || 'Error saving settings.');
      console.error(translations.errorSavingSettings || 'Error saving settings:', error);
    } finally {
      setIsLoading(false);
    }
  }, [apiUrl, nonce, settings, translations]);


  // Define menu items here for easier management
  const menuItems = [
    { id: 'dashboard', label: translations.dashboard || 'Dashboard', icon: faTachometerAlt, component: Dashboard, hasSaveButton: false },
    { id: 'fileOptimization', label: translations.fileOptimization || 'File Optimization', icon: faFileCode, component: FileOptimization, tabKey: 'file_optimisation', hasSaveButton: true },
    { id: 'imageOptimization', label: translations.imageOptimization || 'Image Optimization', icon: faImages, component: ImageOptimization, tabKey: 'image_optimisation', hasSaveButton: true },
    { id: 'preloadSettings', label: translations.preloadSettings || 'Preload & Preconnect', icon: faRocket, component: PreloadSettings, tabKey: 'preload_settings', hasSaveButton: true },
    { id: 'tools', label: translations.tools || 'Tools', icon: faTools, component: Tools, hasSaveButton: false },
    { id: 'activityLog', label: translations.activityLog || 'Activity Log', icon: faHistory, component: ActivityLog, hasSaveButton: false },
  ];

  const activeMenuItem = menuItems.find(item => item.id === currentView) || menuItems[0];
  const ActiveComponent = activeMenuItem.component;

  // Effect for sidebar behavior and responsive adjustments
  useEffect(() => {
    const handleResize = () => {
      if (window.innerWidth >= 768) {
        setSidebarOpen(true); // Keep sidebar open on desktop
      } else {
        setSidebarOpen(false); // Default to closed on mobile, user can toggle
      }
    };
    const handleClickOutside = (event) => {
      if (window.innerWidth < 768 && sidebarOpen) {
        const sidebar = document.querySelector('.wppo-sidebar');
        const hamburger = document.querySelector('.wppo-hamburger-menu');
        if (sidebar && !sidebar.contains(event.target) && hamburger && !hamburger.contains(event.target)) {
          setSidebarOpen(false);
        }
      }
    };

    window.addEventListener('resize', handleResize);
    document.addEventListener('mousedown', handleClickOutside);
    handleResize(); // Call on mount

    return () => {
      window.removeEventListener('resize', handleResize);
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [sidebarOpen]);


  if (!adminData) { // Or a more sophisticated loading state check
    return (
      <div className="wppo-container">
        <div className="wppo-loading-placeholder" style={{ textAlign: 'center', padding: '50px' }}>
          <p>{translations.loadingSettings || 'Loading Performance Optimisation Settings...'}</p>
          {/* You can add a spinner component here */}
        </div>
      </div>
    );
  }

  return (
    <div className="wppo-admin-app-wrapper">
      {/* <ToastContainer position="bottom-right" autoClose={3000} /> */}
      <div className="wppo-container">
        <button
          className="wppo-hamburger-menu"
          onClick={() => setSidebarOpen(!sidebarOpen)}
          aria-label={sidebarOpen ? (translations.closeMenu || "Close menu") : (translations.openMenu || "Open menu")}
          aria-expanded={sidebarOpen}
        >
          <FontAwesomeIcon icon={sidebarOpen ? faTimes : faBars} />
        </button>

        <aside className={`wppo-sidebar ${sidebarOpen ? 'wppo-sidebar--open' : 'wppo-sidebar--hide'}`}>
          <h3>{translations.performanceSettings || 'Performance Settings'}</h3>
          <nav>
            <ul>
              {menuItems.map(item => (
                <li
                  key={item.id}
                  className={currentView === item.id ? 'active' : ''}
                  onClick={() => {
                    setCurrentView(item.id);
                    if (window.innerWidth < 768) setSidebarOpen(false);
                  }}
                  role="button"
                  tabIndex={0}
                  onKeyPress={(e) => { if (e.key === 'Enter' || e.key === ' ') setCurrentView(item.id); }}
                >
                  <FontAwesomeIcon icon={item.icon} className="wppo-sidebar-icon" aria-hidden="true" />
                  <span>{item.label}</span>
                </li>
              ))}
            </ul>
          </nav>
        </aside>

        <main className="wppo-content">
          <ActiveComponent
            settings={settings}
            onUpdateSettings={handleSettingChange} // Pass generic handler
            specificSettings={settings[activeMenuItem.tabKey] || {}} // Pass only relevant settings
            tabKey={activeMenuItem.tabKey} // Pass tabKey for specific updates
            imageInfo={imageInfo} // For Dashboard
            cacheSize={cacheSize} // For Dashboard
            minifiedAssets={minifiedAssets} // For Dashboard
            uiData={uiData} // For ImageOptimization (post types)
            translations={translations}
            apiUrl={apiUrl}
            nonce={nonce}
            isLoading={isLoading} // Pass loading state
            setIsLoading={setIsLoading} // Allow components to set loading
            // Pass specific setStates if components need to update global state directly (use with caution)
            setImageInfo={setImageInfo}
            setCacheSize={setCacheSize}
          // toast={toast} // Pass toast instance if using react-toastify
          />
          {activeMenuItem.hasSaveButton && (
            <button
              className="wppo-button submit-button"
              onClick={() => saveSettingsForTab(activeMenuItem.tabKey)}
              disabled={isLoading}
              style={{ marginTop: '20px' }}
            >
              {isLoading ? (translations.saving || 'Saving...') : (translations.saveSettings || 'Save Settings')}
            </button>
          )}
        </main>
      </div>
    </div>
  );
}

export default App;