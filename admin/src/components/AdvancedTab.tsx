import React, { useState } from 'react';
import { Button, Card, Switch, Input, TextArea, Select, Notice } from '../components/UI';
import { secureApiFetch, sanitizeInput, validateNumber } from '../utils/security';
import { handleApiError, logError } from '../utils/errorHandler';

export const AdvancedTab: React.FC = () => {
	const [settings, setSettings] = useState({
		// WordPress Optimizations
		disable_emojis: true,
		disable_embeds: true,
		disable_xmlrpc: true,
		disable_rest_api: false,
		remove_query_strings: true,
		
		// Database Optimizations
		auto_cleanup: true,
		cleanup_revisions: true,
		cleanup_spam: true,
		cleanup_transients: true,
		
		// Security Enhancements
		hide_wp_version: true,
		disable_file_editing: true,
		
		// Performance Tweaks
		heartbeat_control: true,
		heartbeat_frequency: 60,
		memory_limit: '256M',
		
		// CDN Settings
		cdn_enabled: false,
		cdn_url: '',
		
		// Custom Code
		custom_css: '',
		custom_js: ''
	});

	const [activeSection, setActiveSection] = useState('wordpress');
	const [notification, setNotification] = useState<{type: string, message: string} | null>(null);
	const [saving, setSaving] = useState(false);

	const validateAdvancedSettings = (newSettings: typeof settings) => {
		const warnings: string[] = [];
		
		if (newSettings.disable_rest_api) {
			warnings.push('Disabling REST API may break plugins and themes that depend on it');
		}
		
		if (newSettings.disable_xmlrpc && newSettings.disable_rest_api) {
			warnings.push('Disabling both XML-RPC and REST API may prevent remote publishing');
		}
		
		if (newSettings.cdn_enabled && !newSettings.cdn_url.trim()) {
			warnings.push('CDN URL is required when CDN is enabled');
		}
		
		if (newSettings.cdn_url && !newSettings.cdn_url.match(/^https?:\/\/.+/)) {
			warnings.push('CDN URL must be a valid HTTP/HTTPS URL');
		}
		
		try {
			validateNumber(newSettings.heartbeat_frequency, 15, 300);
		} catch (error) {
			warnings.push('Heartbeat frequency must be between 15 and 300 seconds');
		}
		
		return warnings;
	};

	const handleSettingChange = (key: keyof typeof settings, value: any) => {
		let sanitizedValue = value;
		
		// Sanitize text inputs
		if (typeof value === 'string' && ['cdn_url', 'custom_css', 'custom_js'].includes(key)) {
			sanitizedValue = sanitizeInput(value);
		}
		
		const newSettings = { ...settings, [key]: sanitizedValue };
		const warnings = validateAdvancedSettings(newSettings);
		
		if (warnings.length > 0) {
			setNotification({
				type: 'warning',
				message: warnings.join('. ')
			});
		}
		
		setSettings(newSettings);
	};

	const saveSettings = async () => {
		setSaving(true);
		try {
			const validationErrors = validateAdvancedSettings(settings);
			if (validationErrors.length > 0) {
				throw new Error(validationErrors.join('. '));
			}

			await secureApiFetch('/wp-json/performance-optimisation/v1/advanced/settings', {
				method: 'POST',
				data: settings
			});
			
			setNotification({
				type: 'success',
				message: 'Advanced settings saved successfully!'
			});
		} catch (error) {
			logError(error, { component: 'AdvancedTab', action: 'saveSettings' });
			setNotification({
				type: 'error',
				message: handleApiError(error)
			});
		}
		setSaving(false);
		setTimeout(() => setNotification(null), 5000);
	};

	const runDatabaseCleanup = async () => {
		try {
			const result = await secureApiFetch('/wp-json/performance-optimisation/v1/database/cleanup', {
				method: 'POST',
				data: {
					cleanup_revisions: settings.cleanup_revisions,
					cleanup_spam: settings.cleanup_spam,
					cleanup_transients: settings.cleanup_transients
				}
			});
			
			setNotification({
				type: 'success',
				message: `Database cleanup completed. ${result.items_removed || 0} items removed.`
			});
		} catch (error) {
			logError(error, { component: 'AdvancedTab', action: 'runDatabaseCleanup' });
			setNotification({
				type: 'error',
				message: handleApiError(error)
			});
		}
	};

	const sections = [
		{ id: 'wordpress', label: 'WordPress Optimizations' },
		{ id: 'database', label: 'Database Cleanup' },
		{ id: 'security', label: 'Security Enhancements' },
		{ id: 'performance', label: 'Performance Tweaks' },
		{ id: 'cdn', label: 'CDN Integration' },
		{ id: 'custom', label: 'Custom Code' }
	];

	const renderWordPressSection = () => (
		<Card title="WordPress Optimizations">
			<div className="wppo-settings-list">
				<div className="wppo-setting">
					<Switch 
						checked={settings.disable_emojis}
						onChange={(checked) => setSettings({...settings, disable_emojis: checked})}
					/>
					<div>
						<h4>Disable Emojis</h4>
						<p>Remove emoji scripts and styles</p>
					</div>
				</div>
				<div className="wppo-setting">
					<Switch 
						checked={settings.disable_embeds}
						onChange={(checked) => setSettings({...settings, disable_embeds: checked})}
					/>
					<div>
						<h4>Disable Embeds</h4>
						<p>Remove oEmbed functionality</p>
					</div>
				</div>
				<div className="wppo-setting">
					<Switch 
						checked={settings.disable_xmlrpc}
						onChange={(checked) => setSettings({...settings, disable_xmlrpc: checked})}
					/>
					<div>
						<h4>Disable XML-RPC</h4>
						<p>Disable XML-RPC for security</p>
					</div>
				</div>
				<div className="wppo-setting">
					<Switch 
						checked={settings.remove_query_strings}
						onChange={(checked) => setSettings({...settings, remove_query_strings: checked})}
					/>
					<div>
						<h4>Remove Query Strings</h4>
						<p>Remove version parameters from static resources</p>
					</div>
				</div>
			</div>
		</Card>
	);

	const renderDatabaseSection = () => (
		<Card title="Database Cleanup">
			<div className="wppo-settings-list">
				<div className="wppo-setting">
					<Switch 
						checked={settings.auto_cleanup}
						onChange={(checked) => setSettings({...settings, auto_cleanup: checked})}
					/>
					<div>
						<h4>Automatic Cleanup</h4>
						<p>Run database cleanup weekly</p>
					</div>
				</div>
				<div className="wppo-setting">
					<Switch 
						checked={settings.cleanup_revisions}
						onChange={(checked) => setSettings({...settings, cleanup_revisions: checked})}
					/>
					<div>
						<h4>Remove Post Revisions</h4>
						<p>Delete old post revisions</p>
					</div>
				</div>
				<div className="wppo-setting">
					<Switch 
						checked={settings.cleanup_spam}
						onChange={(checked) => setSettings({...settings, cleanup_spam: checked})}
					/>
					<div>
						<h4>Remove Spam Comments</h4>
						<p>Delete spam and trash comments</p>
					</div>
				</div>
				<div className="wppo-setting">
					<Switch 
						checked={settings.cleanup_transients}
						onChange={(checked) => setSettings({...settings, cleanup_transients: checked})}
					/>
					<div>
						<h4>Clean Transients</h4>
						<p>Remove expired transient data</p>
					</div>
				</div>
			</div>
			<Button variant="secondary">Run Cleanup Now</Button>
		</Card>
	);

	const renderSecuritySection = () => (
		<Card title="Security Enhancements">
			<div className="wppo-settings-list">
				<div className="wppo-setting">
					<Switch 
						checked={settings.hide_wp_version}
						onChange={(checked) => setSettings({...settings, hide_wp_version: checked})}
					/>
					<div>
						<h4>Hide WordPress Version</h4>
						<p>Remove version info from HTML</p>
					</div>
				</div>
				<div className="wppo-setting">
					<Switch 
						checked={settings.disable_file_editing}
						onChange={(checked) => setSettings({...settings, disable_file_editing: checked})}
					/>
					<div>
						<h4>Disable File Editing</h4>
						<p>Prevent theme/plugin editing from admin</p>
					</div>
				</div>
			</div>
		</Card>
	);

	const renderPerformanceSection = () => (
		<Card title="Performance Tweaks">
			<div className="wppo-settings-list">
				<div className="wppo-setting">
					<Switch 
						checked={settings.heartbeat_control}
						onChange={(checked) => setSettings({...settings, heartbeat_control: checked})}
					/>
					<div>
						<h4>Heartbeat Control</h4>
						<p>Optimize WordPress heartbeat frequency</p>
					</div>
				</div>
				<div className="wppo-setting-full">
					<h4>Heartbeat Frequency (seconds)</h4>
					<Select 
						value={settings.heartbeat_frequency}
						onChange={(value) => setSettings({...settings, heartbeat_frequency: parseInt(value)})}
						options={[
							{ value: 15, label: '15 seconds' },
							{ value: 30, label: '30 seconds' },
							{ value: 60, label: '60 seconds' },
							{ value: 120, label: '2 minutes' }
						]}
					/>
				</div>
				<div className="wppo-setting-full">
					<h4>Memory Limit</h4>
					<Select 
						value={settings.memory_limit}
						onChange={(value) => setSettings({...settings, memory_limit: value})}
						options={[
							{ value: '128M', label: '128MB' },
							{ value: '256M', label: '256MB' },
							{ value: '512M', label: '512MB' },
							{ value: '1G', label: '1GB' }
						]}
					/>
				</div>
			</div>
		</Card>
	);

	const renderCDNSection = () => (
		<Card title="CDN Integration">
			<div className="wppo-settings-list">
				<div className="wppo-setting">
					<Switch 
						checked={settings.cdn_enabled}
						onChange={(checked) => setSettings({...settings, cdn_enabled: checked})}
					/>
					<div>
						<h4>Enable CDN</h4>
						<p>Use Content Delivery Network for static assets</p>
					</div>
				</div>
				{settings.cdn_enabled && (
					<div className="wppo-setting-full">
						<h4>CDN URL</h4>
						<Input 
							value={settings.cdn_url}
							onChange={(value) => setSettings({...settings, cdn_url: value})}
							placeholder="https://cdn.example.com"
						/>
						<p>Enter your CDN domain (without trailing slash)</p>
					</div>
				)}
			</div>
		</Card>
	);

	const renderCustomSection = () => (
		<Card title="Custom Code">
			<div className="wppo-settings-list">
				<div className="wppo-setting-full">
					<h4>Custom CSS</h4>
					<TextArea 
						value={settings.custom_css}
						onChange={(value) => setSettings({...settings, custom_css: value})}
						placeholder="/* Add your custom CSS here */"
						rows={8}
					/>
				</div>
				<div className="wppo-setting-full">
					<h4>Custom JavaScript</h4>
					<TextArea 
						value={settings.custom_js}
						onChange={(value) => setSettings({...settings, custom_js: value})}
						placeholder="// Add your custom JavaScript here"
						rows={8}
					/>
				</div>
			</div>
		</Card>
	);

	const renderSection = () => {
		switch (activeSection) {
			case 'wordpress': return renderWordPressSection();
			case 'database': return renderDatabaseSection();
			case 'security': return renderSecuritySection();
			case 'performance': return renderPerformanceSection();
			case 'cdn': return renderCDNSection();
			case 'custom': return renderCustomSection();
			default: return renderWordPressSection();
		}
	};

	return (
		<div className="wppo-advanced-tab">
			<div className="wppo-advanced-layout">
				{/* Section Navigation */}
				<div className="wppo-section-nav">
					{sections.map(section => (
						<button
							key={section.id}
							className={`wppo-nav-item ${activeSection === section.id ? 'active' : ''}`}
							onClick={() => setActiveSection(section.id)}
						>
							{section.label}
						</button>
					))}
				</div>

				{/* Section Content */}
				<div className="wppo-section-content">
					{renderSection()}
				</div>
			</div>

			<div className="wppo-save-bar">
				<Button variant="primary">Save Advanced Settings</Button>
				<Button variant="secondary">Reset to Defaults</Button>
			</div>
		</div>
	);
};
