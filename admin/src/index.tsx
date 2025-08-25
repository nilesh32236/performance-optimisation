/**
 * Admin Interface Entry Point
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

import React from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import './styles/main.scss';

// Initialize the admin interface when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('wppo-admin-root');
  
  if (container) {
    const root = createRoot(container);
    root.render(<App />);
  }
});