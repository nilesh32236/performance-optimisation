import React, { useState } from 'react';
import Layout from './Layout';

import Dashboard from './Dashboard';
import FileOptimization from './FileOptimization';
import ImageOptimization from './ImageOptimization';
import PreloadSettings from './PreloadSettings';
import Tools from './Tools';
import ActivityLog from './ActivityLog';
import Database from './Database';
import CDN from './CDN';
import CriticalCss from './CriticalCss';

function App({ adminData }) {
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
  const [isLoading, setIsLoading] = useState(false);

  const handleSettingChange = (tabKey, settingKey, value) => {
    setSettings(prevSettings => ({
      ...prevSettings,
      [tabKey]: {
        ...(prevSettings[tabKey] || {}),
        [settingKey]: value,
      },
    }));
  };

  const saveSettingsForTab = async (tabKey) => {
    if (!settings[tabKey]) {
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
        if (result.data.new_settings) {
          setSettings(result.data.new_settings);
        }
      }
    } catch (error) {
      console.error('Error saving settings:', error);
    } finally {
      setIsLoading(false);
    }
  };

  const components = {
    dashboard: Dashboard,
    fileOptimization: FileOptimization,
    imageOptimization: ImageOptimization,
    preloadSettings: PreloadSettings,
    tools: Tools,
    database: Database,
    cdn: CDN,
    criticalCss: CriticalCss,
    activityLog: ActivityLog,
  };

  const ActiveComponent = components[currentView];

  return (
    <Layout
      adminData={adminData}
      currentView={currentView}
      setCurrentView={setCurrentView}
    >
      <ActiveComponent
        adminData={adminData}
        onUpdateSettings={handleSettingChange}
        specificSettings={settings[currentView] || {}}
        isLoading={isLoading}
        saveSettingsForTab={saveSettingsForTab}
      />
    </Layout>
  );
}

export default App;
