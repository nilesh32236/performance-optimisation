import React, { useState } from 'react';
import { CheckboxOption, handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';

const ImageOptimization = ({ options = {} }) => {
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
	}

	const [settings, setSettings] = useState(defaultSettings);
	const [isLoading, setIsLoading] = useState(false);

	const togglePostType = (postType) => {
		setSettings((prevSettings) => ({
			...prevSettings,
			selectedPostType: prevSettings.selectedPostType.includes(postType)
				? prevSettings.selectedPostType.filter((type) => type !== postType)
				: [...prevSettings.selectedPostType, postType],
		}));
	};

	const onSubmit = async (e) => {
		e.preventDefault();
		setIsLoading(true);

		try {
			await apiCall('update_settings', { tab: 'image_optimisation', settings });
		} catch (error) {
			console.error(translations.formSubmissionError, error);
		} finally {
			setIsLoading(false);
		}
	}

	return (
		<form onSubmit={onSubmit} className="settings-form">
			<h2>{translations.imgOptimizationsettings}</h2>

			{/* Lazy Load Images */}
			<CheckboxOption
				label={translations.lazyLoadImages}
				checked={settings.lazyLoadImages}
				onChange={handleChange(setSettings)}
				name='lazyLoadImages'
				textareaName='excludeImages'
				textareaPlaceholder={translations.excludeImages}
				textareaValue={settings.excludeImages}
				onTextareaChange={handleChange(setSettings)}
				description={translations.lazyLoadImagesDesc}
			>
				{settings.lazyLoadImages && (
					<>
						<input
							className='input-field'
							placeholder={translations.excludeFistImages}
							name='excludeFistImages'
							value={settings.excludeFistImages}
							onChange={handleChange(setSettings)}
						/>

						{/* Replace Low-Resolution Placeholder with SVG */}
						<div className="checkbox-option sub-fields">
							<label>
								<input
									type="checkbox"
									name="replacePlaceholderWithSVG"
									checked={settings.replacePlaceholderWithSVG}
									onChange={handleChange(setSettings)}
								/>
								{translations.replaceImgToSVG}
							</label>
							<p className="option-description">
								{translations.replaceImgToSVGDesc}
							</p>
						</div>
					</>
				)}
			</CheckboxOption>

			{/* Convert to WebP */}
			<CheckboxOption
				label={translations.convertImg}
				checked={settings.convertImg}
				onChange={handleChange(setSettings)}
				name='convertImg'
				textareaName='excludeConvertImages'
				textareaPlaceholder={translations.excludeConvertImages}
				textareaValue={settings.excludeConvertImages}
				onTextareaChange={handleChange(setSettings)}
				description={translations.convertImgDesc}
			>
				{settings.convertImg && (
					<div>
						<label className='sub-fields'>
							{translations.conversationFormat}
							<select
								name='conversionFormat'
								value={settings.conversionFormat}
								onChange={handleChange(setSettings)}
							>
								<option value='webp'>{translations.webp}</option>
								<option value='avif'>{translations.avif}</option>
								<option value='both'>{translations.both}</option>
							</select>
						</label>
					</div>
				)}
			</CheckboxOption>

			{/* Preload Front Page Images */}
			<CheckboxOption
				label={translations.preloadFrontPageImg}
				checked={settings.preloadFrontPageImages}
				onChange={handleChange(setSettings)}
				name='preloadFrontPageImages'
				textareaName='preloadFrontPageImagesUrls'
				textareaPlaceholder={translations.preloadFrontPageImgUrl}
				textareaValue={settings.preloadFrontPageImagesUrls}
				onTextareaChange={handleChange(setSettings)}
				description={translations.preloadFrontPageImgDesc}
			/>


			{/* Preload Feature Images for Specific Post Types */}
			<CheckboxOption
				label={translations.preloadPostTypeImg}
				checked={settings.preloadPostTypeImage}
				onChange={handleChange(setSettings)}
				name='preloadPostTypeImage'
				description={translations.preloadPostTypeImgDesc}
			>
				{settings.preloadPostTypeImage && (
					<div>
						{settings.availablePostTypes && settings.availablePostTypes.map((postType) => (
							<div key={postType} className="post-type-option">
								<label>
									<input
										type="checkbox"
										checked={settings.selectedPostType.includes(postType)}
										onChange={() => togglePostType(postType)}
									/>
									{postType.charAt(0).toUpperCase() + postType.slice(1)}
								</label>
							</div>
						))}
						<textarea
							className="text-area-field"
							placeholder={translations.excludePostTypeImgUrl}
							name="excludePostTypeImgUrl"
							value={settings.excludePostTypeImgUrl}
							onChange={handleChange(setSettings)}
						/>
						<span>
							<input
								className='input-field'
								type="number"
								name="maxWidthImgSize"
								value={settings.maxWidthImgSize}
								onChange={handleChange(setSettings)}
							/>
							<p className="option-description">
								{translations.maxWidthImgSize}
							</p>
						</span>

						<span>
							<textarea
								className='text-area-field'
								placeholder={translations.excludeSize}
								type="number"
								name="excludeSize"
								value={settings.excludeSize}
								onChange={handleChange(setSettings)}
							/>
						</span>
					</div>
				)}
			</CheckboxOption>

			<button type="submit" className="submit-button" disabled={isLoading}>
				{isLoading ? translations.saving : translations.saveSettings}
			</button>
		</form>
	);
};

export default ImageOptimization;
