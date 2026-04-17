import { useState } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faEye,
	faMagic,
	faCloudUploadAlt,
	faInfoCircle,
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';

const ImageOptimization = ( { options = {} } ) => {
	const translations = wppoSettings.translations;

	const defaultSettings = {
		lazyLoadImages: false,
		wrapInPicture: true,
		excludeFirstImages: 0,
		excludeImages: '',
		lazyLoadVideos: false,
		excludeVideos: '',
		convertImg: false,
		conversionFormat: 'webp',
		excludeConvertImages: '',
		replacePlaceholderWithSVG: false,
		preloadFrontPageImagesUrls: '',
		preloadPostTypeImage: false,
		selectedPostType: [],
		availablePostTypes: [],
		excludePostTypeImgUrl: '',
		maxWidthImgSize: 0,
		excludeSize: '',
		...options,
	};

	const [ settings, setSettings ] = useState( defaultSettings );
	const [ isLoading, setIsLoading ] = useState( false );

	const togglePostType = ( type ) => {
		const newSelected = settings.selectedPostType.includes( type )
			? settings.selectedPostType.filter( ( t ) => t !== type )
			: [ ...settings.selectedPostType, type ];
		setSettings( { ...settings, selectedPostType: newSelected } );
	};

	const onSubmit = async ( e ) => {
		if ( e ) e.preventDefault();
		setIsLoading( true );
		try {
			await apiCall( 'update_settings', { tab: 'image_optimisation', settings } );
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title="Image Optimization"
				description="Optimize media delivery with advanced lazy loading, next-gen formats, and preloading rules."
				actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						isLoading={ isLoading }
						onClick={ onSubmit }
						label="Save Settings"
					/>
				}
			/>

			<div className="wppo-grid-2-col">
				<FeatureCard title="Lazy Loading" icon={ <FontAwesomeIcon icon={ faEye } /> }>
					<div className="wppo-field-group">
						<div className="wppo-switch-field">
							<div>
								<strong>Enable Lazy Load</strong>
								<p className="wppo-text-muted">Delay loading of images until scroll.</p>
							</div>
							<label className="wppo-switch">
								<input type="checkbox" name="lazyLoadImages" checked={ settings.lazyLoadImages } onChange={ handleChange( setSettings ) } />
								<span className="wppo-slider"></span>
							</label>
						</div>

						{ settings.lazyLoadImages && (
							<div className="wppo-field-nest">
								<div className="wppo-field">
									<label className="field-label">Exclude First X Images</label>
									<input className="wppo-input" type="number" name="excludeFirstImages" value={ settings.excludeFirstImages } onChange={ handleChange( setSettings ) } />
								</div>
								<div className="wppo-switch-field" style={ { marginTop: '16px' } }>
									<div>
										<strong>SVG Placeholders</strong>
										<p className="wppo-text-muted">Lightweight blurred placeholders.</p>
									</div>
									<label className="wppo-switch">
										<input type="checkbox" name="replacePlaceholderWithSVG" checked={ settings.replacePlaceholderWithSVG } onChange={ handleChange( setSettings ) } />
										<span className="wppo-slider"></span>
									</label>
								</div>
							</div>
						) }

						<div className="wppo-switch-field">
							<div>
								<strong>Wrap in Picture Tag</strong>
								<p className="wppo-text-muted">Use modern picture elements.</p>
							</div>
							<label className="wppo-switch">
								<input type="checkbox" name="wrapInPicture" checked={ settings.wrapInPicture } onChange={ handleChange( setSettings ) } />
								<span className="wppo-slider"></span>
							</label>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard title="Video & Media" icon={ <FontAwesomeIcon icon={ faMagic } /> }>
					<div className="wppo-field-group">
						<div className="wppo-switch-field">
							<div>
								<strong>Video Lazy Loading</strong>
								<p className="wppo-text-muted">Delay iFrame and video tags.</p>
							</div>
							<label className="wppo-switch">
								<input type="checkbox" name="lazyLoadVideos" checked={ settings.lazyLoadVideos } onChange={ handleChange( setSettings ) } />
								<span className="wppo-slider"></span>
							</label>
						</div>

						<div className="wppo-field">
							<label className="field-label">Exclude from Lazy Load</label>
							<textarea className="wppo-textarea" name="excludeImages" rows="3" placeholder="Class names or partial URLs" value={ settings.excludeImages } onChange={ handleChange( setSettings ) } />
						</div>
					</div>
				</FeatureCard>
			</div>

			<div className="wppo-grid-2-col">
				<FeatureCard title="Next-Gen Conversion" icon={ <FontAwesomeIcon icon={ faMagic } /> }>
					<div className="wppo-field-group">
						<div className="wppo-switch-field">
							<div>
								<strong>Auto Convert Formats</strong>
								<p className="wppo-text-muted">Serve modern formats automatically.</p>
							</div>
							<label className="wppo-switch">
								<input type="checkbox" name="convertImg" checked={ settings.convertImg } onChange={ handleChange( setSettings ) } />
								<span className="wppo-slider"></span>
							</label>
						</div>

						{ settings.convertImg && (
							<div className="wppo-field-nest">
								<div className="wppo-field">
									<label className="field-label">Target Format</label>
									<select className="wppo-select" name="conversionFormat" value={ settings.conversionFormat } onChange={ handleChange( setSettings ) }>
										<option value="webp">WebP (Standard)</option>
										<option value="avif">AVIF (Maximum Compression)</option>
										<option value="both">Both (Best Compatibility)</option>
									</select>
								</div>
								<div className="wppo-field" style={ { marginTop: '16px' } }>
									<label className="field-label">Exclude from Conversion</label>
									<textarea className="wppo-textarea" name="excludeConvertImages" rows="2" placeholder="Partial URLs" value={ settings.excludeConvertImages } onChange={ handleChange( setSettings ) } />
								</div>
							</div>
						) }
					</div>
				</FeatureCard>

				<FeatureCard title="Responsive Limits" icon={ <FontAwesomeIcon icon={ faMagic } /> }>
					<div className="wppo-field-group">
						<div className="wppo-field">
							<label className="field-label">Max Image Width (px)</label>
							<input className="wppo-input" type="number" name="maxWidthImgSize" value={ settings.maxWidthImgSize } onChange={ handleChange( setSettings ) } />
						</div>
						<div className="wppo-field">
							<label className="field-label">Exclude Classes from Max Width</label>
							<input className="wppo-input" type="text" name="excludeSize" placeholder="e.g. .no-resize, .hero" value={ settings.excludeSize } onChange={ handleChange( setSettings ) } />
						</div>
					</div>
				</FeatureCard>
			</div>

			<FeatureCard title="Advanced Preloading" icon={ <FontAwesomeIcon icon={ faCloudUploadAlt } /> }>
				<div className="wppo-grid-2-col">
					<div>
						<label className="field-label">Preload Frontpage URLs</label>
						<textarea className="wppo-textarea" name="preloadFrontPageImagesUrls" rows="4" placeholder="URLs (one per line)" value={ settings.preloadFrontPageImagesUrls } onChange={ handleChange( setSettings ) } />
					</div>
					<div>
						<div className="wppo-switch-field" style={ { marginBottom: '16px' } }>
							<div>
								<strong>Preload Featured Images</strong>
								<p className="wppo-text-muted">Automatic preload for selected types.</p>
							</div>
							<label className="wppo-switch">
								<input type="checkbox" name="preloadPostTypeImage" checked={ settings.preloadPostTypeImage } onChange={ handleChange( setSettings ) } />
								<span className="wppo-slider"></span>
							</label>
						</div>
						{ settings.preloadPostTypeImage && (
							<div className="wppo-post-types-grid" style={ { display: 'flex', flexWrap: 'wrap', gap: '10px' } }>
								{ settings.availablePostTypes.map( ( type ) => (
									<label key={ type } className={ `wppo-post-type-chip ${ settings.selectedPostType.includes( type ) ? 'active' : '' }` }>
										<input type="checkbox" style={ { display: 'none' } } checked={ settings.selectedPostType.includes( type ) } onChange={ () => togglePostType( type ) } />
										{ type }
									</label>
								) ) }
							</div>
						) }
					</div>
				</div>
			</FeatureCard>
		</div>
	);
};

export default ImageOptimization;
