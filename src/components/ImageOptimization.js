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
		lazyLoadNative: false,
		wrapInPicture: true,
		excludeFirstImages: 0,
		excludeImages: '',

		lazyLoadVideos: false,
		enableVideoPlaceholder: false,
		excludeVideos: '',
		convertImg: false,
		conversionFormat: 'webp',
		excludeConvertImages: '',
		preloadFrontPageImages: false,
		preloadFrontPageImagesUrls: '',
		preloadPostTypeImage: false,
		selectedPostType: [],
		availablePostTypes: [],
		excludePostTypeImgUrl: '',
		maxWidthImgSize: 0,
		excludeSize: '',
		autoPreloadLCP: false,
		...options,
		placeholderType:
			options.placeholderType ||
			( options.replacePlaceholderWithSVG ? 'svg' : 'none' ),
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
					role="alert"
					aria-live="polite"
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
						aria-label={ __(
							'Dismiss',
							'performance-optimisation'
						) }
					>
						<FontAwesomeIcon icon={ faTimes } />
					</button>
				</div>
			) }

			<div className="wppo-stacked-cards">
				<FeatureCard
					title={ __( 'Lazy Loading', 'performance-optimisation' ) }
					icon={ <FontAwesomeIcon icon={ faEye } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label={ __(
								'Enable Lazy Load',
								'performance-optimisation'
							) }
							description={ __(
								'Images below the fold are loaded only when the user scrolls near them. Reduces initial page weight and improves Largest Contentful Paint (LCP) for above-the-fold content.',
								'performance-optimisation'
							) }
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
										aria-describedby="excludeFirstImages-desc"
									/>
									<p
										id="excludeFirstImages-desc"
										className="wppo-text-muted wppo-mt-10 wppo-text-small"
									>
										Skip lazy loading for the first N images
										on the page. Set to 1–3 to ensure your
										hero/banner image loads immediately
										without waiting for scroll.
									</p>
								</div>
								<SwitchField
									label={
										wppoSettings?.translations
											?.lazyLoadNative ||
										__(
											'Use Native Lazy Loading',
											'performance-optimisation'
										)
									}
									description={
										wppoSettings?.translations
											?.lazyLoadNativeDesc ||
										__(
											'Use the browser\'s native loading="lazy" attribute instead of JavaScript-based IntersectionObserver. Supported in modern browsers and reduces JS overhead.',
											'performance-optimisation'
										)
									}
									name="lazyLoadNative"
									checked={ settings.lazyLoadNative }
									onChange={ handleChange( setSettings ) }
								/>
								<div className="wppo-field">
									<label
										className="wppo-field-label"
										htmlFor="placeholderType"
									>
										{ wppoSettings?.translations
											?.placeholderType ||
											__(
												'Placeholder Type',
												'performance-optimisation'
											) }
									</label>
									<select
										className="wppo-select"
										id="placeholderType"
										name="placeholderType"
										value={ settings.placeholderType }
										onChange={ handleChange( setSettings ) }
									>
										<option value="none">
											{ wppoSettings?.translations
												?.placeholderNone ||
												__(
													'None',
													'performance-optimisation'
												) }
										</option>
										<option value="svg">
											{ wppoSettings?.translations
												?.placeholderSvg ||
												__(
													'SVG Placeholder (Lightweight)',
													'performance-optimisation'
												) }
										</option>
										<option value="dominant_color">
											{ wppoSettings?.translations
												?.placeholderDominantColor ||
												__(
													'Dominant Color (Extracted from Image)',
													'performance-optimisation'
												) }
										</option>
										<option value="lqip">
											{ wppoSettings?.translations
												?.placeholderLqip ||
												__(
													'LQIP (Blur Preview)',
													'performance-optimisation'
												) }
										</option>
									</select>
									<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
										<strong>None:</strong>{ ' ' }
										{ wppoSettings?.translations
											?.placeholderNoneDesc ||
											__(
												'The src attribute is removed until the image is in view.',
												'performance-optimisation'
											) }
										<br />
										<strong>
											{ wppoSettings?.translations
												?.placeholderSvgLabel ||
												__(
													'SVG',
													'performance-optimisation'
												) }
											:
										</strong>{ ' ' }
										{ wppoSettings?.translations
											?.placeholderSvgDesc ||
											__(
												'Lightweight inline SVG while the real image loads. Prevents layout shift.',
												'performance-optimisation'
											) }
										<br />
										<strong>
											{ wppoSettings?.translations
												?.placeholderDominantColorLabel ||
												__(
													'Dominant Color',
													'performance-optimisation'
												) }
											:
										</strong>{ ' ' }
										{ wppoSettings?.translations
											?.placeholderDominantColorDesc ||
											__(
												'Extracted during image conversion. Smooth background-color fade transition.',
												'performance-optimisation'
											) }
										<br />
										<strong>
											{ wppoSettings?.translations
												?.placeholderLqipLabel ||
												__(
													'LQIP',
													'performance-optimisation'
												) }
											:
										</strong>{ ' ' }
										{ wppoSettings?.translations
											?.placeholderLqipDesc ||
											__(
												'20×20 blurred preview. Images must be re-optimized for LQIP to take effect.',
												'performance-optimisation'
											) }
									</p>
								</div>
							</div>
						) }

						<SwitchField
							label={ __(
								'Wrap in Picture Tag',
								'performance-optimisation'
							) }
							description={ __(
								'Wrap <img> elements in a <picture> element to enable serving next-gen formats (WebP/AVIF) with a fallback for older browsers. Required for format conversion to work.',
								'performance-optimisation'
							) }
							name="wrapInPicture"
							checked={ settings.wrapInPicture }
							onChange={ handleChange( setSettings ) }
						/>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __( 'Video & Media', 'performance-optimisation' ) }
					icon={ <FontAwesomeIcon icon={ faMagic } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label={ __(
								'Video Lazy Loading',
								'performance-optimisation'
							) }
							description={ __(
								'Defer loading of <iframe> and <video> embeds until they enter the viewport. Significantly reduces initial page load time for pages with embedded YouTube, Vimeo, or other media.',
								'performance-optimisation'
							) }
							name="lazyLoadVideos"
							checked={ settings.lazyLoadVideos }
							onChange={ handleChange( setSettings ) }
						/>

						{ settings.lazyLoadVideos && (
							<div className="wppo-field-nest">
								<SwitchField
									label={
										wppoSettings?.translations
											?.videoPlaceholder ||
										__(
											'Video Placeholder',
											'performance-optimisation'
										)
									}
									description={
										wppoSettings?.translations
											?.videoPlaceholderDesc ||
										__(
											'Replace YouTube embeds with lightweight thumbnail previews. The actual video player loads only when the user clicks the play button, saving up to 800KB per embed.',
											'performance-optimisation'
										)
									}
									name="enableVideoPlaceholder"
									checked={ settings.enableVideoPlaceholder }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						) }

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
								aria-describedby="excludeVideos-desc"
							/>
							<p
								id="excludeVideos-desc"
								className="wppo-text-muted wppo-mt-10 wppo-text-small"
							>
								Enter CSS class names or partial URLs of embeds
								that should always load immediately.
							</p>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Next-Gen Conversion',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faMagic } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label={ __(
								'Auto Convert Formats',
								'performance-optimisation'
							) }
							description={ __(
								'Automatically convert uploaded JPEG/PNG images to modern formats (WebP or AVIF). Modern formats are 25–50 percent smaller than JPEG at the same quality, directly improving page speed scores.',
								'performance-optimisation'
							) }
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
										aria-describedby="excludeConvertImages-desc"
									/>
									<p
										id="excludeConvertImages-desc"
										className="wppo-text-muted wppo-mt-10 wppo-text-small"
									>
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
					title={ __(
						'Responsive Limits',
						'performance-optimisation'
					) }
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
								aria-describedby="maxWidthImgSize-desc"
							/>
							<p
								id="maxWidthImgSize-desc"
								className="wppo-text-muted wppo-mt-10 wppo-text-small"
							>
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
								placeholder="e.g. 300, 600, 1200"
								value={ settings.excludeSize }
								onChange={ handleChange( setSettings ) }
								aria-describedby="excludeSize-desc"
							/>
							<p
								id="excludeSize-desc"
								className="wppo-text-muted wppo-mt-10 wppo-text-small"
							>
								Comma-separated image width values (pixels).
								Images with these widths in srcset will be
								skipped.
							</p>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Advanced Preloading',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faCloudUploadAlt } /> }
				>
					<div className="wppo-stacked-cards">
						<div>
							<SwitchField
								label={ __(
									'Auto-preload LCP Image',
									'performance-optimisation'
								) }
								description={ __(
									'Automatically detect and preload the Largest Contentful Paint (LCP) image from PageSpeed scan data. Requires a configured PageSpeed API key. Falls back to featured image when no PageSpeed data is available.',
									'performance-optimisation'
								) }
								name="autoPreloadLCP"
								checked={ settings.autoPreloadLCP }
								onChange={ handleChange( setSettings ) }
							/>
						</div>
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
										aria-describedby="preloadFrontPageImagesUrls-desc"
									/>
									<p
										id="preloadFrontPageImagesUrls-desc"
										className="wppo-text-muted wppo-mt-10 wppo-text-small"
									>
										One URL per line. Only add
										above-the-fold images — preloading too
										many images can hurt performance.
									</p>
								</div>
							) }
						</div>
						<div>
							<SwitchField
								label={ __(
									'Preload Featured Images',
									'performance-optimisation'
								) }
								description={ __(
									'Automatically add preload hints for the featured image of posts and pages. Select which post types to apply this to below. Improves LCP for archive and single post pages.',
									'performance-optimisation'
								) }
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
															? 'wppo-post-type-chip--active'
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
