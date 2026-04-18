import { useState } from '@wordpress/element';
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
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import SwitchField from './common/SwitchField';

const FileOptimization = ( { options = {} } ) => {
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
		if ( e ) {
			e.preventDefault();
		}
		setIsLoading( true );
		try {
			await apiCall( 'update_settings', {
				tab: 'file_optimisation',
				settings,
			} );
		} catch ( err ) {
			console.error( 'Failed updating file optimisation settings', err );
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
				<div className="wppo-sub-tabs" role="tablist">
					{ subTabs.map( ( tab ) => (
						<button
							key={ tab.id }
							id={ `tab-${ tab.id }` }
							className={ `wppo-sub-tab ${
								activeSubTab === tab.id ? 'active' : ''
							}` }
							onClick={ () => setActiveSubTab( tab.id ) }
							type="button"
							role="tab"
							aria-selected={ activeSubTab === tab.id }
							aria-controls={ `panel-${ tab.id }` }
						>
							<FontAwesomeIcon icon={ tab.icon } />
							{ tab.label }
						</button>
					) ) }
				</div>
			</FeatureHeader>

			<div className="wppo-tab-content">
				{ activeSubTab === 'assets' && (
					<div
						id="panel-assets"
						className="wppo-grid-2-col"
						role="tabpanel"
						aria-labelledby="tab-assets"
					>
						<FeatureCard
							title="CSS Optimization"
							icon={ <FontAwesomeIcon icon={ faCode } /> }
						>
							<div className="wppo-field-group">
								<SwitchField
									label="Minify CSS"
									description="Remove whitespace and comments from stylesheets."
									name="minifyCSS"
									checked={ settings.minifyCSS }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label="Combine CSS"
									description="Merge all CSS into a single file to reduce requests."
									name="combineCSS"
									checked={ settings.combineCSS }
									onChange={ handleChange( setSettings ) }
								/>
								{ settings.minifyCSS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeCSS"
										>
											Exclude CSS from Minification
										</label>
										<textarea
											className="wppo-textarea"
											id="excludeCSS"
											name="excludeCSS"
											rows="3"
											placeholder="Handles or partial URLs (one per line)"
											value={ settings.excludeCSS }
											onChange={ handleChange(
												setSettings
											) }
										/>
									</div>
								) }
							</div>
						</FeatureCard>

						<FeatureCard
							title="HTML Optimization"
							icon={ <FontAwesomeIcon icon={ faCode } /> }
						>
							<SwitchField
								label="Minify HTML"
								description="Compress the HTML output of your website."
								name="minifyHTML"
								checked={ settings.minifyHTML }
								onChange={ handleChange( setSettings ) }
							/>
						</FeatureCard>
					</div>
				) }

				{ activeSubTab === 'scripts' && (
					<div
						id="panel-scripts"
						className="wppo-grid-2-col"
						role="tabpanel"
						aria-labelledby="tab-scripts"
					>
						<FeatureCard
							title="JavaScript Loading"
							icon={ <FontAwesomeIcon icon={ faRocket } /> }
						>
							<div className="wppo-field-group">
								<SwitchField
									label="Minify JavaScript"
									description="Compress JS files to reduce execution time."
									name="minifyJS"
									checked={ settings.minifyJS }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label="Defer JavaScript"
									description="Load scripts in the background to prevent render blocking."
									name="deferJS"
									checked={ settings.deferJS }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label="Delay JavaScript Execution"
									description="Delay loading until user interaction (keyboard/mouse)."
									name="delayJS"
									checked={ settings.delayJS }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						</FeatureCard>

						{ ( settings.minifyJS || settings.delayJS ) && (
							<FeatureCard
								title="Script Rules"
								icon={ <FontAwesomeIcon icon={ faRocket } /> }
							>
								{ settings.minifyJS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeJS"
										>
											Exclude JS from Minification
										</label>
										<textarea
											className="wppo-textarea"
											id="excludeJS"
											name="excludeJS"
											rows="3"
											placeholder="Handles or partial URLs"
											value={ settings.excludeJS }
											onChange={ handleChange(
												setSettings
											) }
										/>
									</div>
								) }
								{ settings.delayJS && (
									<div className="wppo-field wppo-mt-20">
										<label
											className="wppo-field-label"
											htmlFor="delayJSList"
										>
											Scripts to Delay
										</label>
										<textarea
											className="wppo-textarea"
											id="delayJSList"
											name="delayJSList"
											rows="3"
											placeholder="Partial URLs or keywords"
											value={ settings.delayJSList }
											onChange={ handleChange(
												setSettings
											) }
										/>
										<div className="wppo-notice wppo-notice--warning wppo-mt-12">
											<FontAwesomeIcon
												icon={ faExclamationTriangle }
											/>
											<span>
												Delaying scripts can break
												immediate functionality. Test
												carefully.
											</span>
										</div>
									</div>
								) }
							</FeatureCard>
						) }
					</div>
				) }

				{ activeSubTab === 'ecommerce' && (
					<div
						id="panel-ecommerce"
						role="tabpanel"
						aria-labelledby="tab-ecommerce"
					>
						<FeatureCard
							title="WooCommerce Core"
							icon={ <FontAwesomeIcon icon={ faStore } /> }
						>
							<div className="wppo-field-group">
								<SwitchField
									label="Optimize WooCommerce Assets"
									description="Disable WooCommerce scripts/styles on non-ecommerce pages."
									name="removeWooCSSJS"
									checked={ settings.removeWooCSSJS }
									onChange={ handleChange( setSettings ) }
								/>

								{ settings.removeWooCSSJS && (
									<>
										<div className="wppo-notice wppo-notice--warning">
											<FontAwesomeIcon
												icon={ faExclamationTriangle }
											/>
											<span>
												This may break carts on custom
												pages. Verify your checkout
												flow.
											</span>
										</div>
										<div className="wppo-grid-2-col wppo-mt-20">
											<div className="wppo-field">
												<label
													className="wppo-field-label"
													htmlFor="excludeUrlToKeepJSCSS"
												>
													Keep Assets on these URLs
												</label>
												<textarea
													className="wppo-textarea"
													id="excludeUrlToKeepJSCSS"
													name="excludeUrlToKeepJSCSS"
													rows="4"
													placeholder="e.g. shop/.* (regex supported)"
													value={
														settings.excludeUrlToKeepJSCSS
													}
													onChange={ handleChange(
														setSettings
													) }
												/>
											</div>
											<div className="wppo-field">
												<label
													className="wppo-field-label"
													htmlFor="removeCssJsHandle"
												>
													Remove specific CSS/JS
													handles
												</label>
												<textarea
													className="wppo-textarea"
													id="removeCssJsHandle"
													name="removeCssJsHandle"
													rows="4"
													placeholder="Handles (one per line)"
													value={
														settings.removeCssJsHandle
													}
													onChange={ handleChange(
														setSettings
													) }
												/>
											</div>
										</div>
									</>
								) }
							</div>
						</FeatureCard>
					</div>
				) }

				{ activeSubTab === 'network' && (
					<div
						id="panel-network"
						className="wppo-grid-2-col"
						role="tabpanel"
						aria-labelledby="tab-network"
					>
						<FeatureCard
							title="Server Rules"
							icon={ <FontAwesomeIcon icon={ faServer } /> }
						>
							<div className="wppo-field-group">
								<SwitchField
									label="Enable Server Rules (.htaccess)"
									description="Apply performance rules directly at the server level."
									name="enableServerRules"
									checked={ settings.enableServerRules }
									onChange={ handleChange( setSettings ) }
								/>
								{ settings.enableServerRules && (
									<div className="wppo-notice wppo-notice--warning">
										<FontAwesomeIcon
											icon={ faExclamationTriangle }
										/>
										<span>
											This modifies your .htaccess. Ensure
											you have FTP access for recovery.
										</span>
									</div>
								) }
							</div>
						</FeatureCard>

						<FeatureCard
							title="CDN Settings"
							icon={ <FontAwesomeIcon icon={ faServer } /> }
						>
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="cdnURL"
								>
									CDN Hostname
								</label>
								<input
									className="wppo-input"
									type="url"
									id="cdnURL"
									name="cdnURL"
									placeholder="https://cdn.example.com"
									value={ settings.cdnURL }
									onChange={ handleChange( setSettings ) }
								/>
								<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
									Your static assets will be rewritten to use
									this URL.
								</p>
							</div>
						</FeatureCard>
					</div>
				) }

				{ activeSubTab === 'core' && (
					<div
						id="panel-core"
						className="wppo-grid-2-col"
						role="tabpanel"
						aria-labelledby="tab-core"
					>
						<FeatureCard
							title="Cleanup Core Bloat"
							icon={ <FontAwesomeIcon icon={ faShieldAlt } /> }
						>
							<div className="wppo-field-group">
								<SwitchField
									label="Disable Emojis"
									name="disableEmojis"
									checked={ settings.disableEmojis }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label="Disable Embeds"
									name="disableEmbeds"
									checked={ settings.disableEmbeds }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label="Disable Dashicons (Frontend)"
									name="disableDashicons"
									checked={ settings.disableDashicons }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label="Disable XML-RPC"
									name="disableXMLRPC"
									checked={ settings.disableXMLRPC }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						</FeatureCard>

						<FeatureCard
							title="Heartbeat Control"
							icon={ <FontAwesomeIcon icon={ faRocket } /> }
						>
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="heartbeatControl"
								>
									API Frequency
								</label>
								<select
									className="wppo-select"
									id="heartbeatControl"
									name="heartbeatControl"
									value={ settings.heartbeatControl }
									onChange={ handleChange( setSettings ) }
								>
									<option value="default">
										Default Mode
									</option>
									<option value="60s">
										Reduce Frequency (60s)
									</option>
									<option value="disable_ext">
										Disable on Frontend
									</option>
									<option value="disable_all">
										Disable Everywhere
									</option>
								</select>
								<p
									className="wppo-text-muted"
									style={ {
										marginTop: '12px',
										fontSize: '13px',
									} }
								>
									Restricting the Heartbeat API reduces server
									CPU usage by limiting polling.
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
