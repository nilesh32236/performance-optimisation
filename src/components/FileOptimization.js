import { useState, useId } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faCode,
	faRocket,
	faStore,
	faServer,
	faShieldAlt,
	faExclamationTriangle,
	faCheckCircle,
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';

const FileOptimization = ( { options = {} } ) => {
	const translations = wppoSettings.translations;
	const [ activeSubTab, setActiveSubTab ] = useState( 'assets' );

	const defaultSettings = {
		minifyJS: false,
		excludeJS: '',
		minifyCSS: false,
		excludeCSS: '',
		combineCSS: false,
		minifyHTML: false,
		deferJS: false,
		delayJS: false,
		delayJSList: '',
		removeWooCSSJS: false,
		excludeUrlToKeepJSCSS: '',
		removeCssJsHandle: '',
		enableServerRules: false,
		cdnURL: '',
		disableEmojis: false,
		disableEmbeds: false,
		disableDashicons: false,
		disableXMLRPC: false,
		heartbeatControl: 'default',
		...options,
	};

	const [ settings, setSettings ] = useState( defaultSettings );
	const [ isLoading, setIsLoading ] = useState( false );

	const handleSubmit = async ( e ) => {
		if ( e ) e.preventDefault();
		setIsLoading( true );
		try {
			await apiCall( 'update_settings', { tab: 'file_optimisation', settings } );
		} finally {
			setIsLoading( false );
		}
	};

	const subTabs = [
		{ id: 'assets', label: 'Assets', icon: faCode },
		{ id: 'scripts', label: 'Scripts', icon: faRocket },
		{ id: 'ecommerce', label: 'E-Commerce', icon: faStore },
		{ id: 'network', label: 'Network', icon: faServer },
		{ id: 'core', label: 'Core', icon: faShieldAlt },
	];

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title="File Optimization"
				description="Fine-tune how your site delivers CSS, JS, and HTML for maximum performance."
				actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						isLoading={ isLoading }
						onClick={ handleSubmit }
						label="Save Settings"
					/>
				}
			>
				<div className="wppo-sub-tabs">
					{ subTabs.map( ( tab ) => (
						<button
							key={ tab.id }
							className={ `wppo-sub-tab ${ activeSubTab === tab.id ? 'active' : '' }` }
							onClick={ () => setActiveSubTab( tab.id ) }
						>
							<FontAwesomeIcon icon={ tab.icon } />
							{ tab.label }
						</button>
					) ) }
				</div>
			</FeatureHeader>

			<div className="wppo-tab-content">
				{ activeSubTab === 'assets' && (
					<div className="wppo-grid-2-col">
						<FeatureCard title="CSS Optimization" icon={ <FontAwesomeIcon icon={ faCode } /> }>
							<div className="wppo-field-group">
								<div className="wppo-switch-field">
									<div>
										<strong>Minify CSS</strong>
										<p className="wppo-text-muted">Remove whitespace and comments from stylesheets.</p>
									</div>
									<label className="wppo-switch">
										<input type="checkbox" name="minifyCSS" checked={ settings.minifyCSS } onChange={ handleChange( setSettings ) } />
										<span className="wppo-slider"></span>
									</label>
								</div>
								<div className="wppo-switch-field">
									<div>
										<strong>Combine CSS</strong>
										<p className="wppo-text-muted">Merge all CSS into a single file to reduce requests.</p>
									</div>
									<label className="wppo-switch">
										<input type="checkbox" name="combineCSS" checked={ settings.combineCSS } onChange={ handleChange( setSettings ) } />
										<span className="wppo-slider"></span>
									</label>
								</div>
								{ settings.minifyCSS && (
									<div className="wppo-field">
										<label className="field-label">Exclude CSS from Minification</label>
										<textarea className="wppo-textarea" name="excludeCSS" rows="3" placeholder="Handles or partial URLs (one per line)" value={ settings.excludeCSS } onChange={ handleChange( setSettings ) } />
									</div>
								) }
							</div>
						</FeatureCard>

						<FeatureCard title="HTML Optimization" icon={ <FontAwesomeIcon icon={ faCode } /> }>
							<div className="wppo-switch-field">
								<div>
									<strong>Minify HTML</strong>
									<p className="wppo-text-muted">Compress the HTML output of your website.</p>
								</div>
								<label className="wppo-switch">
									<input type="checkbox" name="minifyHTML" checked={ settings.minifyHTML } onChange={ handleChange( setSettings ) } />
									<span className="wppo-slider"></span>
								</label>
							</div>
						</FeatureCard>
					</div>
				) }

				{ activeSubTab === 'scripts' && (
					<div className="wppo-grid-2-col">
						<FeatureCard title="JavaScript Loading" icon={ <FontAwesomeIcon icon={ faRocket } /> }>
							<div className="wppo-field-group">
								<div className="wppo-switch-field">
									<div>
										<strong>Minify JavaScript</strong>
										<p className="wppo-text-muted">Compress JS files to reduce execution time.</p>
									</div>
									<label className="wppo-switch">
										<input type="checkbox" name="minifyJS" checked={ settings.minifyJS } onChange={ handleChange( setSettings ) } />
										<span className="wppo-slider"></span>
									</label>
								</div>
								<div className="wppo-switch-field">
									<div>
										<strong>Defer JavaScript</strong>
										<p className="wppo-text-muted">Load scripts in the background to prevent render blocking.</p>
									</div>
									<label className="wppo-switch">
										<input type="checkbox" name="deferJS" checked={ settings.deferJS } onChange={ handleChange( setSettings ) } />
										<span className="wppo-slider"></span>
									</label>
								</div>
								<div className="wppo-switch-field">
									<div>
										<strong>Delay JavaScript Execution</strong>
										<p className="wppo-text-muted">Delay loading until user interaction (keyboard/mouse).</p>
									</div>
									<label className="wppo-switch">
										<input type="checkbox" name="delayJS" checked={ settings.delayJS } onChange={ handleChange( setSettings ) } />
										<span className="wppo-slider"></span>
									</label>
								</div>
							</div>
						</FeatureCard>

						<FeatureCard title="Script Rules" icon={ <FontAwesomeIcon icon={ faRocket } /> }>
							{ settings.minifyJS && (
								<div className="wppo-field">
									<label className="field-label">Exclude JS from Minification</label>
									<textarea className="wppo-textarea" name="excludeJS" rows="3" placeholder="Handles or partial URLs" value={ settings.excludeJS } onChange={ handleChange( setSettings ) } />
								</div>
							) }
							{ settings.delayJS && (
								<div className="wppo-field" style={ { marginTop: '20px' } }>
									<label className="field-label">Scripts to Delay</label>
									<textarea className="wppo-textarea" name="delayJSList" rows="3" placeholder="Partial URLs or keywords" value={ settings.delayJSList } onChange={ handleChange( setSettings ) } />
									<div className="wppo-notice wppo-notice--warning" style={ { marginTop: '12px' } }>
										<FontAwesomeIcon icon={ faExclamationTriangle } />
										<span>Delaying scripts can break immediate functionality. Test carefully.</span>
									</div>
								</div>
							) }
						</FeatureCard>
					</div>
				) }

				{ activeSubTab === 'ecommerce' && (
					<FeatureCard title="WooCommerce Core" icon={ <FontAwesomeIcon icon={ faStore } /> }>
						<div className="wppo-field-group">
							<div className="wppo-switch-field">
								<div>
									<strong>Optimize WooCommerce Assets</strong>
									<p className="wppo-text-muted">Disable WooCommerce scripts/styles on non-ecommerce pages.</p>
								</div>
								<label className="wppo-switch">
									<input type="checkbox" name="removeWooCSSJS" checked={ settings.removeWooCSSJS } onChange={ handleChange( setSettings ) } />
									<span className="wppo-slider"></span>
								</label>
							</div>

							{ settings.removeWooCSSJS && (
								<>
									<div className="wppo-notice wppo-notice--warning">
										<FontAwesomeIcon icon={ faExclamationTriangle } />
										<span>This may break carts on custom pages. Verify your checkout flow.</span>
									</div>
									<div className="wppo-grid-2-col" style={ { marginTop: '20px' } }>
										<div className="wppo-field">
											<label className="field-label">Keep Assets on these URLs</label>
											<textarea className="wppo-textarea" name="excludeUrlToKeepJSCSS" rows="4" placeholder="e.g. shop/.* (regex supported)" value={ settings.excludeUrlToKeepJSCSS } onChange={ handleChange( setSettings ) } />
										</div>
										<div className="wppo-field">
											<label className="field-label">Remove specific CSS/JS handles</label>
											<textarea className="wppo-textarea" name="removeCssJsHandle" rows="4" placeholder="Handles (one per line)" value={ settings.removeCssJsHandle } onChange={ handleChange( setSettings ) } />
										</div>
									</div>
								</>
							) }
						</div>
					</FeatureCard>
				) }

				{ activeSubTab === 'network' && (
					<div className="wppo-grid-2-col">
						<FeatureCard title="Server Rules" icon={ <FontAwesomeIcon icon={ faServer } /> }>
							<div className="wppo-field-group">
								<div className="wppo-switch-field">
									<div>
										<strong>Enable Server Rules (.htaccess)</strong>
										<p className="wppo-text-muted">Apply performance rules directly at the server level.</p>
									</div>
									<label className="wppo-switch">
										<input type="checkbox" name="enableServerRules" checked={ settings.enableServerRules } onChange={ handleChange( setSettings ) } />
										<span className="wppo-slider"></span>
									</label>
								</div>
								{ settings.enableServerRules && (
									<div className="wppo-notice wppo-notice--warning">
										<FontAwesomeIcon icon={ faExclamationTriangle } />
										<span>This modifies your .htaccess. Ensure you have FTP access for recovery.</span>
									</div>
								) }
							</div>
						</FeatureCard>

						<FeatureCard title="CDN Settings" icon={ <FontAwesomeIcon icon={ faServer } /> }>
							<div className="wppo-field">
								<label className="field-label">CDN Hostname</label>
								<input className="wppo-input" type="text" name="cdnURL" placeholder="https://cdn.example.com" value={ settings.cdnURL } onChange={ handleChange( setSettings ) } />
								<p className="wppo-text-muted" style={ { marginTop: '10px', fontSize: '13px' } }>Your static assets will be rewritten to use this URL.</p>
							</div>
						</FeatureCard>
					</div>
				) }

				{ activeSubTab === 'core' && (
					<div className="wppo-grid-2-col">
						<FeatureCard title="Cleanup Core Bloat" icon={ <FontAwesomeIcon icon={ faShieldAlt } /> }>
							<div className="wppo-field-group">
								<div className="wppo-switch-field">
									<strong>Disable Emojis</strong>
									<label className="wppo-switch">
										<input type="checkbox" name="disableEmojis" checked={ settings.disableEmojis } onChange={ handleChange( setSettings ) } />
										<span className="wppo-slider"></span>
									</label>
								</div>
								<div className="wppo-switch-field">
									<strong>Disable Embeds</strong>
									<label className="wppo-switch">
										<input type="checkbox" name="disableEmbeds" checked={ settings.disableEmbeds } onChange={ handleChange( setSettings ) } />
										<span className="wppo-slider"></span>
									</label>
								</div>
								<div className="wppo-switch-field">
									<strong>Disable Dashicons (Frontend)</strong>
									<label className="wppo-switch">
										<input type="checkbox" name="disableDashicons" checked={ settings.disableDashicons } onChange={ handleChange( setSettings ) } />
										<span className="wppo-slider"></span>
									</label>
								</div>
								<div className="wppo-switch-field">
									<strong>Disable XML-RPC</strong>
									<label className="wppo-switch">
										<input type="checkbox" name="disableXMLRPC" checked={ settings.disableXMLRPC } onChange={ handleChange( setSettings ) } />
										<span className="wppo-slider"></span>
									</label>
								</div>
							</div>
						</FeatureCard>

						<FeatureCard title="Heartbeat Control" icon={ <FontAwesomeIcon icon={ faRocket } /> }>
							<div className="wppo-field">
								<label className="field-label">API Frequency</label>
								<select className="wppo-select" name="heartbeatControl" value={ settings.heartbeatControl } onChange={ handleChange( setSettings ) }>
									<option value="default">Default Mode</option>
									<option value="60s">Reduce Frequency (60s)</option>
									<option value="disable_ext">Disable on Frontend</option>
									<option value="disable_all">Disable Everywhere</option>
								</select>
								<p className="wppo-text-muted" style={ { marginTop: '12px', fontSize: '13px' } }>
									Restricting the Heartbeat API reduces server CPU usage by limiting polling.
								</p>
							</div>
						</FeatureCard>
					</div>
				) }
			</div>
		</div>
	);
};

export default FileOptimization;
