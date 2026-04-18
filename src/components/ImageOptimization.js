import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import SwitchField from './common/SwitchField';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faEye,
	faMagic,
	faCloudUploadAlt,
	faCheckCircle,
	faExclamationTriangle,
	faTimes,
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';

const ImageOptimization = ( { options = {} } ) => {
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
		preloadFrontPageImages: false,
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
	const [ notification, setNotification ] = useState( null );

	useEffect( () => {
		if ( notification ) {
			const timer = setTimeout( () => setNotification( null ), 5000 );
			return () => clearTimeout( timer );
		}
	}, [ notification ] );

	const togglePostType = ( type ) => {
		setSettings( ( prev ) => {
			const newSelected = prev.selectedPostType.includes( type )
				? prev.selectedPostType.filter( ( t ) => t !== type )
				: [ ...prev.selectedPostType, type ];
			return { ...prev, selectedPostType: newSelected };
		} );
	};

	const onSubmit = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setIsLoading( true );
		try {
			const res = await apiCall( 'update_settings', {
				tab: 'image_optimisation',
				settings,
			} );

			if ( res.success ) {
				setNotification( {
					type: 'success',
					message:
						res.message ||
						__(
							'Settings saved successfully.',
							'performance-optimisation'
						),
				} );
			} else {
				setNotification( {
					type: 'error',
					message:
						res.message ||
						__(
							'Error saving settings.',
							'performance-optimisation'
						),
				} );
			}
		} catch ( error ) {
			setNotification( {
				type: 'error',
				message:
					error.message ||
					__( 'Error saving settings.', 'performance-optimisation' ),
			} );
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'Image Optimization', 'performance-optimisation' ) }
				description={ __(
					'Optimize media delivery with advanced lazy loading, next-gen formats, and preloading rules.',
					'performance-optimisation'
				) }
				actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						isLoading={ isLoading }
						onClick={ onSubmit }
						label={ __(
							'Save Settings',
							'performance-optimisation'
						) }
					/>
				}
			/>

			{ notification && (
				<div
					className={ `wppo-notice wppo-notice--${ notification.type }` }
				>
					<div className="wppo-notice__content">
						<FontAwesomeIcon
							icon={
								notification.type === 'success'
									? faCheckCircle
									: faExclamationTriangle
							}
						/>
						<span>{ notification.message }</span>
					</div>
					<button
						className="wppo-notice__dismiss"
						type="button"
						onClick={ () => setNotification( null ) }
						aria-label="Dismiss"
					>
						<FontAwesomeIcon icon={ faTimes } />
					</button>
				</div>
			) }

			<div className="wppo-stacked-cards">
				<FeatureCard
					title="Lazy Loading"
					icon={ <FontAwesomeIcon icon={ faEye } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label="Enable Lazy Load"
							description="Images below the fold are loaded only when the user scrolls near them. Reduces initial page weight and improves Largest Contentful Paint (LCP) for above-the-fold content."
							name="lazyLoadImages"
							checked={ settings.lazyLoadImages }
							onChange={ handleChange( setSettings ) }
						/>

						{ settings.lazyLoadImages && (
							<div className="wppo-field-nest">
								<div className="wppo-field">
									<label
										className="wppo-field-label"
										htmlFor="excludeFirstImages"
									>
										Exclude First X Images
									</label>
									<input
										className="wppo-input"
										id="excludeFirstImages"
										type="number"
										name="excludeFirstImages"
										value={ settings.excludeFirstImages }
										onChange={ handleChange( setSettings ) }
									/>
									<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
										Skip lazy loading for the first N images
										on the page. Set to 1–3 to ensure your
										hero/banner image loads immediately
										without waiting for scroll.
									</p>
								</div>
								<SwitchField
									label="SVG Placeholders"
									description="Replace the image src with a lightweight inline SVG blur placeholder while the real image loads. Prevents layout shift and gives a smooth loading experience."
									name="replacePlaceholderWithSVG"
									checked={
										settings.replacePlaceholderWithSVG
									}
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						) }

						<SwitchField
							label="Wrap in Picture Tag"
							description="Wrap <img> elements in a <picture> element to enable serving next-gen formats (WebP/AVIF) with a fallback for older browsers. Required for format conversion to work."
							name="wrapInPicture"
							checked={ settings.wrapInPicture }
							onChange={ handleChange( setSettings ) }
						/>
					</div>
				</FeatureCard>

				<FeatureCard
					title="Video & Media"
					icon={ <FontAwesomeIcon icon={ faMagic } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label="Video Lazy Loading"
							description="Defer loading of <iframe> and <video> embeds until they enter the viewport. Significantly reduces initial page load time for pages with embedded YouTube, Vimeo, or other media."
							name="lazyLoadVideos"
							checked={ settings.lazyLoadVideos }
							onChange={ handleChange( setSettings ) }
						/>

						<div className="wppo-field">
							<label
								className="wppo-field-label"
								htmlFor="excludeVideos"
							>
								Exclude from Video Lazy Load
							</label>
							<textarea
								className="wppo-textarea"
								id="excludeVideos"
								name="excludeVideos"
								rows="3"
								placeholder="Class names or partial URLs (one per line)"
								value={ settings.excludeVideos }
								onChange={ handleChange( setSettings ) }
							/>
							<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
								Enter CSS class names or partial URLs of embeds
								that should always load immediately.
							</p>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title="Next-Gen Conversion"
					icon={ <FontAwesomeIcon icon={ faMagic } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label="Auto Convert Formats"
							description="Automatically convert uploaded JPEG/PNG images to modern formats (WebP or AVIF). Modern formats are 25–50% smaller than JPEG at the same quality, directly improving page speed scores."
							name="convertImg"
							checked={ settings.convertImg }
							onChange={ handleChange( setSettings ) }
						/>

						{ settings.convertImg && (
							<div className="wppo-field-nest">
								<div className="wppo-field">
									<label
										className="wppo-field-label"
										htmlFor="conversionFormat"
									>
										Target Format
									</label>
									<select
										className="wppo-select"
										id="conversionFormat"
										name="conversionFormat"
										value={ settings.conversionFormat }
										onChange={ handleChange( setSettings ) }
									>
										<option value="webp">
											WebP (Standard — 95%+ browser
											support)
										</option>
										<option value="avif">
											AVIF (Maximum Compression — newer
											browsers only)
										</option>
										<option value="both">
											Both (Best Compatibility — serves
											AVIF where supported, WebP as
											fallback)
										</option>
									</select>
								</div>
								<div className="wppo-field wppo-field--spaced">
									<label
										className="wppo-field-label"
										htmlFor="excludeConvertImages"
									>
										Exclude from Conversion
									</label>
									<textarea
										className="wppo-textarea"
										id="excludeConvertImages"
										name="excludeConvertImages"
										rows="2"
										placeholder="Partial URLs (one per line)"
										value={ settings.excludeConvertImages }
										onChange={ handleChange( setSettings ) }
									/>
									<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
										Images matching these partial URLs will
										keep their original format. Useful for
										logos or images where exact color
										accuracy matters.
									</p>
								</div>
							</div>
						) }
					</div>
				</FeatureCard>

				<FeatureCard
					title="Responsive Limits"
					icon={ <FontAwesomeIcon icon={ faMagic } /> }
				>
					<div className="wppo-field-group">
						<div className="wppo-field">
							<label
								className="wppo-field-label"
								htmlFor="maxWidthImgSize"
							>
								Max Image Width (px)
							</label>
							<input
								className="wppo-input"
								id="maxWidthImgSize"
								type="number"
								name="maxWidthImgSize"
								value={ settings.maxWidthImgSize }
								onChange={ handleChange( setSettings ) }
							/>
							<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
								Images wider than this value will have a{ ' ' }
								<code>max-width</code> style applied. Set to{ ' ' }
								<code>0</code> to disable. Useful for preventing
								oversized images from breaking layouts on small
								screens.
							</p>
						</div>
						<div className="wppo-field">
							<label
								className="wppo-field-label"
								htmlFor="excludeSize"
							>
								Exclude Classes from Max Width
							</label>
							<input
								className="wppo-input"
								id="excludeSize"
								type="text"
								name="excludeSize"
								placeholder="e.g. .no-resize, .hero-image"
								value={ settings.excludeSize }
								onChange={ handleChange( setSettings ) }
							/>
							<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
								Comma-separated CSS class names. Images with
								these classes will not have the max-width
								constraint applied.
							</p>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title="Advanced Preloading"
					icon={ <FontAwesomeIcon icon={ faCloudUploadAlt } /> }
				>
					<div className="wppo-stacked-cards">
						<div>
							<SwitchField
								label={ __(
									'Preload Front Page Images',
									'performance-optimisation'
								) }
								description={ __(
									'Inject <link rel="preload"> hints for critical images on your homepage. Tells the browser to fetch these images at the highest priority, improving LCP scores for your most visited page.',
									'performance-optimisation'
								) }
								name="preloadFrontPageImages"
								checked={ settings.preloadFrontPageImages }
								onChange={ handleChange( setSettings ) }
							/>
							{ settings.preloadFrontPageImages && (
								<div className="wppo-field wppo-mt-12">
									<label
										className="wppo-field-label"
										htmlFor="preloadFrontPageImagesUrls"
									>
										{ __(
											'Frontpage Image URLs to Preload',
											'performance-optimisation'
										) }
									</label>
									<textarea
										className="wppo-textarea"
										id="preloadFrontPageImagesUrls"
										name="preloadFrontPageImagesUrls"
										rows="3"
										placeholder="/wp-content/uploads/hero.jpg"
										value={
											settings.preloadFrontPageImagesUrls
										}
										onChange={ handleChange( setSettings ) }
									/>
									<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
										One URL per line. Only add
										above-the-fold images — preloading too
										many images can hurt performance.
									</p>
								</div>
							) }
						</div>
						<div>
							<SwitchField
								label="Preload Featured Images"
								description="Automatically add preload hints for the featured image of posts and pages. Select which post types to apply this to below. Improves LCP for archive and single post pages."
								name="preloadPostTypeImage"
								checked={ settings.preloadPostTypeImage }
								onChange={ handleChange( setSettings ) }
							/>
							{ settings.preloadPostTypeImage && (
								<>
									<div className="wppo-post-types-grid--chips">
										{ settings.availablePostTypes.map(
											( type ) => (
												<label
													key={ type }
													htmlFor={ `type-${ type }` }
													className={ `wppo-post-type-chip ${
														settings.selectedPostType.includes(
															type
														)
															? 'active'
															: ''
													}` }
												>
													<input
														type="checkbox"
														id={ `type-${ type }` }
														className="screen-reader-text"
														checked={ settings.selectedPostType.includes(
															type
														) }
														onChange={ () =>
															togglePostType(
																type
															)
														}
													/>
													{ type }
												</label>
											)
										) }
									</div>
									<div className="wppo-field wppo-field--spaced">
										<label
											className="wppo-field-label"
											htmlFor="excludePostTypeImgUrl"
										>
											Exclude URLs from Preload
										</label>
										<textarea
											className="wppo-textarea"
											id="excludePostTypeImgUrl"
											name="excludePostTypeImgUrl"
											rows="2"
											placeholder="Partial URLs (one per line)"
											value={
												settings.excludePostTypeImgUrl
											}
											onChange={ handleChange(
												setSettings
											) }
										/>
									</div>
								</>
							) }
						</div>
					</div>
				</FeatureCard>
			</div>
		</div>
	);
};

export default ImageOptimization;
