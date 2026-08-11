import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import SwitchField from './common/SwitchField';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faHourglassStart,
	faLink,
	faFont,
	faRocket,
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import NoticeBanner from './common/NoticeBanner';

const PreloadSettings = ( { options = {} } ) => {
	const defaultSettings = {
		enablePreloadCache: false,
		excludePreloadCache: 'my-account/(.*)\ncart/(.*)\ncheckout/(.*)',
		preloadSitemap: false,
		preconnect: false,
		preconnectOrigins: '',
		prefetchDNS: false,
		dnsPrefetchOrigins: '',
		preloadFonts: false,
		preloadFontsUrls: '',
		preloadCSS: false,
		preloadCSSUrls: '',
		enableSpeculationRules: false,
		speculationMode: 'prerender',
		speculationEagerness: 'moderate',
		speculationExcludeUrls: '',
		...options,
	};

	const [ settings, setSettings ] = useState( defaultSettings );
	const [ isLoading, setIsLoading ] = useState( false );
	const { notice, notify, dismiss } = useNotice();

	const handleSubmit = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setIsLoading( true );
		dismiss();

		try {
			const res = await apiCall( 'update_settings', {
				tab: 'preload_settings',
				settings,
			} );

			if ( res.success ) {
				notify( {
					type: 'success',
					message:
						res.message ||
						__(
							'Settings updated successfully.',
							'performance-optimisation'
						),
				} );
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Failed to update settings.',
							'performance-optimisation'
						),
				} );
			}
		} catch ( err ) {
			console.error( 'Failed updating preload settings', err );
			notify( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
			} );
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'Preload Settings', 'performance-optimisation' ) }
				description={ __(
					'Improve perceived performance by pre-connecting to domains and preloading critical assets.',
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
			</FeatureHeader>

			<form onSubmit={ handleSubmit } className="wppo-stacked-cards">
				<FeatureCard
					title={ __( 'Cache Warm-up', 'performance-optimisation' ) }
					icon={ <FontAwesomeIcon icon={ faHourglassStart } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label={ __(
								'Enable Preload Cache',
								'performance-optimisation'
							) }
							description={ __(
								'Automatically visit all pages to pre-generate the cache. The first real visitor gets a fast cached response instead of waiting for a cold page build.',
								'performance-optimisation'
							) }
							name="enablePreloadCache"
							checked={ settings.enablePreloadCache }
							onChange={ handleChange( setSettings ) }
						/>
						{ settings.enablePreloadCache && (
							<div className="wppo-mt-20">
								<label
									className="wppo-field-label"
									htmlFor="excludePreloadCache"
								>
									{ __(
										'Exclude URLs from Cache Warm-up',
										'performance-optimisation'
									) }
								</label>
								<textarea
									className="wppo-textarea"
									id="excludePreloadCache"
									name="excludePreloadCache"
									rows="3"
									placeholder={ __(
										'Regex patterns, one per line',
										'performance-optimisation'
									) }
									value={ settings.excludePreloadCache }
									onChange={ handleChange( setSettings ) }
									aria-describedby="excludePreloadCache-desc"
								/>
								<p
									id="excludePreloadCache-desc"
									className="wppo-text-muted wppo-mt-10 wppo-text-small"
								>
									{ __(
										'Skip dynamic pages like cart, checkout, and account pages that should never be cached. Supports regex patterns.',
										'performance-optimisation'
									) }
								</p>
							</div>
						) }
						<SwitchField
							label={ __(
								'Preload from Sitemap',
								'performance-optimisation'
							) }
							description={ __(
								'Also warm up URLs discovered in your sitemap, so pages outside standard post queries get cached too.',
								'performance-optimisation'
							) }
							name="preloadSitemap"
							checked={ settings.preloadSitemap }
							onChange={ handleChange( setSettings ) }
						/>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Third-Party Connections',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faLink } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label={ __(
								'Preconnect',
								'performance-optimisation'
							) }
							description={ __(
								'Open a TCP/TLS connection to third-party origins before the browser needs them. Eliminates connection setup latency for fonts, analytics, and CDN resources.',
								'performance-optimisation'
							) }
							name="preconnect"
							checked={ settings.preconnect }
							onChange={ handleChange( setSettings ) }
						/>
						{ settings.preconnect && (
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="preconnectOrigins"
								>
									{ __(
										'Preconnect Origins',
										'performance-optimisation'
									) }
								</label>
								<textarea
									className="wppo-textarea"
									id="preconnectOrigins"
									name="preconnectOrigins"
									rows="2"
									placeholder="https://fonts.googleapis.com"
									value={ settings.preconnectOrigins }
									onChange={ handleChange( setSettings ) }
									aria-describedby="preconnectOrigins-desc"
								/>
								<p
									id="preconnectOrigins-desc"
									className="wppo-text-muted wppo-mt-10 wppo-text-small"
								>
									{ __(
										'One origin per line. Use full URLs including protocol.',
										'performance-optimisation'
									) }
								</p>
							</div>
						) }

						<SwitchField
							label={ __(
								'DNS Prefetch',
								'performance-optimisation'
							) }
							description={ __(
								'Resolve domain names in the background before the browser requests resources from them. Faster than preconnect but only handles DNS — useful for domains you do not need a full connection to immediately.',
								'performance-optimisation'
							) }
							name="prefetchDNS"
							checked={ settings.prefetchDNS }
							onChange={ handleChange( setSettings ) }
						/>
						{ settings.prefetchDNS && (
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="dnsPrefetchOrigins"
								>
									{ __(
										'DNS Prefetch Origins',
										'performance-optimisation'
									) }
								</label>
								<textarea
									className="wppo-textarea"
									id="dnsPrefetchOrigins"
									name="dnsPrefetchOrigins"
									rows="2"
									placeholder="example.com"
									value={ settings.dnsPrefetchOrigins }
									onChange={ handleChange( setSettings ) }
									aria-describedby="dnsPrefetchOrigins-desc"
								/>
								<p
									id="dnsPrefetchOrigins-desc"
									className="wppo-text-muted wppo-mt-10 wppo-text-small"
								>
									{ __(
										'One hostname per line, without protocol (e.g.',
										'performance-optimisation'
									) }{ ' ' }
									<code>cdn.example.com</code>).
								</p>
							</div>
						) }
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Critical Assets Preloading',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faFont } /> }
				>
					<div className="wppo-stacked-cards">
						<div className="wppo-field-group">
							<SwitchField
								label={ __(
									'Preload Fonts',
									'performance-optimisation'
								) }
								description={ __(
									'Inject preload hints for critical font files so the browser fetches them at the highest priority. Eliminates the flash of invisible text (FOIT) on first load.',
									'performance-optimisation'
								) }
								name="preloadFonts"
								checked={ settings.preloadFonts }
								onChange={ handleChange( setSettings ) }
							/>
							{ settings.preloadFonts && (
								<div className="wppo-field">
									<label
										className="wppo-field-label"
										htmlFor="preloadFontsUrls"
									>
										{ __(
											'Font URLs to Preload',
											'performance-optimisation'
										) }
									</label>
									<textarea
										className="wppo-textarea"
										id="preloadFontsUrls"
										name="preloadFontsUrls"
										rows="3"
										placeholder="/wp-content/themes/my-theme/fonts/myfont.woff2"
										value={ settings.preloadFontsUrls }
										onChange={ handleChange( setSettings ) }
										aria-describedby="preloadFontsUrls-desc"
									/>
									<p
										id="preloadFontsUrls-desc"
										className="wppo-text-muted wppo-mt-10 wppo-text-small"
									>
										{ __(
											'One URL per line. Prefer .woff2 format for best browser support.',
											'performance-optimisation'
										) }
									</p>
								</div>
							) }
						</div>
						<div className="wppo-field-group">
							<SwitchField
								label={ __(
									'Preload Critical CSS',
									'performance-optimisation'
								) }
								description={ __(
									'Inject preload hints for above-the-fold stylesheets. Ensures critical styles are fetched before the browser renders the page, reducing render-blocking delays.',
									'performance-optimisation'
								) }
								name="preloadCSS"
								checked={ settings.preloadCSS }
								onChange={ handleChange( setSettings ) }
							/>
							{ settings.preloadCSS && (
								<div className="wppo-field">
									<label
										className="wppo-field-label"
										htmlFor="preloadCSSUrls"
									>
										{ __(
											'CSS URLs to Preload',
											'performance-optimisation'
										) }
									</label>
									<textarea
										className="wppo-textarea"
										id="preloadCSSUrls"
										name="preloadCSSUrls"
										rows="3"
										placeholder="/wp-content/themes/my-theme/style.css"
										value={ settings.preloadCSSUrls }
										onChange={ handleChange( setSettings ) }
										aria-describedby="preloadCSSUrls-desc"
									/>
									<p
										id="preloadCSSUrls-desc"
										className="wppo-text-muted wppo-mt-10 wppo-text-small"
									>
										{ __(
											'One URL per line. Only add stylesheets needed for above-the-fold content.',
											'performance-optimisation'
										) }
									</p>
								</div>
							) }
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Speculative Loading',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faRocket } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label={ __(
								'Enable Speculative Loading',
								'performance-optimisation'
							) }
							description={ __(
								'When enabled, browsers may prerender or prefetch linked pages before navigation for near-instant load times.',
								'performance-optimisation'
							) }
							name="enableSpeculationRules"
							checked={ settings.enableSpeculationRules }
							onChange={ handleChange( setSettings ) }
						/>
						{ settings.enableSpeculationRules && (
							<>
								<div className="wppo-field">
									<label
										className="wppo-field-label"
										htmlFor="speculationMode"
									>
										{ __(
											'Speculation Mode',
											'performance-optimisation'
										) }
									</label>
									<select
										className="wppo-select"
										id="speculationMode"
										name="speculationMode"
										value={ settings.speculationMode }
										onChange={ handleChange( setSettings ) }
									>
										<option value="prefetch">
											{ __(
												'Prefetch (faster navigation, lower resource usage)',
												'performance-optimisation'
											) }
										</option>
										<option value="prerender">
											{ __(
												'Prerender (instant navigation, higher resource usage)',
												'performance-optimisation'
											) }
										</option>
									</select>
								</div>
								<div className="wppo-field">
									<label
										className="wppo-field-label"
										htmlFor="speculationEagerness"
									>
										{ __(
											'Eagerness',
											'performance-optimisation'
										) }
									</label>
									<select
										className="wppo-select"
										id="speculationEagerness"
										name="speculationEagerness"
										value={ settings.speculationEagerness }
										onChange={ handleChange( setSettings ) }
									>
										<option value="conservative">
											{ __(
												'Conservative (only on hover/touch)',
												'performance-optimisation'
											) }
										</option>
										<option value="moderate">
											{ __(
												'Moderate (on hover + nearby links)',
												'performance-optimisation'
											) }
										</option>
										<option value="eager">
											{ __(
												'Eager (immediately on page load)',
												'performance-optimisation'
											) }
										</option>
									</select>
								</div>
								<div className="wppo-field">
									<label
										className="wppo-field-label"
										htmlFor="speculationExcludeUrls"
									>
										{ __(
											'Exclude URLs from Speculation',
											'performance-optimisation'
										) }
									</label>
									<textarea
										className="wppo-textarea"
										id="speculationExcludeUrls"
										name="speculationExcludeUrls"
										rows="3"
										placeholder="/my-plugin/*\n/excluded-path/*"
										value={
											settings.speculationExcludeUrls
										}
										onChange={ handleChange( setSettings ) }
										aria-describedby="speculationExcludeUrls-desc"
									/>
									<p
										id="speculationExcludeUrls-desc"
										className="wppo-text-muted wppo-mt-10 wppo-text-small"
									>
										{ __(
											'Add URL patterns to exclude from speculative loading. Supports wildcard (*) patterns. WooCommerce cart, checkout, and account pages are excluded automatically.',
											'performance-optimisation'
										) }
									</p>
								</div>
							</>
						) }
					</div>
				</FeatureCard>
			</form>
		</div>
	);
};

export default PreloadSettings;
