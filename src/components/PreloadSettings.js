import { useState } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
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
		if ( e ) e.preventDefault();
		setIsLoading( true );

		try {
			await apiCall( 'update_settings', { tab: 'preload_settings', settings } );
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

			<div className="wppo-grid-2-col">
				<FeatureCard title="Cache Warm-up" icon={ <FontAwesomeIcon icon={ faHourglassStart } /> }>
					<div style={ { display: 'flex', flexDirection: 'column', gap: '20px' } }>
						<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between' } }>
							<div>
								<strong>Enable Preload Cache</strong>
								<p className="wppo-text-muted" style={ { margin: '4px 0 0 0', fontSize: '13px' } }>Crawl site to populate cache.</p>
							</div>
							<label className="wppo-switch">
								<input type="checkbox" name="enablePreloadCache" checked={ settings.enablePreloadCache } onChange={ handleChange( setSettings ) } />
								<span className="wppo-slider"></span>
							</label>
						</div>
						{ settings.enablePreloadCache && (
							<div>
								<label className="field-label">Exclude URLs</label>
								<textarea className="wppo-textarea" name="excludePreloadCache" rows="3" value={ settings.excludePreloadCache } onChange={ handleChange( setSettings ) } />
							</div>
						) }
					</div>
				</FeatureCard>

				<FeatureCard title="Connections" icon={ <FontAwesomeIcon icon={ faLink } /> }>
					<div style={ { display: 'flex', flexDirection: 'column', gap: '20px' } }>
						<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between' } }>
							<div>
								<strong>Preconnect</strong>
								<p className="wppo-text-muted" style={ { margin: '4px 0 0 0', fontSize: '13px' } }>Establish early server connections.</p>
							</div>
							<label className="wppo-switch">
								<input type="checkbox" name="preconnect" checked={ settings.preconnect } onChange={ handleChange( setSettings ) } />
								<span className="wppo-slider"></span>
							</label>
						</div>
						<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between' } }>
							<div>
								<strong>DNS Prefetch</strong>
								<p className="wppo-text-muted" style={ { margin: '4px 0 0 0', fontSize: '13px' } }>Resolve domain names early.</p>
							</div>
							<label className="wppo-switch">
								<input type="checkbox" name="prefetchDNS" checked={ settings.prefetchDNS } onChange={ handleChange( setSettings ) } />
								<span className="wppo-slider"></span>
							</label>
						</div>
					</div>
				</FeatureCard>
			</div>

			<FeatureCard title="Critical Assets" icon={ <FontAwesomeIcon icon={ faFont } /> }>
				<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '24px' } }>
					<div>
						<label className="field-label">Preload Fonts</label>
						<textarea className="wppo-textarea" name="preloadFontsUrls" rows="3" placeholder="Font URLs (one per line)" value={ settings.preloadFontsUrls } onChange={ handleChange( setSettings ) } />
					</div>
					<div>
						<label className="field-label">Preload Critical CSS</label>
						<textarea className="wppo-textarea" name="preloadCSSUrls" rows="3" placeholder="CSS URLs (one per line)" value={ settings.preloadCSSUrls } onChange={ handleChange( setSettings ) } />
					</div>
				</div>
			</FeatureCard>
		</div>
	);
};

export default PreloadSettings;
