import { useState, useId } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import CheckboxOption from './common/CheckboxOption';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faCode,
	faRocket,
	faStore,
	faServer,
	faExclamationTriangle,
	faShieldAlt,
} from '@fortawesome/free-solid-svg-icons';

const FileOptimization = ( { options = {} } ) => {
	const translations = wppoSettings.translations;

	const defaultSettings = {
		minifyJS: false,
		excludeJS: '',
		minifyCSS: false,
		excludeCSS: '',
		combineCSS: false,
		excludeCombineCSS: '',
		removeWooCSSJS: false,
		excludeUrlToKeepJSCSS:
			'shop/(.*)\nproduct/(.*)\nmy-account/(.*)\ncart/(.*)\ncheckout/(.*)',
		removeCssJsHandle:
			'style: woocommerce-layout\nstyle: woocommerce-general\nstyle: woocommerce-smallscreen\nstyle: wc-blocks-style\nscript: woocommerce\nscript: wc-cart-fragments\nscript: wc-add-to-cart\nscript: jquery-blockui\nscript: wc-order-attribution\nscript: sourcebuster-js',
		minifyHTML: false,
		deferJS: false,
		excludeDeferJS: '',
		delayJS: false,
		excludeDelayJS: '',
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
	const [ activeSubTab, setActiveSubTab ] = useState( 'basic' );
	const [ isLoading, setIsLoading ] = useState( false );
	const excludeUrlId = useId();
	const cssJsHandleId = useId();
	const cdnUrlId = useId();
	const heartbeatId = useId();

	const subTabs = [
		{ id: 'basic', label: 'Basic', icon: faCode },
		{ id: 'advanced', label: 'Advanced', icon: faRocket },
		{ id: 'ecommerce', label: 'E-commerce', icon: faStore },
		{ id: 'network', label: 'Network', icon: faServer },
		{
			id: 'core',
			label: translations.coreTweaks || 'Core Tweaks',
			icon: faShieldAlt,
		},
	];

	const handleSubmit = async ( e ) => {
		e.preventDefault();
		setIsLoading( true );
		try {
			await apiCall( 'update_settings', {
				tab: 'file_optimisation',
				settings,
			} );
		} catch ( error ) {
			console.error( translations.formSubmissionError, error );
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<form onSubmit={ handleSubmit } className="settings-form fadeIn">
			<div className="settings-header-flex">
				<h2>{ translations.fileOptimizationSettings }</h2>
			</div>

			<div className="wppo-sub-tabs-container">
				<div
					className="wppo-sub-tabs"
					role="tablist"
					aria-label={
						translations.fileOptimizationTabs ||
						'File Optimization Tabs'
					}
				>
					{ subTabs.map( ( tab ) => (
						<button
							key={ tab.id }
							id={ `tab-${ tab.id }` }
							type="button"
							role="tab"
							aria-selected={ activeSubTab === tab.id }
							aria-controls={ `tabpanel-${ tab.id }` }
							tabIndex={ activeSubTab === tab.id ? 0 : -1 }
							className={ `sub-tab-item ${
								activeSubTab === tab.id ? 'active' : ''
							}` }
							onClick={ () => setActiveSubTab( tab.id ) }
							onKeyDown={ ( e ) => {
								if (
									e.key === 'ArrowRight' ||
									e.key === 'ArrowLeft'
								) {
									e.preventDefault();
									const currentIndex = subTabs.findIndex(
										( t ) => t.id === activeSubTab
									);
									let newIndex = currentIndex;
									if ( e.key === 'ArrowRight' ) {
										newIndex =
											( currentIndex + 1 ) %
											subTabs.length;
									} else if ( e.key === 'ArrowLeft' ) {
										newIndex =
											( currentIndex -
												1 +
												subTabs.length ) %
											subTabs.length;
									}
									if ( newIndex !== currentIndex ) {
										setActiveSubTab(
											subTabs[ newIndex ].id
										);
										document
											.getElementById(
												`tab-${ subTabs[ newIndex ].id }`
											)
											?.focus();
									}
								}
							} }
						>
							<FontAwesomeIcon icon={ tab.icon } />
							<span>{ tab.label }</span>
						</button>
					) ) }
				</div>
			</div>

			<div className="sub-tab-content fadeIn">
				<div
					className="feature-card"
					id="tabpanel-basic"
					role="tabpanel"
					aria-labelledby="tab-basic"
					hidden={ activeSubTab !== 'basic' }
				>
					<h3>
						<FontAwesomeIcon icon={ faCode } /> Basic Optimization
					</h3>
					<p>
						Essential minification settings to reduce asset file
						sizes and improve page load speed.
					</p>

					<CheckboxOption
						label={ translations.minifyJS }
						checked={ settings.minifyJS }
						onChange={ handleChange( setSettings ) }
						name="minifyJS"
						textareaName="excludeJS"
						textareaPlaceholder={ translations.excludeJSFiles }
						textareaValue={ settings.excludeJS }
						onTextareaChange={ handleChange( setSettings ) }
						description={
							translations.minifyJSDesc ||
							'Remove whitespace and comments from JavaScript files to reduce payload size.'
						}
					/>

					<CheckboxOption
						label={ translations.minifyCSS }
						checked={ settings.minifyCSS }
						onChange={ handleChange( setSettings ) }
						name="minifyCSS"
						textareaName="excludeCSS"
						textareaPlaceholder={ translations.excludeCSSFiles }
						textareaValue={ settings.excludeCSS }
						onTextareaChange={ handleChange( setSettings ) }
						description={
							translations.minifyCSSDesc ||
							'Remove whitespace and comments from CSS files to reduce payload size.'
						}
					/>

					<CheckboxOption
						label={ translations.minifyHTML }
						checked={ settings.minifyHTML }
						onChange={ handleChange( setSettings ) }
						name="minifyHTML"
						description={
							translations.minifyHTMLDesc ||
							'Remove unnecessary whitespace and comments from HTML to reduce page size.'
						}
					/>
				</div>

				<div
					className="feature-card"
					id="tabpanel-advanced"
					role="tabpanel"
					aria-labelledby="tab-advanced"
					hidden={ activeSubTab !== 'advanced' }
				>
					<h3>
						<FontAwesomeIcon icon={ faRocket } /> Advanced Delivery
					</h3>
					<p>
						Advanced techniques like combining assets and deferring
						execution for maximum performance.
					</p>

					<CheckboxOption
						label={ translations.combineCSS }
						checked={ settings.combineCSS }
						onChange={ handleChange( setSettings ) }
						name="combineCSS"
						textareaName="excludeCombineCSS"
						textareaPlaceholder={ translations.excludeCombineCSS }
						textareaValue={ settings.excludeCombineCSS }
						onTextareaChange={ handleChange( setSettings ) }
						description={
							translations.combineCSSDesc ||
							'Merge multiple CSS files into a single file to reduce HTTP requests.'
						}
					/>

					<CheckboxOption
						label={ translations.deferJS }
						checked={ settings.deferJS }
						onChange={ handleChange( setSettings ) }
						name="deferJS"
						textareaName="excludeDeferJS"
						textareaPlaceholder={ translations.excludeDeferJS }
						textareaValue={ settings.excludeDeferJS }
						onTextareaChange={ handleChange( setSettings ) }
						description={
							translations.deferJSDesc ||
							'Delay the execution of JavaScript until the HTML parser has finished to improve render time.'
						}
					/>
					{ settings.deferJS && (
						<div className="wppo-notice wppo-notice--warning">
							<FontAwesomeIcon icon={ faExclamationTriangle } />
							<span>
								{ translations.deferJSWarning ||
									'This may affect inline scripts. Test your site thoroughly after enabling. Use the exclusion list above for any scripts that break.' }
							</span>
						</div>
					) }

					<CheckboxOption
						label={ translations.delayJS }
						checked={ settings.delayJS }
						onChange={ handleChange( setSettings ) }
						name="delayJS"
						textareaName="excludeDelayJS"
						textareaPlaceholder={ translations.excludeDelayJS }
						textareaValue={ settings.excludeDelayJS }
						onTextareaChange={ handleChange( setSettings ) }
						description={
							translations.delayJSDesc ||
							'Delay JavaScript execution until user interaction (e.g., scroll, click) to boost initial page speed.'
						}
					/>
					{ settings.delayJS && (
						<div className="wppo-notice wppo-notice--warning">
							<FontAwesomeIcon icon={ faExclamationTriangle } />
							<span>
								{ translations.delayJSWarning ||
									'Delayed scripts will not execute until user interaction. This can break scripts that need to run immediately. Test carefully.' }
							</span>
						</div>
					) }
				</div>

				<div
					className="feature-card"
					id="tabpanel-ecommerce"
					role="tabpanel"
					aria-labelledby="tab-ecommerce"
					hidden={ activeSubTab !== 'ecommerce' }
				>
					<h3>
						<FontAwesomeIcon icon={ faStore } /> WooCommerce Core
					</h3>
					<p>
						Optimize WooCommerce assets specifically for
						non-e-commerce pages to prevent bloat.
					</p>

					<CheckboxOption
						label={ translations.removeWooCSSJS }
						checked={ settings.removeWooCSSJS }
						onChange={ handleChange( setSettings ) }
						name="removeWooCSSJS"
						description="Disable WooCommerce scripts and styles on regular pages while keeping them on store pages."
					>
						{ settings.removeWooCSSJS && (
							<div
								className="wppo-notice wppo-notice--warning"
								style={ { marginBottom: '0.75rem' } }
							>
								<FontAwesomeIcon
									icon={ faExclamationTriangle }
								/>
								<span>
									{ translations.removeWooCSSJSWarning ||
										'Removing WooCommerce assets can break cart, checkout, or product pages if URLs or handles are wrong. Test store flows after enabling.' }
								</span>
							</div>
						) }
						{ settings.removeWooCSSJS && (
							<div
								style={ {
									display: 'grid',
									gridTemplateColumns: '1fr 1fr',
									gap: '20px',
								} }
							>
								<div className="setting-group">
									<label
										className="field-label"
										htmlFor={ excludeUrlId }
									>
										{ translations.excludeUrlToKeepJSCSS }
									</label>
									<textarea
										id={ excludeUrlId }
										className="text-area-field"
										placeholder="URLs to keep assets (e.g. shop/.*)"
										name="excludeUrlToKeepJSCSS"
										value={ settings.excludeUrlToKeepJSCSS }
										onChange={ handleChange( setSettings ) }
									/>
								</div>
								<div className="setting-group">
									<label
										className="field-label"
										htmlFor={ cssJsHandleId }
									>
										{ translations.removeCssJsHandle }
									</label>
									<textarea
										id={ cssJsHandleId }
										className="text-area-field"
										placeholder="Handles to remove (one per line)"
										name="removeCssJsHandle"
										value={ settings.removeCssJsHandle }
										onChange={ handleChange( setSettings ) }
									/>
								</div>
							</div>
						) }
					</CheckboxOption>
				</div>

				<div
					className="feature-card"
					id="tabpanel-network"
					role="tabpanel"
					aria-labelledby="tab-network"
					hidden={ activeSubTab !== 'network' }
				>
					<h3>
						<FontAwesomeIcon icon={ faServer } /> Network &
						Infrastructure
					</h3>
					<p>
						Configure advanced network behaviors and Content
						Delivery Network integration.
					</p>

					<CheckboxOption
						label={ translations.enableServerRules }
						checked={ settings.enableServerRules }
						onChange={ handleChange( setSettings ) }
						name="enableServerRules"
						description={ translations.enableServerRulesDesc }
					/>
					{ settings.enableServerRules && (
						<div className="wppo-notice wppo-notice--warning">
							<FontAwesomeIcon icon={ faExclamationTriangle } />
							<span>
								{ translations.serverRulesWarning ||
									'This modifies your .htaccess file. Ensure you have a backup. If your site becomes inaccessible, revert via FTP.' }
							</span>
						</div>
					) }

					<div
						className="setting-group"
						style={ { marginTop: '24px' } }
					>
						<label className="field-label" htmlFor={ cdnUrlId }>
							{ translations.cdnURL }
						</label>
						<input
							id={ cdnUrlId }
							type="text"
							className="input-field"
							placeholder={
								translations.cdnURLPlaceholder ||
								'https://cdn.example.com'
							}
							name="cdnURL"
							value={ settings.cdnURL }
							onChange={ handleChange( setSettings ) }
						/>
					</div>
				</div>

				<div
					className="feature-card"
					id="tabpanel-core"
					role="tabpanel"
					aria-labelledby="tab-core"
					hidden={ activeSubTab !== 'core' }
				>
					<h3>
						<FontAwesomeIcon icon={ faShieldAlt } />{ ' ' }
						{ translations.coreTweaks || 'Core Tweaks' }
					</h3>
					<p>
						{ translations.coreTweaksIntro ||
							'Disable unnecessary WordPress core features to reduce database weight and frontend requests.' }
					</p>

					<CheckboxOption
						label={ translations.disableEmojis || 'Disable Emojis' }
						checked={ settings.disableEmojis }
						onChange={ handleChange( setSettings ) }
						name="disableEmojis"
						description={
							translations.disableEmojisDesc ||
							'Removes the extra inline JS and wp-emoji-release.min.js file loaded on every page.'
						}
					/>

					<CheckboxOption
						label={ translations.disableEmbeds || 'Disable Embeds' }
						checked={ settings.disableEmbeds }
						onChange={ handleChange( setSettings ) }
						name="disableEmbeds"
						description={
							translations.disableEmbedsDesc ||
							'Removes the wp-embed.min.js script if you do not embed WordPress content from other sites.'
						}
					/>

					<CheckboxOption
						label={
							translations.disableDashicons ||
							'Disable Dashicons on Frontend'
						}
						checked={ settings.disableDashicons }
						onChange={ handleChange( setSettings ) }
						name="disableDashicons"
						description={
							translations.disableDashiconsDesc ||
							'Prevents the heavy Dashicons CSS from loading for non-logged-in users.'
						}
					/>

					<CheckboxOption
						label={
							translations.disableXMLRPC || 'Disable XML-RPC'
						}
						checked={ settings.disableXMLRPC }
						onChange={ handleChange( setSettings ) }
						name="disableXMLRPC"
						description={
							translations.disableXMLRPCDesc ||
							'Security & performance fix that stops brute-force pingback attacks draining server CPU.'
						}
					/>

					<div
						className="setting-group"
						style={ { marginTop: '24px' } }
					>
						<label className="field-label" htmlFor={ heartbeatId }>
							{ translations.heartbeatControl ||
								'Heartbeat API Control' }
						</label>
						<p
							className="field-description"
							style={ { marginBottom: '12px' } }
						>
							{ translations.heartbeatControlDesc ||
								'The Heartbeat API pings admin-ajax.php frequently, causing CPU spikes. Control its behavior here.' }
						</p>
						<select
							id={ heartbeatId }
							className="input-field"
							name="heartbeatControl"
							value={ settings.heartbeatControl }
							onChange={ handleChange( setSettings ) }
						>
							<option value="default">
								{ translations.heartbeatOptDefault ||
									'Default Mode' }
							</option>
							<option value="60s">
								{ translations.heartbeatOpt60s ||
									'Reduce Frequency (60 Seconds)' }
							</option>
							<option value="disable_ext">
								{ translations.heartbeatOptDisableExt ||
									'Disable on Frontend Only' }
							</option>
							<option value="disable_all">
								{ translations.heartbeatOptDisableAll ||
									'Disable Everywhere' }
							</option>
						</select>
					</div>
				</div>
			</div>

			<div
				style={ {
					marginTop: '40px',
					display: 'flex',
					justifyContent: 'flex-end',
				} }
			>
				<LoadingSubmitButton
					isLoading={ isLoading }
					label={ translations.saveSettings }
					loadingLabel={ translations.saving }
				/>
			</div>
		</form>
	);
};

export default FileOptimization;
