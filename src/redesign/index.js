import React from 'react';
import { render } from '@wordpress/element';
import App from './App';

import './style.scss';

const adminData = window.wppoAdminData || {};

render(<App adminData={adminData} />, document.getElementById('wppo-admin-app'));
