import { useState } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import SwitchField from './common/SwitchField';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faHourglassStart,
	faLink,
	faFont,
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';

const PreloadSettings = ( { options = {} } ) => {
	const defaultSettings = {
		enablePreloadCache: false,
		excludePreloadCache: 'my-account/(.*)\ncart/(.*)\ncheckout/(.*)',
		preconnect: false,
		preconnectOrigins: '',
		prefetchDNS: false,
		dnsPrefetchOrigins: '',
		preloadFonts: false,
		preloadFontsUrls: '',
		preloadCSS: false,
		preloadCSSUrls: '',
		...options,
	};

	const [ settings, setSettings ] = useState( defaultSettings );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ notification, setNotification ] = useState( {
		message: '',
		success: false,
	} );

	const handleSubmit = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setNotification( { message: '', success: false } );

		try {
			const res = await apiCall( 'update_settings', {
				tab: 'preload_settings',
				settings,
			} );

			if ( res.success ) {
				setNotification( {
					message: res.message || 'Settings updated successfully.',
					success: true,
				} );
			} else {
				setNotification( {
					message: res.message || 'Failed to update settings.',
					success: false,
				} );
			}
		} catch ( err ) {
			console.error( 'Failed updating preload settings', err );
			setNotification( {
				message: 'An unexpected error occurred.',
				success: false,
			} );
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title="Preload Settings"
				description="Improve perceived performance by pre-connecting to domains and preloading critical assets."
				actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						isLoading={ isLoading }
						onClick={ handleSubmit }
						label="Save Settings"
					/>
				}
			>
				{ notification.message && (
					<div
						className={ `wppo-notice wppo-notice--${
							notification.success ? 'success' : 'error'
						} wppo-mb-20` }
					>
						<span>{ notification.message }</span>
					</div>
				) }
			</FeatureHeader>

			<form onSubmit={ handleSubmit } className="wppo-stacked-cards">
				<FeatureCard
					title="Cache Warm-up"
					icon={ <FontAwesomeIcon icon={ faHourglassStart } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label="Enable Preload Cache"
							description="Automatically visit all pages to pre-generate the cache. The first real visitor gets a fast cached response instead of waiting for a cold page build."
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
									Exclude URLs from Cache Warm-up
								</label>
								<textarea
									className="wppo-textarea"
									id="excludePreloadCache"
									name="excludePreloadCache"
									rows="3"
									placeholder="Regex patterns, one per line"
									value={ settings.excludePreloadCache }
									onChange={ handleChange( setSettings ) }
								/>
								<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
									Skip dynamic pages like cart, checkout, and
									account pages that should never be cached.
									Supports regex patterns.
								</p>
							</div>
						) }
					</div>
				</FeatureCard>

				<FeatureCard
					title="Third-Party Connections"
					icon={ <FontAwesomeIcon icon={ faLink } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label="Preconnect"
							description="Open a TCP/TLS connection to third-party origins before the browser needs them. Eliminates connection setup latency for fonts, analytics, and CDN resources."
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
									Preconnect Origins
								</label>
								<textarea
									className="wppo-textarea"
									id="preconnectOrigins"
									name="preconnectOrigins"
									rows="2"
									placeholder="https://fonts.googleapis.com"
									value={ settings.preconnectOrigins }
									onChange={ handleChange( setSettings ) }
								/>
								<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
									One origin per line. Use full URLs including
									protocol.
								</p>
							</div>
						) }

						<SwitchField
							label="DNS Prefetch"
							description="Resolve domain names in the background before the browser requests resources from them. Faster than preconnect but only handles DNS — useful for domains you don't need a full connection to immediately."
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
									DNS Prefetch Origins
								</label>
								<textarea
									className="wppo-textarea"
									id="dnsPrefetchOrigins"
									name="dnsPrefetchOrigins"
									rows="2"
									placeholder="example.com"
									value={ settings.dnsPrefetchOrigins }
									onChange={ handleChange( setSettings ) }
								/>
								<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
									One hostname per line, without protocol
									(e.g. <code>cdn.example.com</code>).
								</p>
							</div>
						) }
					</div>
				</FeatureCard>

				<FeatureCard
					title="Critical Assets Preloading"
					icon={ <FontAwesomeIcon icon={ faFont } /> }
				>
					<div className="wppo-stacked-cards">
						<div className="wppo-field-group">
							<SwitchField
								label="Preload Fonts"
								description='Inject &lt;link rel="preload"&gt; hints for critical font files so the browser fetches them at the highest priority. Eliminates the flash of invisible text (FOIT) on first load.'
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
										Font URLs to Preload
									</label>
									<textarea
										className="wppo-textarea"
										id="preloadFontsUrls"
										name="preloadFontsUrls"
										rows="3"
										placeholder="/wp-content/themes/my-theme/fonts/myfont.woff2"
										value={ settings.preloadFontsUrls }
										onChange={ handleChange( setSettings ) }
									/>
									<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
										One URL per line. Prefer .woff2 format
										for best browser support.
									</p>
								</div>
							) }
						</div>
						<div className="wppo-field-group">
							<SwitchField
								label="Preload Critical CSS"
								description='Inject &lt;link rel="preload"&gt; hints for above-the-fold stylesheets. Ensures critical styles are fetched before the browser renders the page, reducing render-blocking delays.'
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
										CSS URLs to Preload
									</label>
									<textarea
										className="wppo-textarea"
										id="preloadCSSUrls"
										name="preloadCSSUrls"
										rows="3"
										placeholder="/wp-content/themes/my-theme/style.css"
										value={ settings.preloadCSSUrls }
										onChange={ handleChange( setSettings ) }
									/>
									<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
										One URL per line. Only add stylesheets
										needed for above-the-fold content.
									</p>
								</div>
							) }
						</div>
					</div>
				</FeatureCard>
			</form>
		</div>
	);
};

export default PreloadSettings;
