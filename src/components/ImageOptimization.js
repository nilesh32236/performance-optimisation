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
						label={ __( 'Save Settings', 'performance-optimisation' ) }
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

			<div className="wppo-grid-2-col">
				<FeatureCard
					title="Lazy Loading"
					icon={ <FontAwesomeIcon icon={ faEye } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label="Enable Lazy Load"
							description="Delay loading of images until scroll."
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
								</div>
								<SwitchField
									label="SVG Placeholders"
									description="Lightweight blurred placeholders."
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
							description="Use modern picture elements."
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
							description="Delay iFrame and video tags."
							name="lazyLoadVideos"
							checked={ settings.lazyLoadVideos }
							onChange={ handleChange( setSettings ) }
						/>

						<div className="wppo-field">
							<label
								className="wppo-field-label"
								htmlFor="excludeVideos"
							>
								Exclude from Lazy Load
							</label>
							<textarea
								className="wppo-textarea"
								id="excludeVideos"
								name="excludeVideos"
								rows="3"
								placeholder="Class names or partial URLs"
								value={ settings.excludeVideos }
								onChange={ handleChange( setSettings ) }
							/>
						</div>
					</div>
				</FeatureCard>
			</div>

			<div className="wppo-grid-2-col">
				<FeatureCard
					title="Next-Gen Conversion"
					icon={ <FontAwesomeIcon icon={ faMagic } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label="Auto Convert Formats"
							description="Serve modern formats automatically."
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
											WebP (Standard)
										</option>
										<option value="avif">
											AVIF (Maximum Compression)
										</option>
										<option value="both">
											Both (Best Compatibility)
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
										placeholder="Partial URLs"
										value={ settings.excludeConvertImages }
										onChange={ handleChange( setSettings ) }
									/>
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
								placeholder="e.g. .no-resize, .hero"
								value={ settings.excludeSize }
								onChange={ handleChange( setSettings ) }
							/>
						</div>
					</div>
				</FeatureCard>
			</div>

			<FeatureCard
				title="Advanced Preloading"
				icon={ <FontAwesomeIcon icon={ faCloudUploadAlt } /> }
			>
				<div className="wppo-grid-2-col">
					<div>
						<label
							className="wppo-field-label"
							htmlFor="preloadFrontPageImagesUrls"
						>
							Preload Frontpage URLs
						</label>
						<textarea
							className="wppo-textarea"
							id="preloadFrontPageImagesUrls"
							name="preloadFrontPageImagesUrls"
							rows="4"
							placeholder="URLs (one per line)"
							value={ settings.preloadFrontPageImagesUrls }
							onChange={ handleChange( setSettings ) }
						/>
					</div>
					<div>
						<SwitchField
							label="Preload Featured Images"
							description="Automatic preload for selected types."
							name="preloadPostTypeImage"
							checked={ settings.preloadPostTypeImage }
							onChange={ handleChange( setSettings ) }
						/>
						{ settings.preloadPostTypeImage && (
							<>
								<div className="wppo-post-types-grid">
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
														togglePostType( type )
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
										value={ settings.excludePostTypeImgUrl }
										onChange={ handleChange( setSettings ) }
									/>
								</div>
							</>
						) }
					</div>
				</div>
			</FeatureCard>
		</div>
	);
};

export default ImageOptimization;
