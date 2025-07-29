import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faTachometerAlt,
  faFileCode,
  faImages,
  faRocket,
  faTools,
  faHistory,
  faDatabase,
  faCloudUploadAlt,
  faCss3,
} from '@fortawesome/free-brands-svg-icons';

function Sidebar({ adminData, currentView, setCurrentView, setSidebarOpen }) {
  const { translations = {} } = adminData || {};

  const menuItems = [
    { id: 'dashboard', label: translations.dashboard || 'Dashboard', icon: faTachometerAlt },
    { id: 'fileOptimization', label: translations.fileOptimization || 'File Optimization', icon: faFileCode },
    { id: 'imageOptimization', label: translations.imageOptimization || 'Image Optimization', icon: faImages },
    { id: 'preloadSettings', label: translations.preloadSettings || 'Preload & Preconnect', icon: faRocket },
    { id: 'tools', label: translations.tools || 'Tools', icon: faTools },
    { id: 'database', label: translations.database || 'Database', icon: 'faDatabase' },
    { id: 'cdn', label: translations.cdn || 'CDN', icon: 'faCloudUploadAlt' },
    { id: 'criticalCss', label: translations.criticalCss || 'Critical CSS', icon: 'faCss3' },
    { id: 'activityLog', label: translations.activityLog || 'Activity Log', icon: faHistory },
  ];

  return (
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
  );
}

export default Sidebar;
