import { useState } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import CheckboxOption from './common/CheckboxOption';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faHourglassStart,
	faLink,
	faFont,
} from '@fortawesome/free-solid-svg-icons';

const PreloadSettings = ( { options = {} } ) => {
	const translations = wppoSettings.translations;

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
		e.preventDefault();
		setIsLoading( true );

		try {
			await apiCall( 'update_settings', {
				tab: 'preload_settings',
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
			<h2>{ translations.preloadSettings }</h2>

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={ faHourglassStart } /> Smart Cache
					Warm-up
				</h3>
				<p>
					Automatically crawl your site to populate the cache before
					users visit, ensuring instant page loads.
				</p>

				<CheckboxOption
					label={ translations.enablePreloadCache }
					checked={ settings.enablePreloadCache }
					onChange={ handleChange( setSettings ) }
					name="enablePreloadCache"
					textareaName="excludePreloadCache"
					textareaPlaceholder={ translations.excludePreloadCache }
					textareaValue={ settings.excludePreloadCache }
					onTextareaChange={ handleChange( setSettings ) }
					description={ translations.enablePreloadCacheDesc }
				/>
			</div>

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={ faLink } /> Connection Prediction
				</h3>
				<p>
					Establish early connections to external domains (CDN, Google
					Fonts, etc.) to reduce latency of subsequent requests.
				</p>

				<CheckboxOption
					label={ translations.preconnect }
					checked={ settings.preconnect }
					onChange={ handleChange( setSettings ) }
					name="preconnect"
					textareaName="preconnectOrigins"
					textareaPlaceholder={ translations.preconnectOrigins }
					textareaValue={ settings.preconnectOrigins }
					onTextareaChange={ handleChange( setSettings ) }
					description={ translations.preconnectDesc }
				/>

				<div style={ { marginTop: '24px' } }>
					<CheckboxOption
						label={ translations.prefetchDNS }
						checked={ settings.prefetchDNS }
						onChange={ handleChange( setSettings ) }
						name="prefetchDNS"
						textareaName="dnsPrefetchOrigins"
						textareaPlaceholder={ translations.dnsPrefetchOrigins }
						textareaValue={ settings.dnsPrefetchOrigins }
						onTextareaChange={ handleChange( setSettings ) }
						description={ translations.prefetchDNSDesc }
					/>
				</div>
			</div>

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={ faFont } /> Critical Asset Priority
				</h3>
				<p>
					Identify and preload essential resources like fonts and CSS
					to prevent layout shifts and improve rendering speed.
				</p>

				<CheckboxOption
					label={ translations.preloadFonts }
					checked={ settings.preloadFonts }
					onChange={ handleChange( setSettings ) }
					name="preloadFonts"
					textareaName="preloadFontsUrls"
					textareaPlaceholder={ translations.preloadFontsUrls }
					textareaValue={ settings.preloadFontsUrls }
					onTextareaChange={ handleChange( setSettings ) }
					description={ translations.preloadFontsDesc }
				/>

				<div style={ { marginTop: '24px' } }>
					<CheckboxOption
						label={ translations.preloadCSS }
						checked={ settings.preloadCSS }
						onChange={ handleChange( setSettings ) }
						name="preloadCSS"
						textareaName="preloadCSSUrls"
						textareaPlaceholder={ translations.preloadCSSUrls }
						textareaValue={ settings.preloadCSSUrls }
						onTextareaChange={ handleChange( setSettings ) }
						description={ translations.preloadCSSDesc }
					/>
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

export default PreloadSettings;
