import { useState, useId } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import CheckboxOption from './common/CheckboxOption';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faEye,
	faMagic,
	faCloudUploadAlt,
	faInfoCircle,
} from '@fortawesome/free-solid-svg-icons';

const ImageOptimization = ( { options = {} } ) => {
	const translations = wppoSettings.translations;

	const defaultSettings = {
		lazyLoadImages: false,
		excludeFistImages: 0,
		excludeImages: '',
		convertImg: false,
		conversionFormat: 'webp',
		excludeConvertImages: '',
		replacePlaceholderWithSVG: false,
		preloadFrontPageImages: '',
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
	const conversionFormatId = useId();
	const maxWidthImgSizeId = useId();
	const excludeSizeId = useId();
	const postTypeCheckboxPrefix = useId();
	const excludeFirstImagesId = useId();
	const excludePostTypeUrlsId = useId();

	const togglePostType = ( postType ) => {
		setSettings( ( prevSettings ) => ( {
			...prevSettings,
			selectedPostType: prevSettings.selectedPostType.includes( postType )
				? prevSettings.selectedPostType.filter(
						( type ) => type !== postType
				  )
				: [ ...prevSettings.selectedPostType, postType ],
		} ) );
	};

	const onSubmit = async ( e ) => {
		e.preventDefault();
		setIsLoading( true );
		try {
			await apiCall( 'update_settings', {
				tab: 'image_optimisation',
				settings,
			} );
		} catch ( error ) {
			console.error( translations.formSubmissionError, error );
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<form onSubmit={ onSubmit } className="settings-form fadeIn">
			<h2>{ translations.imgOptimizationsettings }</h2>

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={ faEye } /> Smart Loading
				</h3>
				<p>
					Manage how images are loaded on your site to prioritize
					critical content and reduce initial payload.
				</p>

				<CheckboxOption
					label={ translations.lazyLoadImages }
					checked={ settings.lazyLoadImages }
					onChange={ handleChange( setSettings ) }
					name="lazyLoadImages"
					textareaName="excludeImages"
					textareaPlaceholder={ translations.excludeImages }
					textareaValue={ settings.excludeImages }
					onTextareaChange={ handleChange( setSettings ) }
					description={ translations.lazyLoadImagesDesc }
				>
					{ settings.lazyLoadImages && (
						<>
							<div className="wppo-notice wppo-notice--info">
								<FontAwesomeIcon icon={ faInfoCircle } />
								<span>{ translations.lazyLoadInfo || 'Images above the fold (header, hero) should be excluded to avoid layout shifts. Use the settings below to fine-tune.' }</span>
							</div>
							<div
							style={ {
								display: 'flex',
								flexDirection: 'column',
								gap: '24px',
							} }
						>
							<div className="setting-group">
								<label
									className="field-label"
									htmlFor={ excludeFirstImagesId }
								>
									{ translations.excludeFistImages }
								</label>
								<input
									id={ excludeFirstImagesId }
									className="input-field"
									type="number"
									placeholder="e.g. 2"
									name="excludeFistImages"
									value={ settings.excludeFistImages }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
							<CheckboxOption
								label={ translations.replaceImgToSVG }
								checked={ settings.replacePlaceholderWithSVG }
								onChange={ handleChange( setSettings ) }
								name="replacePlaceholderWithSVG"
								description={ translations.replaceImgToSVGDesc }
							/>
						</div>
					</>
					) }
				</CheckboxOption>
			</div>

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={ faMagic } /> Next-Gen Formats
				</h3>
				<p>
					Automatically serve modern image formats like WebP or AVIF
					for superior compression without quality loss.
				</p>

				<CheckboxOption
					label={ translations.convertImg }
					checked={ settings.convertImg }
					onChange={ handleChange( setSettings ) }
					name="convertImg"
					textareaName="excludeConvertImages"
					textareaPlaceholder={ translations.excludeConvertImages }
					textareaValue={ settings.excludeConvertImages }
					onTextareaChange={ handleChange( setSettings ) }
					description={ translations.convertImgDesc }
				>
					{ settings.convertImg && (
						<>
							<div className="wppo-notice wppo-notice--info" style={ { marginBottom: '16px' } }>
								<FontAwesomeIcon icon={ faInfoCircle } />
								<span>{ translations.convertImgInfo || 'Converted images are served alongside originals. Browsers that don\'t support the format will fall back to the original automatically.' }</span>
							</div>
							<div
							className="setting-group"
							style={ { marginTop: '16px' } }
						>
							<label
								className="field-label"
								htmlFor={ conversionFormatId }
							>
								{ translations.conversationFormat }
							</label>
							<select
								id={ conversionFormatId }
								name="conversionFormat"
								value={ settings.conversionFormat }
								onChange={ handleChange( setSettings ) }
								className="input-field"
							>
								<option value="webp">
									{ translations.webp }
								</option>
								<option value="avif">
									{ translations.avif }
								</option>
								<option value="both">
									{ translations.both }
								</option>
							</select>
						</div>
					</>
					) }
				</CheckboxOption>
			</div>

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={ faCloudUploadAlt } /> Intelligent
					Preloading
				</h3>
				<p>
					Speed up the Largest Contentful Paint (LCP) by preloading
					critical images before regular assets.
				</p>

				<CheckboxOption
					label={ translations.preloadFrontPageImg }
					checked={ settings.preloadFrontPageImages }
					onChange={ handleChange( setSettings ) }
					name="preloadFrontPageImages"
					textareaName="preloadFrontPageImagesUrls"
					textareaPlaceholder={ translations.preloadFrontPageImgUrl }
					textareaValue={ settings.preloadFrontPageImagesUrls }
					onTextareaChange={ handleChange( setSettings ) }
					description={ translations.preloadFrontPageImgDesc }
				/>

				<div style={ { marginTop: '32px' } }>
					<CheckboxOption
						label={ translations.preloadPostTypeImg }
						checked={ settings.preloadPostTypeImage }
						onChange={ handleChange( setSettings ) }
						name="preloadPostTypeImage"
						description={ translations.preloadPostTypeImgDesc }
					>
						{ settings.preloadPostTypeImage && (
							<div
								style={ {
									display: 'flex',
									flexDirection: 'column',
									gap: '24px',
								} }
							>
								<div>
									<div
										className="field-label"
										style={ { marginBottom: '12px' } }
									>
										Target Post Types
									</div>
									<div className="post-types-grid">
										{ settings.availablePostTypes?.map(
											( postType ) => (
												<div
													key={ postType }
													className="post-type-checkbox"
												>
													<label
														htmlFor={ `${ postTypeCheckboxPrefix }-${ postType }` }
													>
														<input
															id={ `${ postTypeCheckboxPrefix }-${ postType }` }
															type="checkbox"
															checked={ settings.selectedPostType.includes(
																postType
															) }
															onChange={ () =>
																togglePostType(
																	postType
																)
															}
														/>
														{ postType
															.charAt( 0 )
															.toUpperCase() +
															postType.slice(
																1
															) }
													</label>
												</div>
											)
										) }
									</div>
								</div>

								<div className="setting-group">
									<label
										className="field-label"
										htmlFor={ excludePostTypeUrlsId }
									>
										Exclude Specific URLs
									</label>
									<textarea
										id={ excludePostTypeUrlsId }
										className="text-area-field"
										placeholder={
											translations.excludePostTypeImgUrl
										}
										name="excludePostTypeImgUrl"
										value={ settings.excludePostTypeImgUrl }
										onChange={ handleChange( setSettings ) }
									/>
								</div>

								<div
									style={ {
										display: 'grid',
										gridTemplateColumns: '1fr 1fr',
										gap: '20px',
									} }
								>
									<div className="setting-group">
										<label
											className="field-label"
											htmlFor={ maxWidthImgSizeId }
										>
											{ translations.maxWidthImgSize }
										</label>
										<input
											id={ maxWidthImgSizeId }
											className="input-field"
											type="number"
											name="maxWidthImgSize"
											value={ settings.maxWidthImgSize }
											onChange={ handleChange(
												setSettings
											) }
										/>
									</div>
									<div className="setting-group">
										<label
											className="field-label"
											htmlFor={ excludeSizeId }
										>
											{ translations.excludeSize }
										</label>
										<textarea
											id={ excludeSizeId }
											className="text-area-field"
											placeholder={
												translations.excludeSize
											}
											name="excludeSize"
											value={ settings.excludeSize }
											onChange={ handleChange(
												setSettings
											) }
										/>
									</div>
								</div>
							</div>
						) }
					</CheckboxOption>
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

export default ImageOptimization;
