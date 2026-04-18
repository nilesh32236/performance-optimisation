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

	const handleSubmit = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setIsLoading( true );

		try {
			await apiCall( 'update_settings', {
				tab: 'preload_settings',
				settings,
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
			/>

			<form onSubmit={ handleSubmit } className="wppo-grid-2-col">
				<FeatureCard
					title="Cache Warm-up"
					icon={ <FontAwesomeIcon icon={ faHourglassStart } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label="Enable Preload Cache"
							description="Crawl site to populate cache."
							name="enablePreloadCache"
							checked={ settings.enablePreloadCache }
							onChange={ handleChange( setSettings ) }
						/>
						{ settings.enablePreloadCache && (
							<div>
								<label
									className="wppo-field-label"
									htmlFor="excludePreloadCache"
								>
									Exclude URLs
								</label>
								<textarea
									className="wppo-textarea"
									id="excludePreloadCache"
									name="excludePreloadCache"
									rows="3"
									value={ settings.excludePreloadCache }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						) }
					</div>
				</FeatureCard>

				<FeatureCard
					title="Connections"
					icon={ <FontAwesomeIcon icon={ faLink } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label="Preconnect"
							description="Establish early server connections."
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
									placeholder="https://example.com"
									value={ settings.preconnectOrigins }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						) }

						<SwitchField
							label="DNS Prefetch"
							description="Resolve domain names early."
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
							</div>
						) }
					</div>
				</FeatureCard>
			</form>

			<form onSubmit={ handleSubmit }>
				<FeatureCard
					title="Critical Assets"
					icon={ <FontAwesomeIcon icon={ faFont } /> }
				>
					<div className="wppo-grid-2-col">
						<div className="wppo-field-group">
							<SwitchField
								label="Preload Fonts"
								description="Load critical fonts early."
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
										placeholder="Font URLs (one per line)"
										value={ settings.preloadFontsUrls }
										onChange={ handleChange( setSettings ) }
									/>
								</div>
							) }
						</div>
						<div className="wppo-field-group">
							<SwitchField
								label="Preload Critical CSS"
								description="Load essential stylesheets early."
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
										placeholder="CSS URLs (one per line)"
										value={ settings.preloadCSSUrls }
										onChange={ handleChange( setSettings ) }
									/>
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
