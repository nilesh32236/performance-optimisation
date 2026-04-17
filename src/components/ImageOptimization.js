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

const ImageOptimization = ({ options = {} }) => {
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

	const [settings, setSettings] = useState(defaultSettings);
	const [isLoading, setIsLoading] = useState(false);
	const conversionFormatId = useId();
	const maxWidthImgSizeId = useId();
	const excludeSizeId = useId();
	const postTypeCheckboxPrefix = useId();
	const excludeFirstImagesId = useId();
	const excludePostTypeUrlsId = useId();

	const togglePostType = (postType) => {
		setSettings((prevSettings) => ({
			...prevSettings,
			selectedPostType: prevSettings.selectedPostType.includes(postType)
				? prevSettings.selectedPostType.filter(
					(type) => type !== postType
				)
				: [...prevSettings.selectedPostType, postType],
		}));
	};

	const onSubmit = async (e) => {
		e.preventDefault();
		setIsLoading(true);
		try {
			await apiCall('update_settings', {
				tab: 'image_optimisation',
				settings,
			});
			wppoSettings.notify(
				translations.formSubmitted || 'Settings saved successfully.',
				'success'
			);
		} catch (error) {
			console.error(translations.formSubmissionError, error);
			wppoSettings.notify(
				translations.formSubmissionError || 'Error saving settings.',
				'error'
			);
		} finally {
			setIsLoading(false);
		}
	};

	return (
		<form onSubmit={onSubmit} className="settings-form fadeIn">
			<h2>{translations.imgOptimizationsettings}</h2>

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={faEye} /> Smart Loading
				</h3>
				<p>
					Manage how images are loaded on your site to prioritize
					critical content and reduce initial payload.
				</p>

				<CheckboxOption
					label={translations.lazyLoadImages}
					checked={settings.lazyLoadImages}
					onChange={handleChange(setSettings)}
					name="lazyLoadImages"
					textareaName="excludeImages"
					textareaPlaceholder={translations.excludeImages}
					textareaValue={settings.excludeImages}
					onTextareaChange={handleChange(setSettings)}
					description={
						translations.lazyLoadImagesDesc ||
						'Delay loading of images until they scroll into view to reduce initial page weight.'
					}
				>
					{settings.lazyLoadImages && (
						<>
							<div className="wppo-notice wppo-notice--info">
								<FontAwesomeIcon icon={faInfoCircle} />
								<span>
									{translations.lazyLoadInfo ||
										'Images above the fold (header, hero) should be excluded to avoid layout shifts. Use the settings below to fine-tune.'}
								</span>
							</div>
							<div
								style={{
									display: 'flex',
									flexDirection: 'column',
									gap: '24px',
								}}
							>
								<div className="setting-group">
									<label
										className="field-label"
										htmlFor={excludeFirstImagesId}
									>
										{translations.excludeFirstImages}
									</label>
									<input
										id={excludeFirstImagesId}
										className="input-field"
										type="number"
										placeholder="e.g. 2"
										name="excludeFirstImages"
										value={settings.excludeFirstImages}
										onChange={handleChange(setSettings)}
									/>
								</div>
								<CheckboxOption
									label={translations.replaceImgToSVG}
									checked={
										settings.replacePlaceholderWithSVG
									}
									onChange={handleChange(setSettings)}
									name="replacePlaceholderWithSVG"
									description={
										translations.replaceImgToSVGDesc ||
										'Show a blurry lightweight SVG placeholder while the original image loads.'
									}
								/>
							</div>
						</>
					)}
				</CheckboxOption>

				<div style={{ marginTop: '24px' }}>
					<CheckboxOption
						label={translations.wrapInPicture}
						checked={settings.wrapInPicture}
						onChange={handleChange(setSettings)}
						name="wrapInPicture"
						description={
							translations.wrapInPictureDesc ||
							'Wrap images in a <picture> tag for advanced optimization.'
						}
					/>
				</div>
			</div>

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={faEye} /> Video Optimization
				</h3>
				<p>
					Manage how videos are loaded to improve performance and user
					experience.
				</p>

				<CheckboxOption
					label={translations.lazyLoadVideos}
					checked={settings.lazyLoadVideos}
					onChange={handleChange(setSettings)}
					name="lazyLoadVideos"
					textareaName="excludeVideos"
					textareaPlaceholder={translations.excludeVideos}
					textareaValue={settings.excludeVideos}
					onTextareaChange={handleChange(setSettings)}
					description={
						translations.lazyLoadVideosDesc ||
						'Delay loading of videos until they scroll into view.'
					}
				/>
			</div>

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={faMagic} /> Next-Gen Formats
				</h3>
				<p>
					Automatically convert your images to modern formats like
					WebP or AVIF for significantly smaller file sizes.
				</p>

				<CheckboxOption
					label={translations.convertImg}
					checked={settings.convertImg}
					onChange={handleChange(setSettings)}
					name="convertImg"
					textareaName="excludeConvertImages"
					textareaPlaceholder={translations.excludeConvertImages}
					textareaValue={settings.excludeConvertImages}
					onTextareaChange={handleChange(setSettings)}
					description={
						translations.convertImgDesc ||
						'Convert images to modern formats like WebP or AVIF.'
					}
				>
					{settings.convertImg && (
						<div className="setting-group">
							<label
								className="field-label"
								htmlFor={conversionFormatId}
							>
								{translations.conversationFormat}
							</label>
							<select
								id={conversionFormatId}
								className="input-field"
								name="conversionFormat"
								value={settings.conversionFormat}
								onChange={handleChange(setSettings)}
							>
								<option value="webp">
									{translations.webp}
								</option>
								<option value="avif">
									{translations.avif}
								</option>
								<option value="both">
									{translations.both}
								</option>
							</select>
						</div>
					)}
				</CheckboxOption>
			</div>

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={faCloudUploadAlt} /> Preloading
				</h3>
				<p>
					Preload critical assets to ensure they are available as soon
					as the browser needs them.
				</p>

				<div className="setting-group">
					<label className="field-label">
						{translations.preloadFrontPageImg}
					</label>
					<textarea
						className="input-field"
						name="preloadFrontPageImagesUrls"
						placeholder={translations.preloadFrontPageImgUrl}
						value={settings.preloadFrontPageImagesUrls}
						onChange={handleChange(setSettings)}
					/>
					<p className="field-description">
						{translations.preloadFrontPageImgDesc}
					</p>
				</div>

				<CheckboxOption
					label={translations.preloadPostTypeImg}
					checked={settings.preloadPostTypeImage}
					onChange={handleChange(setSettings)}
					name="preloadPostTypeImage"
					textareaName="excludePostTypeImgUrl"
					textareaPlaceholder={translations.excludePostTypeImgUrl}
					textareaValue={settings.excludePostTypeImgUrl}
					onTextareaChange={handleChange(setSettings)}
					description={
						translations.preloadPostTypeImgDesc ||
						'Automatically preload featured images for selected post types.'
					}
				>
					{settings.preloadPostTypeImage && (
						<div className="post-types-grid">
							{settings.availablePostTypes.map((type) => (
								<label
									key={type}
									className="checkbox-label"
								>
									<input
										type="checkbox"
										checked={settings.selectedPostType.includes(
											type
										)}
										onChange={() =>
											togglePostType(type)
										}
									/>
									{type}
								</label>
							))}
						</div>
					)}
				</CheckboxOption>
			</div>

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={faMagic} /> Responsive Images
				</h3>
				<p>
					Set maximum dimensions for images to ensure they are not
					larger than necessary.
				</p>

				<div className="setting-group">
					<label
						className="field-label"
						htmlFor={maxWidthImgSizeId}
					>
						Max Image Width (px)
					</label>
					<input
						id={maxWidthImgSizeId}
						className="input-field"
						type="number"
						name="maxWidthImgSize"
						value={settings.maxWidthImgSize}
						onChange={handleChange(setSettings)}
					/>
				</div>

				<div className="setting-group">
					<label
						className="field-label"
						htmlFor={excludeSizeId}
					>
						Exclude Classes from Max Width
					</label>
					<input
						id={excludeSizeId}
						className="input-field"
						type="text"
						placeholder="e.g. .no-resize, .hero-img"
						name="excludeSize"
						value={settings.excludeSize}
						onChange={handleChange(setSettings)}
					/>
				</div>
			</div>

			<div className="form-actions">
				<LoadingSubmitButton
					isLoading={isLoading}
					text={translations.saveSettings}
				/>
			</div>
		</form>
	);
};

export default ImageOptimization;
