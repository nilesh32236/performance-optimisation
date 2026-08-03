import { __ } from '@wordpress/i18n';
import { useState, useRef, useEffect } from '@wordpress/element';
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
	faSpinner,
} from '@fortawesome/free-solid-svg-icons';
import Tooltip from './common/Tooltip';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import SwitchField from './common/SwitchField';

import CriticalCssPanel from './CriticalCssPanel';

const FileOptimization = ( {
	options = {},
	serverRules = null,
	serverRulesError = false,
	ccssStatus = {},
	onRetryServerRules,
	onCcssRefresh,
} ) => {
	const [ activeSubTab, setActiveSubTab ] = useState( 'assets' );
	const tabRefs = useRef( {} );

	const defaultSettings = {
		minifyJS: false,
		excludeJS: '',
		minifyCSS: false,
		excludeCSS: '',
		combineCSS: false,
		excludeCombineCSS: '',
		minifyHTML: false,
		deferJS: false,
		excludeDeferJS: '',
		delayJS: false,
		excludeDelayJS: '',
		delayJSDefaultStrategy: options.delayJSDefaultStrategy || 'interaction',
		delayJSIdleList: options.delayJSIdleList || '',
		delayJSViewportList: options.delayJSViewportList || '',
		delayJSPriority: options.delayJSPriority || '',
		delayJSIdleTimeout: options.delayJSIdleTimeout || 3000,
		removeWooCSSJS: false,
		excludeUrlToKeepJSCSS: '',
		removeCssJsHandle: '',
		enableServerRules: false,
		criticalCSS: false,
		hostGoogleFontsLocally: false,
		cdnURL: '',
		removeUnusedCSS: false,
		excludeUnusedCSS: '',
		disableEmojis: false,
		disableEmbeds: false,
		disableDashicons: false,
		disableXMLRPC: false,
		blockAssetsOnDemand: false,
		loadAllCoreBlockAssets: false,
		heartbeatControl: 'default',
		minifyInlineCSS: false,
		minifyInlineJS: false,
		removeHTMLComments: true,
		...options,
	};

	const [ settings, setSettings ] = useState( defaultSettings );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ notification, setNotification ] = useState( {
		message: '',
		success: false,
	} );
	const notificationTimer = useRef( null );

	const withNotification = async (
		apiCallPromise,
		successMessage,
		errorMessage
	) => {
		setIsLoading( true );
		setNotification( { message: '', success: false } );

		// Clear any existing auto-dismiss timer to avoid stale timeout hazard.
		if ( notificationTimer.current ) {
			clearTimeout( notificationTimer.current );
		}

		try {
			const res = await apiCallPromise;
			if ( res.success ) {
				setNotification( {
					message: res.message || successMessage,
					success: true,
				} );
			} else {
				setNotification( {
					message: res.message || errorMessage,
					success: false,
				} );
			}
		} catch ( err ) {
			console.error( errorMessage, err );
			setNotification( {
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				success: false,
			} );
		} finally {
			setIsLoading( false );
			notificationTimer.current = setTimeout( () => {
				setNotification( { message: '', success: false } );
				notificationTimer.current = null;
			}, 3000 );
		}
	};

	const handleRegenerateCss = async () => {
		try {
			await apiCall( 'regenerate_ccss' );
			if ( onCcssRefresh ) {
				onCcssRefresh();
			}
		} catch ( err ) {
			console.error( 'Failed to regenerate CCSS', err );
		}
	};

	const handleRegenerateUsedCSS = async () => {
		await withNotification(
			( async () => {
				const saveRes = await apiCall( 'update_settings', {
					tab: 'file_optimisation',
					settings: {
						...settings,
						delayJSDefaultStrategy: settings.delayJSDefaultStrategy,
						delayJSIdleList: settings.delayJSIdleList,
						delayJSViewportList: settings.delayJSViewportList,
						delayJSPriority: settings.delayJSPriority,
						delayJSIdleTimeout: settings.delayJSIdleTimeout,
					},
				} );
				if ( ! saveRes.success ) {
					return saveRes;
				}
				return await apiCall( 'used_css_regenerate' );
			} )(),
			__( 'Used CSS regeneration queued.', 'performance-optimisation' ),
			__( 'Failed to regenerate used CSS.', 'performance-optimisation' )
		);
	};

	const handleSubmit = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		await withNotification(
			apiCall( 'update_settings', {
				tab: 'file_optimisation',
				settings: {
					...settings,
					delayJSDefaultStrategy: settings.delayJSDefaultStrategy,
					delayJSIdleList: settings.delayJSIdleList,
					delayJSViewportList: settings.delayJSViewportList,
					delayJSPriority: settings.delayJSPriority,
					delayJSIdleTimeout: settings.delayJSIdleTimeout,
				},
			} ),
			__( 'Settings updated successfully.', 'performance-optimisation' ),
			__( 'Failed to update settings.', 'performance-optimisation' )
		);
	};

	const subTabs = [
		{
			id: 'assets',
			label: __( 'Assets', 'performance-optimisation' ),
			icon: faCode,
		},
		{
			id: 'scripts',
			label: __( 'Scripts', 'performance-optimisation' ),
			icon: faRocket,
		},
		{
			id: 'ecommerce',
			label: __( 'E-Commerce', 'performance-optimisation' ),
			icon: faStore,
		},
		{
			id: 'network',
			label: __( 'Network', 'performance-optimisation' ),
			icon: faServer,
		},
		{
			id: 'core',
			label: __( 'Core', 'performance-optimisation' ),
			icon: faShieldAlt,
		},
	];
	const handleSubTabKeyDown = ( e, index ) => {
		let nextIndex;
		if ( e.key === 'ArrowRight' ) {
			nextIndex = ( index + 1 ) % subTabs.length;
		} else if ( e.key === 'ArrowLeft' ) {
			nextIndex = ( index - 1 + subTabs.length ) % subTabs.length;
		} else {
			return;
		}

		e.preventDefault();
		const nextTab = subTabs[ nextIndex ];
		setActiveSubTab( nextTab.id );

		// Move focus to the next button.
		const nextButton = tabRefs.current[ nextTab.id ];
		if ( nextButton ) {
			nextButton.focus();
		}
	};

	useEffect( () => {
		return () => {
			if ( notificationTimer.current ) {
				clearTimeout( notificationTimer.current );
				notificationTimer.current = null;
			}
		};
	}, [] );

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'File Optimization', 'performance-optimisation' ) }
				description={ __(
					'Fine-tune how your site delivers CSS, JS, and HTML for maximum performance.',
					'performance-optimisation'
				) }
				actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						isLoading={ isLoading }
						onClick={ handleSubmit }
						label={ __(
							'Save Settings',
							'performance-optimisation'
						) }
					/>
				}
			>
				{ notification.message && (
					<div
						className={ `wppo-notice wppo-notice--${
							notification.success ? 'success' : 'error'
						} wppo-mb-20` }
						role="alert"
						aria-live="polite"
					>
						<FontAwesomeIcon
							icon={
								notification.success
									? faCheckCircle
									: faExclamationTriangle
							}
						/>
						<span>{ notification.message }</span>
					</div>
				) }

				<div className="wppo-sub-tabs" role="tablist">
					{ subTabs.map( ( tab, index ) => (
						<button
							key={ tab.id }
							id={ `tab-${ tab.id }` }
							ref={ ( el ) => ( tabRefs.current[ tab.id ] = el ) }
							className={ `wppo-sub-tab${
								activeSubTab === tab.id
									? ' wppo-sub-tab--active'
									: ''
							}` }
							onClick={ () => setActiveSubTab( tab.id ) }
							onKeyDown={ ( e ) =>
								handleSubTabKeyDown( e, index )
							}
							type="button"
							role="tab"
							tabIndex={ activeSubTab === tab.id ? 0 : -1 }
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
						className="wppo-stacked-cards"
						role="tabpanel"
						aria-labelledby="tab-assets"
					>
						<FeatureCard
							title={ __(
								'CSS Optimization',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faCode } /> }
						>
							<div className="wppo-field-group">
								<SwitchField
									label={ __(
										'Minify CSS',
										'performance-optimisation'
									) }
									description={ __(
										'Remove whitespace and comments from stylesheets to reduce file size.',
										'performance-optimisation'
									) }
									name="minifyCSS"
									checked={ settings.minifyCSS }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Combine CSS',
										'performance-optimisation'
									) }
									description={ __(
										'Merge all CSS files into a single file to reduce the number of HTTP requests.',
										'performance-optimisation'
									) }
									name="combineCSS"
									checked={ settings.combineCSS }
									onChange={ handleChange( setSettings ) }
								/>
								{ settings.combineCSS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeCombineCSS"
										>
											{ __(
												'Exclude CSS from Combining',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea"
											id="excludeCombineCSS"
											name="excludeCombineCSS"
											rows="3"
											placeholder={ __(
												'Handles or partial URLs',
												'performance-optimisation'
											) }
											value={ settings.excludeCombineCSS }
											onChange={ handleChange(
												setSettings
											) }
										/>
									</div>
								) }
								<Tooltip
									content={ __(
										'Removes CSS rules not used on the current page, similar to PurgeCSS. Reduces page weight significantly.',
										'performance-optimisation'
									) }
								>
									<SwitchField
										label={ __(
											'Remove Unused CSS',
											'performance-optimisation'
										) }
										description={ __(
											'Scan pages and remove CSS rules that are not used. Reduces file size by 30–80% and helps pass PageSpeed audits.',
											'performance-optimisation'
										) }
										name="removeUnusedCSS"
										checked={ settings.removeUnusedCSS }
										onChange={ handleChange( setSettings ) }
									/>
								</Tooltip>
								{ settings.removeUnusedCSS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeUnusedCSS"
										>
											{ __(
												'Safelist Selectors',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea"
											id="excludeUnusedCSS"
											name="excludeUnusedCSS"
											rows="4"
											placeholder={ __(
												'Selectors to always keep (one per line, e.g. .my-dynamic-class)',
												'performance-optimisation'
											) }
											value={ settings.excludeUnusedCSS }
											onChange={ handleChange(
												setSettings
											) }
										/>
										<button
											className="wppo-button wppo-button--secondary wppo-mt-12"
											onClick={ handleRegenerateUsedCSS }
											type="button"
											disabled={ isLoading }
										>
											{ __(
												'Regenerate Used CSS',
												'performance-optimisation'
											) }
										</button>
									</div>
								) }
								{ settings.minifyCSS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeCSS"
										>
											{ __(
												'Exclude CSS from Minification',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea"
											id="excludeCSS"
											name="excludeCSS"
											rows="3"
											placeholder={ __(
												'Handles or partial URLs (one per line)',
												'performance-optimisation'
											) }
											value={ settings.excludeCSS }
											onChange={ handleChange(
												setSettings
											) }
										/>
									</div>
								) }
								<SwitchField
									label={ __(
										'Critical CSS',
										'performance-optimisation'
									) }
									description={ __(
										'Generate and inline above-the-fold CSS, then defer full stylesheets. Improves FCP and LCP by eliminating render-blocking CSS.',
										'performance-optimisation'
									) }
									name="criticalCSS"
									checked={ settings.criticalCSS }
									onChange={ handleChange( setSettings ) }
								/>
								{ settings.criticalCSS && (
									<CriticalCssPanel
										status={ ccssStatus }
										onRegenerate={ handleRegenerateCss }
									/>
								) }
								<SwitchField
									label={ __(
										'Host Google Fonts Locally',
										'performance-optimisation'
									) }
									description={ __(
										'Automatically detect Google Fonts and serve them from your own server. Eliminates external DNS lookups, improves GDPR compliance, and applies font-display: swap.',
										'performance-optimisation'
									) }
									name="hostGoogleFontsLocally"
									checked={ settings.hostGoogleFontsLocally }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						</FeatureCard>

						<FeatureCard
							title={ __(
								'HTML Optimization',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faCode } /> }
						>
							<SwitchField
								label={ __(
									'Minify HTML',
									'performance-optimisation'
								) }
								description={ __(
									'Compress the HTML output of your website by removing unnecessary whitespace and comments.',
									'performance-optimisation'
								) }
								name="minifyHTML"
								checked={ settings.minifyHTML }
								onChange={ handleChange( setSettings ) }
							/>
							<SwitchField
								label={ __(
									'Remove HTML Comments',
									'performance-optimisation'
								) }
								description={ __(
									'Strip HTML comments from the output (except IE conditional comments).',
									'performance-optimisation'
								) }
								name="removeHTMLComments"
								checked={ settings.removeHTMLComments }
								onChange={ handleChange( setSettings ) }
							/>
							<SwitchField
								label={ __(
									'Minify Inline CSS',
									'performance-optimisation'
								) }
								description={ __(
									'Minify CSS within <style> tags using the PHP minifier.',
									'performance-optimisation'
								) }
								name="minifyInlineCSS"
								checked={ settings.minifyInlineCSS }
								onChange={ handleChange( setSettings ) }
							/>
							<SwitchField
								label={ __(
									'Minify Inline JavaScript',
									'performance-optimisation'
								) }
								description={ __(
									'Minify JavaScript within <script> tags using the PHP minifier.',
									'performance-optimisation'
								) }
								name="minifyInlineJS"
								checked={ settings.minifyInlineJS }
								onChange={ handleChange( setSettings ) }
							/>
						</FeatureCard>
					</div>
				) }

				{ activeSubTab === 'scripts' && (
					<div
						id="panel-scripts"
						className="wppo-stacked-cards"
						role="tabpanel"
						aria-labelledby="tab-scripts"
					>
						<FeatureCard
							title={ __(
								'JavaScript Loading',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faRocket } /> }
						>
							<div className="wppo-field-group">
								<SwitchField
									label={ __(
										'Minify JavaScript',
										'performance-optimisation'
									) }
									description={ __(
										'Compress JS files by removing whitespace and comments to reduce execution time.',
										'performance-optimisation'
									) }
									name="minifyJS"
									checked={ settings.minifyJS }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Defer JavaScript',
										'performance-optimisation'
									) }
									description={ __(
										'Load scripts after the page renders to prevent render-blocking and improve page speed.',
										'performance-optimisation'
									) }
									name="deferJS"
									checked={ settings.deferJS }
									onChange={ handleChange( setSettings ) }
								/>
								{ settings.deferJS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeDeferJS"
										>
											{ __(
												'Exclude JS from Deferring',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea"
											id="excludeDeferJS"
											name="excludeDeferJS"
											rows="3"
											placeholder={ __(
												'Handles or partial URLs',
												'performance-optimisation'
											) }
											value={ settings.excludeDeferJS }
											onChange={ handleChange(
												setSettings
											) }
										/>
									</div>
								) }
								<SwitchField
									label={ __(
										'Delay JavaScript Execution',
										'performance-optimisation'
									) }
									description={ __(
										'Delay all scripts until the user interacts (keyboard/mouse) or load during idle/viewport. Reduces initial CPU usage but may break immediate functionality — test carefully.',
										'performance-optimisation'
									) }
									name="delayJS"
									checked={ settings.delayJS }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						</FeatureCard>

						{ ( settings.minifyJS || settings.delayJS ) && (
							<FeatureCard
								title={ __(
									'Script Rules',
									'performance-optimisation'
								) }
								icon={ <FontAwesomeIcon icon={ faRocket } /> }
							>
								{ settings.minifyJS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeJS"
										>
											{ __(
												'Exclude JS from Minification',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea"
											id="excludeJS"
											name="excludeJS"
											rows="3"
											placeholder={ __(
												'Handles or partial URLs',
												'performance-optimisation'
											) }
											value={ settings.excludeJS }
											onChange={ handleChange(
												setSettings
											) }
										/>
									</div>
								) }
								{ settings.delayJS && (
									<>
										<div className="wppo-field wppo-mt-20">
											<label
												className="wppo-field-label"
												htmlFor="excludeDelayJS"
											>
												{ __(
													'Scripts to Delay',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea"
												id="excludeDelayJS"
												name="excludeDelayJS"
												rows="3"
												placeholder={ __(
													'Partial URLs or keywords',
													'performance-optimisation'
												) }
												value={
													settings.excludeDelayJS
												}
												onChange={ handleChange(
													setSettings
												) }
											/>
										</div>

										<div className="wppo-field wppo-mt-16">
											<label
												className="wppo-field-label"
												htmlFor="delayJSDefaultStrategy"
											>
												{ __(
													'Default Load Strategy',
													'performance-optimisation'
												) }
											</label>
											<select
												className="wppo-select"
												id="delayJSDefaultStrategy"
												name="delayJSDefaultStrategy"
												value={
													settings.delayJSDefaultStrategy
												}
												onChange={ handleChange(
													setSettings
												) }
											>
												<option value="interaction">
													{ __(
														'Interaction (load on user interaction)',
														'performance-optimisation'
													) }
												</option>
												<option value="idle">
													{ __(
														'Idle (load during browser idle)',
														'performance-optimisation'
													) }
												</option>
												<option value="viewport">
													{ __(
														'Viewport (load when near viewport)',
														'performance-optimisation'
													) }
												</option>
											</select>
											<p className="wppo-text-muted wppo-mt-8 wppo-text-small">
												{ __(
													'Default strategy for delayed scripts that are not in a specific list below.',
													'performance-optimisation'
												) }
											</p>
										</div>

										{ ( settings.delayJSDefaultStrategy ===
											'idle' ||
											settings.delayJSIdleList ) && (
											<div className="wppo-field wppo-mt-16">
												<label
													className="wppo-field-label"
													htmlFor="delayJSIdleTimeout"
												>
													{ __(
														'Idle Timeout (ms)',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													type="number"
													id="delayJSIdleTimeout"
													name="delayJSIdleTimeout"
													min="500"
													max="30000"
													step="100"
													value={
														settings.delayJSIdleTimeout
													}
													onChange={ handleChange(
														setSettings
													) }
												/>
												<p className="wppo-text-muted wppo-mt-8 wppo-text-small">
													{ __(
														'Maximum time (ms) to wait before loading idle scripts (default: 3000).',
														'performance-optimisation'
													) }
												</p>
											</div>
										) }

										<div className="wppo-field wppo-mt-16">
											<label
												className="wppo-field-label"
												htmlFor="delayJSIdleList"
											>
												{ __(
													'Scripts to Load When Idle',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea"
												id="delayJSIdleList"
												name="delayJSIdleList"
												rows="3"
												placeholder={ __(
													'Handles or partial URLs (one per line)',
													'performance-optimisation'
												) }
												value={
													settings.delayJSIdleList
												}
												onChange={ handleChange(
													setSettings
												) }
											/>
											<p className="wppo-text-muted wppo-mt-8 wppo-text-small">
												{ __(
													'These scripts load via requestIdleCallback during browser idle time.',
													'performance-optimisation'
												) }
											</p>
										</div>

										<div className="wppo-field wppo-mt-16">
											<label
												className="wppo-field-label"
												htmlFor="delayJSViewportList"
											>
												{ __(
													'Scripts to Load in Viewport',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea"
												id="delayJSViewportList"
												name="delayJSViewportList"
												rows="3"
												placeholder={ __(
													'Handles or partial URLs (one per line)',
													'performance-optimisation'
												) }
												value={
													settings.delayJSViewportList
												}
												onChange={ handleChange(
													setSettings
												) }
											/>
											<p className="wppo-text-muted wppo-mt-8 wppo-text-small">
												{ __(
													'These scripts load when their position enters the viewport.',
													'performance-optimisation'
												) }
											</p>
										</div>

										<div className="wppo-field wppo-mt-16">
											<label
												className="wppo-field-label"
												htmlFor="delayJSPriority"
											>
												{ __(
													'Script Priority',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea"
												id="delayJSPriority"
												name="delayJSPriority"
												rows="3"
												placeholder={ __(
													'handle:high, other:low (one per line)',
													'performance-optimisation'
												) }
												value={
													settings.delayJSPriority
												}
												onChange={ handleChange(
													setSettings
												) }
											/>
											<p className="wppo-text-muted wppo-mt-8 wppo-text-small">
												{ __(
													'Syntax: handle:priority (high, normal, low). High-priority scripts load first.',
													'performance-optimisation'
												) }
											</p>
										</div>

										<div className="wppo-notice wppo-notice--warning wppo-mt-16">
											<FontAwesomeIcon
												icon={ faExclamationTriangle }
											/>
											<span>
												{ __(
													'Delaying scripts can break immediate functionality. Test carefully.',
													'performance-optimisation'
												) }
											</span>
										</div>
									</>
								) }
							</FeatureCard>
						) }
					</div>
				) }

				{ activeSubTab === 'ecommerce' && (
					<div
						id="panel-ecommerce"
						className="wppo-stacked-cards"
						role="tabpanel"
						aria-labelledby="tab-ecommerce"
					>
						<FeatureCard
							title={ __(
								'WooCommerce Core',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faStore } /> }
						>
							<div className="wppo-field-group">
								<SwitchField
									label={ __(
										'Optimize WooCommerce Assets',
										'performance-optimisation'
									) }
									description={ __(
										'Disable WooCommerce scripts and styles on non-ecommerce pages (e.g. blog, about). This reduces page weight but may break cart widgets on custom pages — verify your checkout flow after enabling.',
										'performance-optimisation'
									) }
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
												{ __(
													'This may break carts on custom pages. Verify your checkout flow.',
													'performance-optimisation'
												) }
											</span>
										</div>
										<div className="wppo-stacked-cards wppo-mt-24">
											<div className="wppo-field">
												<label
													className="wppo-field-label"
													htmlFor="excludeUrlToKeepJSCSS"
												>
													{ __(
														'Keep Assets on these URLs',
														'performance-optimisation'
													) }
												</label>
												<textarea
													className="wppo-textarea"
													id="excludeUrlToKeepJSCSS"
													name="excludeUrlToKeepJSCSS"
													rows="4"
													placeholder={ __(
														'e.g. shop/.* (regex supported)',
														'performance-optimisation'
													) }
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
													{ __(
														'Remove specific CSS/JS handles',
														'performance-optimisation'
													) }
												</label>
												<textarea
													className="wppo-textarea"
													id="removeCssJsHandle"
													name="removeCssJsHandle"
													rows="4"
													placeholder={ __(
														'Handles (one per line)',
														'performance-optimisation'
													) }
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
						className="wppo-stacked-cards"
						role="tabpanel"
						aria-labelledby="tab-network"
					>
						<FeatureCard
							title={ __(
								'Server Rules',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faServer } /> }
						>
							<div className="wppo-field-group">
								{ serverRulesError ? (
									<div className="wppo-notice wppo-notice--error">
										<span>
											{ __(
												'Unable to load server configuration. Check your server setup.',
												'performance-optimisation'
											) }
										</span>
										{ onRetryServerRules && (
											<button
												className="wppo-button wppo-button--secondary"
												onClick={ onRetryServerRules }
											>
												{ __(
													'Retry',
													'performance-optimisation'
												) }
											</button>
										) }
									</div>
								) : null }
								{ serverRules === null && ! serverRulesError ? (
									<div className="wppo-loading-placeholder">
										<FontAwesomeIcon
											icon={ faSpinner }
											spin
										/>
										<span>
											{ __(
												'Loading server configuration…',
												'performance-optimisation'
											) }
										</span>
									</div>
								) : null }
								{ serverRules !== null && ! serverRulesError ? (
									<>
										<Tooltip
											content={
												serverRules?.server_type !==
												'apache'
													? __(
															'Server rules require Apache.',
															'performance-optimisation'
													  )
													: ''
											}
										>
											<SwitchField
												label={ __(
													'Enable Server Rules (.htaccess)',
													'performance-optimisation'
												) }
												description={ __(
													'Write performance rules (browser caching, GZIP compression, etc.) directly to your .htaccess file for server-level optimization. Requires Apache. Ensure you have FTP access for recovery if something goes wrong.',
													'performance-optimisation'
												) }
												name="enableServerRules"
												checked={
													serverRules?.server_type ===
														'apache' &&
													settings.enableServerRules
												}
												disabled={
													serverRules?.server_type !==
													'apache'
												}
												onChange={ handleChange(
													setSettings
												) }
											/>
										</Tooltip>

										{ serverRules?.server_type ===
											'apache' &&
											settings.enableServerRules && (
												<div className="wppo-notice wppo-notice--warning">
													<FontAwesomeIcon
														icon={
															faExclamationTriangle
														}
													/>
													<span>
														{ __(
															'This modifies your .htaccess. Ensure you have FTP access for recovery.',
															'performance-optimisation'
														) }
													</span>
												</div>
											) }

										{ serverRules?.server_type ===
											'nginx' && (
											<div className="wppo-nginx-rules wppo-mt-20">
												<div className="wppo-notice wppo-notice--info wppo-mb-16">
													<FontAwesomeIcon
														icon={ faServer }
													/>
													<span>
														<strong>
															{ __(
																'Nginx Detected:',
																'performance-optimisation'
															) }
														</strong>{ ' ' }
														{ __(
															'Server rules cannot be applied automatically on Nginx. Please copy the rules below into your server configuration.',
															'performance-optimisation'
														) }
													</span>
												</div>
												<div className="wppo-field-label">
													{ __(
														'Nginx Configuration',
														'performance-optimisation'
													) }
												</div>
												<pre className="wppo-code-block">
													<code>
														{ serverRules.nginx }
													</code>
												</pre>
												<p className="wppo-text-muted wppo-mt-12 wppo-text-13">
													{ __(
														'Add these rules inside your',
														'performance-optimisation'
													) }{ ' ' }
													<code>
														server { '{' } ...{ ' ' }
														{ '}' }
													</code>{ ' ' }
													{ __(
														'block, then restart Nginx.',
														'performance-optimisation'
													) }
												</p>
											</div>
										) }

										{ serverRules?.server_type ===
											'other' && (
											<div className="wppo-notice wppo-notice--warning wppo-mt-20">
												<FontAwesomeIcon
													icon={
														faExclamationTriangle
													}
												/>
												<span>
													{ __(
														'Unrecognised server software. Automatic rules are only available for Apache (.htaccess).',
														'performance-optimisation'
													) }
												</span>
											</div>
										) }
									</>
								) : null }
							</div>
						</FeatureCard>

						<FeatureCard
							title={ __(
								'CDN Settings',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faServer } /> }
						>
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="cdnURL"
								>
									{ __(
										'CDN Hostname',
										'performance-optimisation'
									) }
								</label>
								<input
									className="wppo-input"
									type="url"
									id="cdnURL"
									name="cdnURL"
									placeholder="https://cdn.example.com"
									value={ settings.cdnURL }
									onChange={ handleChange( setSettings ) }
									aria-describedby="cdnURL-desc"
								/>
								<p
									id="cdnURL-desc"
									className="wppo-text-muted wppo-mt-10 wppo-text-small"
								>
									{ __(
										'Enter your CDN hostname. All static asset URLs (JS, CSS, images) will be rewritten to load from this domain, reducing latency for global visitors.',
										'performance-optimisation'
									) }
								</p>
							</div>
						</FeatureCard>
					</div>
				) }

				{ activeSubTab === 'core' && (
					<div
						id="panel-core"
						className="wppo-stacked-cards"
						role="tabpanel"
						aria-labelledby="tab-core"
					>
						<FeatureCard
							title={ __(
								'Cleanup Core Bloat',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faShieldAlt } /> }
						>
							<div className="wppo-field-group">
								<SwitchField
									label={ __(
										'Disable Emojis',
										'performance-optimisation'
									) }
									description={ __(
										"Remove the WordPress emoji script and stylesheet. Saves ~10 KB per page if you don't use emojis in your content.",
										'performance-optimisation'
									) }
									name="disableEmojis"
									checked={ settings.disableEmojis }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable Embeds',
										'performance-optimisation'
									) }
									description={ __(
										'Remove the oEmbed script that allows embedding external content. Saves ~1 HTTP request if you do not embed tweets, YouTube videos, etc.',
										'performance-optimisation'
									) }
									name="disableEmbeds"
									checked={ settings.disableEmbeds }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable Dashicons (Frontend)',
										'performance-optimisation'
									) }
									description={ __(
										'Prevent the WordPress admin icon font from loading on the frontend for logged-out users. Only disable if your theme does not use Dashicons.',
										'performance-optimisation'
									) }
									name="disableDashicons"
									checked={ settings.disableDashicons }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable XML-RPC',
										'performance-optimisation'
									) }
									description={ __(
										'Block the XML-RPC endpoint (xmlrpc.php). Reduces attack surface and server load. Only disable if you do not use Jetpack, mobile apps, or remote publishing.',
										'performance-optimisation'
									) }
									name="disableXMLRPC"
									checked={ settings.disableXMLRPC }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Load Block Assets On Demand',
										'performance-optimisation'
									) }
									description={ __(
										'Only load block CSS and JavaScript when blocks are actually used on the page. On WordPress 6.8 you must enable this toggle; on WordPress 6.9 and later classic themes load block assets on demand by default.',
										'performance-optimisation'
									) }
									name="blockAssetsOnDemand"
									checked={ settings.blockAssetsOnDemand }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Load Combined Core Block Styles',
										'performance-optimisation'
									) }
									description={ __(
										'Load the combined core block stylesheet (wp-block-library) for compatibility with shortcodes or widgets that enqueue styles while content renders. Overrides on-demand block assets. Requires WordPress 6.9+.',
										'performance-optimisation'
									) }
									name="loadAllCoreBlockAssets"
									checked={ settings.loadAllCoreBlockAssets }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						</FeatureCard>

						<FeatureCard
							title={ __(
								'Heartbeat Control',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faRocket } /> }
						>
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="heartbeatControl"
								>
									{ __(
										'API Frequency',
										'performance-optimisation'
									) }
								</label>
								<select
									className="wppo-select"
									id="heartbeatControl"
									name="heartbeatControl"
									value={ settings.heartbeatControl }
									onChange={ handleChange( setSettings ) }
									aria-describedby="heartbeatControl-desc"
								>
									<option value="default">
										{ __(
											'Default Mode',
											'performance-optimisation'
										) }
									</option>
									<option value="60s">
										{ __(
											'Reduce Frequency (60s)',
											'performance-optimisation'
										) }
									</option>
									<option value="disable_ext">
										{ __(
											'Disable on Frontend',
											'performance-optimisation'
										) }
									</option>
									<option value="disable_all">
										{ __(
											'Disable Everywhere',
											'performance-optimisation'
										) }
									</option>
								</select>
								<p
									id="heartbeatControl-desc"
									className="wppo-text-muted wppo-mt-12 wppo-text-13"
								>
									{ __(
										'Restricting the Heartbeat API reduces server CPU usage by limiting polling.',
										'performance-optimisation'
									) }
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
