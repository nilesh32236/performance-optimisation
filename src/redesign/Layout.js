import React, { useState, useEffect } from 'react';
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
import Sidebar from './Sidebar';

function Layout({ adminData, children, currentView, setCurrentView }) {
  const { translations = {} } = adminData || {};
  const [sidebarOpen, setSidebarOpen] = useState(window.innerWidth >= 768);

  useEffect(() => {
    const handleResize = () => {
      if (window.innerWidth >= 768) {
        setSidebarOpen(true);
      } else {
        setSidebarOpen(false);
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
    handleResize();

    return () => {
      window.removeEventListener('resize', handleResize);
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [sidebarOpen]);

  return (
    <div className="wppo-admin-app-wrapper">
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
          <Sidebar
            adminData={adminData}
            currentView={currentView}
            setCurrentView={setCurrentView}
            setSidebarOpen={setSidebarOpen}
          />
        </aside>

        <main className="wppo-content">
          {children}
        </main>
      </div>
    </div>
  );
}

export default Layout;
