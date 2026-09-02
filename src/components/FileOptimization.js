import { __ } from '@wordpress/i18n';
import { useState, useRef, useEffect } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import { modeLabel } from '../lib/litespeed';
import useNotice from '../lib/useNotice';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faCode,
	faRocket,
	faStore,
	faServer,
	faShieldAlt,
	faExclamationTriangle,
	faSpinner,
} from '@fortawesome/free-solid-svg-icons';
import Tooltip from './common/Tooltip';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import SwitchField from './common/SwitchField';
import NoticeBanner from './common/NoticeBanner';

import CriticalCssPanel from './CriticalCssPanel';

const FileOptimization = ( {
	options = {},
	serverRules = null,
	serverRulesError = false,
	ccssStatus = {},
	ccssError = false,
	onRetryServerRules,
	onCcssRefresh,
	onCcssRetry,
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
		removeQueryStrings: false,
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
		fontMetricFallback: false,
		cdnURL: '',
		cdnMapping: options.cdnMapping || [],
		removeUnusedCSS: false,
		excludeUnusedCSS: '',
		disableEmojis: false,
		disableEmbeds: false,
		disableDashicons: false,
		disableXMLRPC: false,
		disableRestApiLinks: false,
		disableRssFeeds: false,
		disableShortlinks: false,
		disableGeneratorTag: false,
		disableJQueryMigrate: false,
		disablePasswordStrength: false,
		disableSelfPingbacks: false,
		disableRSD: false,
		disableWLWManifest: false,
		disableGlobalStyles: false,
		disableClassicThemeStyles: false,
		disableWooCartFragments: false,
		disableRecentCommentsStyle: false,
		disableCommentReply: false,
		disableOEmbedDiscovery: false,
		disableBlockWidgets: false,
		// Mirrors the pre-6.9 PHP default. PHP always emits the key on WP 6.9+ (where
		// core loads block assets on demand by default), so this fallback is only used
		// on older cores and never contradicts the backend default.
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
	const { notice, notify, dismiss } = useNotice();

	// H-01: sync local state when parent props change after mount.
	useEffect( () => {
		setSettings( ( prev ) => ( { ...prev, ...options } ) );
	}, [ options ] );

	// LiteSpeed integration (Phase 1 — safe coexistence).
	const litespeedInfo =
		typeof wppoSettings !== 'undefined' ? wppoSettings?.litespeed : null;
	const isLiteSpeed = !! litespeedInfo?.detected;
	const optimizerDisabled = !! litespeedInfo?.optimizer_disabled;
	const effectiveMode = litespeedInfo?.effective_mode || 'standalone';
	const lscacheActive = !! litespeedInfo?.lscache_active;
	const liteSpeedModeFromSettings =
		typeof wppoSettings !== 'undefined'
			? wppoSettings?.settings?.litespeed_integration?.mode || 'auto'
			: 'auto';
	const [ litespeedMode, setLitespeedMode ] = useState(
		liteSpeedModeFromSettings
	);
	useEffect( () => {
		setLitespeedMode( liteSpeedModeFromSettings );
	}, [ liteSpeedModeFromSettings ] );
	const [ savingLiteSpeed, setSavingLiteSpeed ] = useState( false );
	const pausedTooltip = __(
		'Paused — LiteSpeed Cache owns optimisation (change in Network → LiteSpeed)',
		'performance-optimisation'
	);
	const effectiveLabel = modeLabel( effectiveMode );
	const effectiveBadgeClass =
		effectiveMode === 'litespeed'
			? 'wppo-status-badge--warning'
			: 'wppo-status-badge--good';

	const handleSaveLiteSpeedMode = async () => {
		setSavingLiteSpeed( true );
		try {
			const res = await apiCall( 'update_settings', {
				tab: 'litespeed_integration',
				settings: { mode: litespeedMode },
			} );
			if ( res.success ) {
				notify( {
					type: 'success',
					message:
						res.message ||
						__(
							'LiteSpeed settings saved.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
				// Mutate global so Dashboard banner + next mount reflect new mode without reload.
				if ( typeof wppoSettings !== 'undefined' && res.data ) {
					wppoSettings.settings = Object.freeze( res.data );
				}
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Failed to save LiteSpeed settings.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
			}
		} catch ( err ) {
			console.error( 'LiteSpeed save failed', err );
			notify( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
		} finally {
			setSavingLiteSpeed( false );
		}
	};

	const withNotification = async (
		apiCallPromise,
		successMessage,
		errorMessage
	) => {
		setIsLoading( true );
		dismiss();

		try {
			const res = await apiCallPromise;
			if ( res.success ) {
				notify( {
					type: 'success',
					message: res.message || successMessage,
					durationMs: 3000,
				} );
			} else {
				notify( {
					type: 'error',
					message: res.message || errorMessage,
					durationMs: 3000,
				} );
			}
		} catch ( err ) {
			console.error( errorMessage, err );
			notify( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
		} finally {
			setIsLoading( false );
		}
	};

	const handleRegenerateCss = async () => {
		await withNotification(
			( async () => {
				const res = await apiCall( 'regenerate_ccss' );
				if ( res?.success && onCcssRefresh ) {
					onCcssRefresh();
				}
				return res;
			} )(),
			__(
				'Critical CSS regeneration queued.',
				'performance-optimisation'
			),
			__(
				'Failed to regenerate critical CSS.',
				'performance-optimisation'
			)
		);
	};

	const handleRegenerateUsedCSS = async () => {
		await withNotification(
			( async () => {
				const saveRes = await apiCall( 'update_settings', {
					tab: 'file_optimisation',
					settings: { ...settings },
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
				settings: { ...settings },
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
		} else if ( e.key === 'Home' ) {
			nextIndex = 0;
		} else if ( e.key === 'End' ) {
			nextIndex = subTabs.length - 1;
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

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'File Optimisation', 'performance-optimisation' ) }
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
				{ notice && (
					<NoticeBanner
						type={ notice.type }
						message={ notice.message }
						className="wppo-mb-20"
					/>
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
								'CSS Optimisation',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faCode } /> }
						>
							{ optimizerDisabled && (
								<div className="wppo-notice wppo-notice--warning wppo-mb-12">
									<FontAwesomeIcon
										icon={ faExclamationTriangle }
									/>{ ' ' }
									{ __(
										'Optimisation paused — LiteSpeed Cache owns CSS/JS optimisation (change in Network → LiteSpeed).',
										'performance-optimisation'
									) }
								</div>
							) }
							<div className="wppo-field-group">
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
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
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
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
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								{ settings.combineCSS && (
									<div className="wppo-notice wppo-notice--warning wppo-mt-12">
										<FontAwesomeIcon
											icon={ faExclamationTriangle }
										/>{ ' ' }
										{ __(
											'May cause FOUC — test in incognito and exclude problematic files above.',
											'performance-optimisation'
										) }
									</div>
								) }
								{ settings.combineCSS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeCombineCSS"
										>
											{ __(
												'Exclude from Combining',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea wppo-textarea--mono"
											id="excludeCombineCSS"
											name="excludeCombineCSS"
											rows="3"
											placeholder={ __(
												'e.g. handle-name or /wp-content/…/style.css',
												'performance-optimisation'
											) }
											value={ settings.excludeCombineCSS }
											onChange={ handleChange(
												setSettings
											) }
											aria-describedby="excludeCombineCSS-desc"
										/>
										<p
											id="excludeCombineCSS-desc"
											className="wppo-text-muted wppo-text-small wppo-mt-8"
										>
											{ __(
												'One handle or partial URL per line. Fix FOUC by excluding problematic files.',
												'performance-optimisation'
											) }
										</p>
									</div>
								) }
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Remove Query Strings From Static Resources',
											'performance-optimisation'
										) }
										description={ __(
											'Strip ?ver= query strings from CSS and JavaScript URLs so proxies and CDNs can cache them more effectively.',
											'performance-optimisation'
										) }
										name="removeQueryStrings"
										checked={ settings.removeQueryStrings }
										onChange={ handleChange( setSettings ) }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								<Tooltip
									content={
										optimizerDisabled
											? pausedTooltip
											: __(
													'Removes CSS rules not used on the current page, similar to PurgeCSS. Reduces page weight significantly.',
													'performance-optimisation'
											  )
									}
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
										disabled={ optimizerDisabled }
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
											className="wppo-textarea wppo-textarea--mono"
											id="excludeUnusedCSS"
											name="excludeUnusedCSS"
											rows="4"
											placeholder={ __(
												'e.g. .my-dynamic-class',
												'performance-optimisation'
											) }
											value={ settings.excludeUnusedCSS }
											onChange={ handleChange(
												setSettings
											) }
											aria-describedby="excludeUnusedCSS-desc"
										/>
										<p
											id="excludeUnusedCSS-desc"
											className="wppo-text-muted wppo-text-small wppo-mt-8"
										>
											{ __(
												'One selector per line — kept even if unused. Use to fix missing styles.',
												'performance-optimisation'
											) }
										</p>
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
											className="wppo-textarea wppo-textarea--mono"
											id="excludeCSS"
											name="excludeCSS"
											rows="3"
											placeholder={ __(
												'e.g. handle-name or /wp-content/…/critical.css',
												'performance-optimisation'
											) }
											value={ settings.excludeCSS }
											onChange={ handleChange(
												setSettings
											) }
											aria-describedby="excludeCSS-desc"
										/>
										<p
											id="excludeCSS-desc"
											className="wppo-text-muted wppo-text-small wppo-mt-8"
										>
											{ __(
												'One handle or partial URL per line.',
												'performance-optimisation'
											) }
										</p>
									</div>
								) }
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
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
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								{ settings.criticalCSS && (
									<>
										{ ccssError && (
											<div className="wppo-notice wppo-notice--error">
												<span>
													{ __(
														'Unable to load Critical CSS status.',
														'performance-optimisation'
													) }
												</span>
												{ onCcssRetry && (
													<button
														className="wppo-button wppo-button--secondary"
														onClick={ onCcssRetry }
													>
														{ __(
															'Retry',
															'performance-optimisation'
														) }
													</button>
												) }
											</div>
										) }
										<CriticalCssPanel
											status={ ccssStatus }
											onRegenerate={ handleRegenerateCss }
										/>
									</>
								) }
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
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
										checked={
											settings.hostGoogleFontsLocally
										}
										onChange={ handleChange( setSettings ) }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Font Metric Fallback',
											'performance-optimisation'
										) }
										description={ __(
											'Inject metric-matched system fallback (size-adjust/ascent-override) to reduce CLS when Google Fonts load.',
											'performance-optimisation'
										) }
										name="fontMetricFallback"
										checked={ settings.fontMetricFallback }
										onChange={ handleChange( setSettings ) }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
							</div>
						</FeatureCard>

						<FeatureCard
							title={ __(
								'HTML Optimisation',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faCode } /> }
						>
							<div className="wppo-field-group">
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
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
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
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
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
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
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
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
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
							</div>
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
							{ optimizerDisabled && (
								<div className="wppo-notice wppo-notice--warning wppo-mb-12">
									<FontAwesomeIcon
										icon={ faExclamationTriangle }
									/>{ ' ' }
									{ __(
										'Optimisation paused — LiteSpeed Cache owns JS optimisation (change in Network → LiteSpeed).',
										'performance-optimisation'
									) }
								</div>
							) }
							<div className="wppo-field-group">
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
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
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
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
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								{ settings.deferJS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeDeferJS"
										>
											{ __(
												'Exclude from Deferring',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea wppo-textarea--mono"
											id="excludeDeferJS"
											name="excludeDeferJS"
											rows="3"
											placeholder={ __(
												'e.g. jquery or /wp-includes/…/script.js',
												'performance-optimisation'
											) }
											value={ settings.excludeDeferJS }
											onChange={ handleChange(
												setSettings
											) }
										/>
										<p className="wppo-text-muted wppo-text-small wppo-mt-8">
											{ __(
												'One handle or partial URL per line.',
												'performance-optimisation'
											) }
										</p>
									</div>
								) }
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
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
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
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
								<div className="wppo-field-group">
									{ settings.minifyJS && (
										<div className="wppo-field">
											<label
												className="wppo-field-label"
												htmlFor="excludeJS"
											>
												{ __(
													'Exclude from Minification',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea wppo-textarea--mono"
												id="excludeJS"
												name="excludeJS"
												rows="3"
												placeholder={ __(
													'e.g. handle-name or /wp-content/…/critical.js',
													'performance-optimisation'
												) }
												value={ settings.excludeJS }
												onChange={ handleChange(
													setSettings
												) }
											/>
											<p className="wppo-text-muted wppo-text-small wppo-mt-8">
												{ __(
													'One handle or partial URL per line.',
													'performance-optimisation'
												) }
											</p>
										</div>
									) }
									{ settings.delayJS && (
										<>
											<div className="wppo-field">
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
													className="wppo-textarea wppo-textarea--mono"
													id="excludeDelayJS"
													name="excludeDelayJS"
													rows="3"
													placeholder={ __(
														'e.g. googletagmanager.com/gtag',
														'performance-optimisation'
													) }
													value={
														settings.excludeDelayJS
													}
													onChange={ handleChange(
														setSettings
													) }
												/>
												<p className="wppo-text-muted wppo-text-small wppo-mt-8">
													{ __(
														'One per line — partial URL or keyword (e.g. gtag).',
														'performance-optimisation'
													) }
												</p>
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
														aria-describedby="delayJSIdleTimeout-desc"
													/>
													<p
														id="delayJSIdleTimeout-desc"
														className="wppo-text-muted wppo-mt-8 wppo-text-small"
													>
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
													className="wppo-textarea wppo-textarea--mono"
													id="delayJSIdleList"
													name="delayJSIdleList"
													rows="3"
													placeholder={ __(
														'e.g. analytics.js',
														'performance-optimisation'
													) }
													value={
														settings.delayJSIdleList
													}
													onChange={ handleChange(
														setSettings
													) }
												/>
												<p className="wppo-text-muted wppo-text-small wppo-mt-8">
													{ __(
														'One per line — loads via requestIdleCallback during browser idle.',
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
													className="wppo-textarea wppo-textarea--mono"
													id="delayJSViewportList"
													name="delayJSViewportList"
													rows="3"
													placeholder={ __(
														'e.g. chat-widget.js',
														'performance-optimisation'
													) }
													value={
														settings.delayJSViewportList
													}
													onChange={ handleChange(
														setSettings
													) }
												/>
												<p className="wppo-text-muted wppo-text-small wppo-mt-8">
													{ __(
														'One per line — loads when near viewport.',
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
													className="wppo-textarea wppo-textarea--mono"
													id="delayJSPriority"
													name="delayJSPriority"
													rows="3"
													placeholder={ __(
														'e.g. critical:high',
														'performance-optimisation'
													) }
													value={
														settings.delayJSPriority
													}
													onChange={ handleChange(
														setSettings
													) }
												/>
												<p className="wppo-text-muted wppo-text-small wppo-mt-8">
													{ __(
														'One per line — handle:priority (high, normal, low). High loads first.',
														'performance-optimisation'
													) }
												</p>
											</div>

											<div className="wppo-notice wppo-notice--warning wppo-mt-16">
												<FontAwesomeIcon
													icon={
														faExclamationTriangle
													}
												/>{ ' ' }
												<span>
													{ __(
														'Delaying scripts can break immediate functionality. Test carefully.',
														'performance-optimisation'
													) }
												</span>
											</div>
										</>
									) }
								</div>
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
										<div className="wppo-field">
											<label
												className="wppo-field-label"
												htmlFor="excludeUrlToKeepJSCSS"
											>
												{ __(
													'Keep Assets on These URLs',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea wppo-textarea--mono"
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
											<p className="wppo-text-muted wppo-text-small wppo-mt-8">
												{ __(
													'One pattern per line — regex supported.',
													'performance-optimisation'
												) }
											</p>
										</div>
										<div className="wppo-field">
											<label
												className="wppo-field-label"
												htmlFor="removeCssJsHandle"
											>
												{ __(
													'Remove Specific CSS/JS Handles',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea wppo-textarea--mono"
												id="removeCssJsHandle"
												name="removeCssJsHandle"
												rows="4"
												placeholder={ __(
													'e.g. woocommerce-smallscreen',
													'performance-optimisation'
												) }
												value={
													settings.removeCssJsHandle
												}
												onChange={ handleChange(
													setSettings
												) }
											/>
											<p className="wppo-text-muted wppo-text-small wppo-mt-8">
												{ __(
													'One handle per line.',
													'performance-optimisation'
												) }
											</p>
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
						{ isLiteSpeed && (
							<FeatureCard
								title={ __(
									'LiteSpeed Integration',
									'performance-optimisation'
								) }
								icon={ <FontAwesomeIcon icon={ faServer } /> }
							>
								<div className="wppo-field-group">
									<div className="wppo-mb-12">
										<span
											className={ `wppo-status-badge wppo-status-badge--${
												lscacheActive ? 'poor' : 'good'
											}` }
											style={ { marginRight: '8px' } }
										>
											{ lscacheActive
												? __(
														'LSCache Active',
														'performance-optimisation'
												  )
												: __(
														'LSCache Inactive',
														'performance-optimisation'
												  ) }
										</span>
										<span
											className="wppo-status-badge wppo-status-badge--good"
											style={ { marginRight: '8px' } }
										>
											{ __(
												'Detected: LiteSpeed',
												'performance-optimisation'
											) }
										</span>
										<span
											className={ `wppo-status-badge ${ effectiveBadgeClass }` }
										>
											{ __(
												'Effective:',
												'performance-optimisation'
											) }{ ' ' }
											{ effectiveLabel }
										</span>
									</div>
									{ lscacheActive &&
										effectiveMode === 'litespeed' && (
											<NoticeBanner
												type="warning"
												message={ __(
													'LiteSpeed Cache owns page cache & optimisation in the current mode — WPPO combiners/minifiers are paused to prevent double processing.',
													'performance-optimisation'
												) }
												className="wppo-mb-12"
											/>
										) }
									{ lscacheActive &&
										liteSpeedModeFromSettings ===
											'auto' && (
											<NoticeBanner
												type="info"
												message={ __(
													'Both plugins are active in Auto mode. Choose the cache owner below.',
													'performance-optimisation'
												) }
												className="wppo-mb-12"
											/>
										) }
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="litespeedMode"
										>
											{ __(
												'Cache Owner',
												'performance-optimisation'
											) }
										</label>
										<select
											className="wppo-select"
											id="litespeedMode"
											value={ litespeedMode }
											onChange={ ( e ) =>
												setLitespeedMode(
													e.target.value
												)
											}
										>
											<option value="auto">
												{ __(
													'Auto — Detect (recommended)',
													'performance-optimisation'
												) }
											</option>
											<option value="wppo">
												{ __(
													'WPPO owns cache',
													'performance-optimisation'
												) }
											</option>
											<option value="litespeed">
												{ __(
													'LiteSpeed owns cache',
													'performance-optimisation'
												) }
											</option>
											<option value="standalone">
												{ __(
													'Standalone — Ignore LiteSpeed',
													'performance-optimisation'
												) }
											</option>
										</select>
										<p className="wppo-text-muted wppo-text-small wppo-mt-8">
											{ __(
												'In Auto, WPPO owns cache when LiteSpeed Cache is not active; otherwise LiteSpeed owns it.',
												'performance-optimisation'
											) }
										</p>
									</div>
									<LoadingSubmitButton
										className="wppo-button wppo-button--secondary"
										isLoading={ savingLiteSpeed }
										onClick={ handleSaveLiteSpeedMode }
										label={ __(
											'Save LiteSpeed Mode',
											'performance-optimisation'
										) }
									/>
									{ serverRules?.litespeed?.dropin && (
										<div className="wppo-mt-16">
											<p className="wppo-text-muted wppo-text-small">
												<strong>
													{ __(
														'Drop-in status:',
														'performance-optimisation'
													) }
												</strong>{ ' ' }
												{ __(
													'Page cache:',
													'performance-optimisation'
												) }{ ' ' }
												{ litespeedInfo?.dropin
													?.advanced_cache ||
													serverRules.litespeed.dropin
														?.advanced_cache ||
													'none' }{ ' ' }
												—{ ' ' }
												{ __(
													'Object cache:',
													'performance-optimisation'
												) }{ ' ' }
												{ litespeedInfo?.dropin
													?.object_cache ||
													serverRules.litespeed.dropin
														?.object_cache ||
													'none' }
											</p>
											{ ( litespeedInfo?.dropin
												?.object_cache === 'foreign' ||
												litespeedInfo?.dropin
													?.advanced_cache ===
													'foreign' ||
												serverRules.litespeed.dropin
													?.advanced_cache ===
													'foreign' ||
												serverRules.litespeed.dropin
													?.object_cache ===
													'foreign' ) && (
												<div className="wppo-notice wppo-notice--warning wppo-mt-8">
													<FontAwesomeIcon
														icon={
															faExclamationTriangle
														}
													/>{ ' ' }
													{ __(
														'Only one object-cache.php / advanced-cache.php can exist. Choose the handler in Tools → Object Cache.',
														'performance-optimisation'
													) }
												</div>
											) }
											{ litespeedInfo?.dropin
												?.advanced_cache ===
												'litespeed' && (
												<div className="wppo-notice wppo-notice--info wppo-mt-8">
													{ __(
														'Page cache drop-in is owned by LiteSpeed — WPPO file cache is bypassed in this mode.',
														'performance-optimisation'
													) }
												</div>
											) }
										</div>
									) }
								</div>
							</FeatureCard>
						) }
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
													'apache' &&
												serverRules?.server_type !==
													'litespeed'
													? __(
															'Server rules require Apache or LiteSpeed.',
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
												description={
													serverRules?.server_type ===
													'litespeed'
														? __(
																'Write performance rules (browser caching, GZIP compression, etc.) directly to your .htaccess file for server-level optimisation. LiteSpeed is Apache-compatible — restart OpenLiteSpeed after changes. Ensure you have FTP access for recovery if something goes wrong.',
																'performance-optimisation'
														  )
														: __(
																'Write performance rules (browser caching, GZIP compression, etc.) directly to your .htaccess file for server-level optimisation. Requires Apache. Ensure you have FTP access for recovery if something goes wrong.',
																'performance-optimisation'
														  )
												}
												name="enableServerRules"
												checked={
													( serverRules?.server_type ===
														'apache' ||
														serverRules?.server_type ===
															'litespeed' ) &&
													settings.enableServerRules
												}
												disabled={
													serverRules?.server_type !==
														'apache' &&
													serverRules?.server_type !==
														'litespeed'
												}
												onChange={ handleChange(
													setSettings
												) }
											/>
										</Tooltip>

										{ ( serverRules?.server_type ===
											'apache' ||
											serverRules?.server_type ===
												'litespeed' ) &&
											settings.enableServerRules && (
												<div className="wppo-notice wppo-notice--warning">
													<FontAwesomeIcon
														icon={
															faExclamationTriangle
														}
													/>
													<span>
														{ serverRules?.server_type ===
														'litespeed'
															? __(
																	'This modifies your .htaccess. LiteSpeed is Apache-compatible — restart OpenLiteSpeed after changes. Ensure you have FTP access for recovery.',
																	'performance-optimisation'
															  )
															: __(
																	'This modifies your .htaccess. Ensure you have FTP access for recovery.',
																	'performance-optimisation'
															  ) }
													</span>
												</div>
											) }

										{ serverRules?.server_type ===
											'litespeed' && (
											<div className="wppo-notice wppo-notice--info wppo-mt-20">
												<FontAwesomeIcon
													icon={ faServer }
												/>
												<span>
													<strong>
														{ __(
															'LiteSpeed Detected:',
															'performance-optimisation'
														) }
													</strong>{ ' ' }
													{ __(
														'LiteSpeed is Apache-compatible. Server rules use the same .htaccess as Apache — restart OpenLiteSpeed after changes.',
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
								{ settings.cdnURL &&
									Array.isArray( settings.cdnMapping ) &&
									settings.cdnMapping.length > 0 && (
										<div className="wppo-notice wppo-notice--info wppo-mt-12">
											{ __(
												'Migrated to mapping #0 — cdnURL kept for backward compatibility.',
												'performance-optimisation'
											) }
										</div>
									) }
							</div>
							<div className="wppo-field wppo-mt-16">
								{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
								<label className="wppo-field-label">
									{ __(
										'CDN Mapping (parity with LSCWP)',
										'performance-optimisation'
									) }
								</label>
								<p className="wppo-text-muted wppo-text-small wppo-mb-12">
									{ __(
										'One-to-many mapping by origin/dir/filetype. Up to 5 entries. Use * wildcard in Origin Dir (e.g. wp-content/*).',
										'performance-optimisation'
									) }
								</p>
								{ ( settings.cdnMapping || [] ).map(
									( entry, idx ) => (
										<div
											key={ idx }
											className="wppo-mt-12"
											style={ {
												border: '1px solid var(--wppo-border)',
												padding: '12px',
												borderRadius: '6px',
											} }
										>
											<div className="wppo-field">
												{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
												<label className="wppo-field-label">
													{ __(
														'CDN URL',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													type="url"
													placeholder="https://cdn.example.com"
													value={
														entry.cdn_url || ''
													}
													onChange={ ( e ) => {
														const v =
															e.target.value;
														setSettings(
															( prev ) => {
																const m = [
																	...( prev.cdnMapping ||
																		[] ),
																];
																m[ idx ] = {
																	...m[ idx ],
																	cdn_url: v,
																};
																return {
																	...prev,
																	cdnMapping:
																		m,
																};
															}
														);
													} }
												/>
											</div>
											<div className="wppo-field wppo-mt-8">
												{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
												<label className="wppo-field-label">
													{ __(
														'Origin URL (ori)',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													type="url"
													placeholder="https://example.com"
													value={ entry.ori || '' }
													onChange={ ( e ) => {
														const v =
															e.target.value;
														setSettings(
															( prev ) => {
																const m = [
																	...( prev.cdnMapping ||
																		[] ),
																];
																m[ idx ] = {
																	...m[ idx ],
																	ori: v,
																};
																return {
																	...prev,
																	cdnMapping:
																		m,
																};
															}
														);
													} }
												/>
											</div>
											<div className="wppo-field wppo-mt-8">
												{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
												<label className="wppo-field-label">
													{ __(
														'Origin Dir (ori_dir) — wildcard * allowed, pipe-separated',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													type="text"
													placeholder="wp-content|wp-includes"
													value={
														entry.ori_dir || ''
													}
													onChange={ ( e ) => {
														const v =
															e.target.value;
														setSettings(
															( prev ) => {
																const m = [
																	...( prev.cdnMapping ||
																		[] ),
																];
																m[ idx ] = {
																	...m[ idx ],
																	ori_dir: v,
																};
																return {
																	...prev,
																	cdnMapping:
																		m,
																};
															}
														);
													} }
												/>
											</div>
											<div className="wppo-field wppo-mt-8">
												{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
												<label className="wppo-field-label">
													{ __(
														'Include Dirs',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													type="text"
													placeholder="wp-content|wp-includes"
													value={
														entry.include_dirs || ''
													}
													onChange={ ( e ) => {
														const v =
															e.target.value;
														setSettings(
															( prev ) => {
																const m = [
																	...( prev.cdnMapping ||
																		[] ),
																];
																m[ idx ] = {
																	...m[ idx ],
																	include_dirs:
																		v,
																};
																return {
																	...prev,
																	cdnMapping:
																		m,
																};
															}
														);
													} }
												/>
											</div>
											<div className="wppo-field wppo-mt-8">
												{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
												<label className="wppo-field-label">
													{ __(
														'Include Filetypes (comma-separated)',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													type="text"
													placeholder="jpg,png,css,js"
													value={
														entry.include_filetypes ||
														''
													}
													onChange={ ( e ) => {
														const v =
															e.target.value;
														setSettings(
															( prev ) => {
																const m = [
																	...( prev.cdnMapping ||
																		[] ),
																];
																m[ idx ] = {
																	...m[ idx ],
																	include_filetypes:
																		v,
																};
																return {
																	...prev,
																	cdnMapping:
																		m,
																};
															}
														);
													} }
												/>
											</div>
											<div className="wppo-field wppo-mt-8">
												{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
												<label className="wppo-field-label">
													{ __(
														'CDN Attr allowlist (cdn_attr) — e.g. src,href,srcset',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													type="text"
													placeholder="src,href,srcset"
													value={
														entry.cdn_attr || ''
													}
													onChange={ ( e ) => {
														const v =
															e.target.value;
														setSettings(
															( prev ) => {
																const m = [
																	...( prev.cdnMapping ||
																		[] ),
																];
																m[ idx ] = {
																	...m[ idx ],
																	cdn_attr: v,
																};
																return {
																	...prev,
																	cdnMapping:
																		m,
																};
															}
														);
													} }
												/>
											</div>
											<button
												className="wppo-button wppo-button--secondary wppo-mt-8"
												type="button"
												onClick={ () => {
													setSettings( ( prev ) => {
														const m = [
															...( prev.cdnMapping ||
																[] ),
														];
														m.splice( idx, 1 );
														return {
															...prev,
															cdnMapping: m,
														};
													} );
												} }
											>
												{ __(
													'Remove',
													'performance-optimisation'
												) }
											</button>
										</div>
									)
								) }
								{ ( settings.cdnMapping || [] ).length < 5 && (
									<button
										className="wppo-button wppo-button--secondary wppo-mt-12"
										type="button"
										onClick={ () => {
											setSettings( ( prev ) => {
												const m = [
													...( prev.cdnMapping ||
														[] ),
												];
												m.push( {
													cdn_url: '',
													ori: '',
													ori_dir: '',
													include_dirs:
														'wp-content|wp-includes',
													include_filetypes: '',
													cdn_attr: '',
												} );
												return {
													...prev,
													cdnMapping: m,
												};
											} );
										} }
									>
										{ __(
											'Add Mapping',
											'performance-optimisation'
										) }
									</button>
								) }
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
										'Remove REST API Links',
										'performance-optimisation'
									) }
									description={ __(
										'Remove the REST API discovery link and oEmbed links from the front end. The REST API itself stays active.',
										'performance-optimisation'
									) }
									name="disableRestApiLinks"
									checked={ settings.disableRestApiLinks }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable RSS Feeds',
										'performance-optimisation'
									) }
									description={ __(
										'Redirect all feed requests to the home page and remove feed discovery links. Only for sites that do not use feeds.',
										'performance-optimisation'
									) }
									name="disableRssFeeds"
									checked={ settings.disableRssFeeds }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Remove Shortlinks',
										'performance-optimisation'
									) }
									description={ __(
										'Remove the rel=shortlink tag from page output.',
										'performance-optimisation'
									) }
									name="disableShortlinks"
									checked={ settings.disableShortlinks }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Remove Generator Meta Tag',
										'performance-optimisation'
									) }
									description={ __(
										'Hide the WordPress version meta generator tag.',
										'performance-optimisation'
									) }
									name="disableGeneratorTag"
									checked={ settings.disableGeneratorTag }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Remove jQuery Migrate',
										'performance-optimisation'
									) }
									description={ __(
										'Drop the jquery-migrate dependency to save a request. Only disable if your theme/plugins do not rely on deprecated jQuery APIs.',
										'performance-optimisation'
									) }
									name="disableJQueryMigrate"
									checked={ settings.disableJQueryMigrate }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Remove Password Strength Meter',
										'performance-optimisation'
									) }
									description={ __(
										'Prevent the password strength meter script from loading on the front end.',
										'performance-optimisation'
									) }
									name="disablePasswordStrength"
									checked={ settings.disablePasswordStrength }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable Self-pingbacks',
										'performance-optimisation'
									) }
									description={ __(
										'Stop posts from pinging themselves when linking to their own site.',
										'performance-optimisation'
									) }
									name="disableSelfPingbacks"
									checked={ settings.disableSelfPingbacks }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable RSD Link',
										'performance-optimisation'
									) }
									description={ __(
										'Remove the EditURI (RSD) link used by remote clients.',
										'performance-optimisation'
									) }
									name="disableRSD"
									checked={ settings.disableRSD }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable WLW Manifest',
										'performance-optimisation'
									) }
									description={ __(
										'Remove the Windows Live Writer manifest link.',
										'performance-optimisation'
									) }
									name="disableWLWManifest"
									checked={ settings.disableWLWManifest }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable Global Styles',
										'performance-optimisation'
									) }
									description={ __(
										'Remove WordPress global styles and SVG filters.',
										'performance-optimisation'
									) }
									name="disableGlobalStyles"
									checked={ settings.disableGlobalStyles }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable Classic Theme Styles',
										'performance-optimisation'
									) }
									description={ __(
										'Remove classic theme stylesheet.',
										'performance-optimisation'
									) }
									name="disableClassicThemeStyles"
									checked={
										settings.disableClassicThemeStyles
									}
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable Woo Cart Fragments',
										'performance-optimisation'
									) }
									description={ __(
										'Prevent WooCommerce cart fragments AJAX polling.',
										'performance-optimisation'
									) }
									name="disableWooCartFragments"
									checked={ settings.disableWooCartFragments }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable Recent Comments Style',
										'performance-optimisation'
									) }
									description={ __(
										'Remove recent comments widget inline CSS.',
										'performance-optimisation'
									) }
									name="disableRecentCommentsStyle"
									checked={
										settings.disableRecentCommentsStyle
									}
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable Comment Reply JS',
										'performance-optimisation'
									) }
									description={ __(
										'Remove comment-reply script.',
										'performance-optimisation'
									) }
									name="disableCommentReply"
									checked={ settings.disableCommentReply }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable oEmbed Discovery',
										'performance-optimisation'
									) }
									description={ __(
										'Remove oEmbed discovery links from head.',
										'performance-optimisation'
									) }
									name="disableOEmbedDiscovery"
									checked={ settings.disableOEmbedDiscovery }
									onChange={ handleChange( setSettings ) }
								/>
								<SwitchField
									label={ __(
										'Disable Block Widgets',
										'performance-optimisation'
									) }
									description={ __(
										'Disable block-based widgets editor.',
										'performance-optimisation'
									) }
									name="disableBlockWidgets"
									checked={ settings.disableBlockWidgets }
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
