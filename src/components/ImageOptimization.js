import React, { useState } from 'react';
import { CheckboxOption, handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';

const ImageOptimization = ({ options = {} }) => {
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
			console.error('Form submission error:', error);
		} finally {
			setIsLoading(false);
		}
	}

	return (
		<form onSubmit={onSubmit} className="settings-form">
			<h2>Image Optimization Settings</h2>

			{/* Lazy Load Images */}
			<CheckboxOption
				label='Lazy Load Images'
				checked={settings.lazyLoadImages}
				onChange={handleChange(setSettings)}
				name='lazyLoadImages'
				textareaName='excludeImages'
				textareaPlaceholder='Exclude specific image URLs'
				textareaValue={settings.excludeImages}
				onTextareaChange={handleChange(setSettings)}
				description='Enable lazy loading for images to improve the initial load speed by loading images only when they appear in the viewport.'
			>
				{settings.lazyLoadImages && (
					<>
						<input
							className='input-field'
							placeholder='Enter number you want to exclude first'
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
								Replace Low-Resolution Placeholder with SVG
							</label>
							<p className="option-description">
								Use SVG placeholders for images that are being lazy-loaded to improve page rendering performance.
							</p>
						</div>
					</>
				)}
			</CheckboxOption>

			{/* Convert to WebP */}
			<CheckboxOption
				label='Enable Image Conversion'
				checked={settings.convertImg}
				onChange={handleChange(setSettings)}
				name='convertImg'
				textareaName='excludeConvertImages'
				textareaPlaceholder='Exclude specific images from conversion'
				textareaValue={settings.excludeConvertImages}
				onTextareaChange={handleChange(setSettings)}
				description='Convert images to WebP/AVIF format to reduce image size while maintaining quality.'
			>
				{settings.convertImg && (
					<div>
						<label className='sub-fields'>
							Conversion Format:
							<select
								name='conversionFormat'
								value={settings.conversionFormat}
								onChange={handleChange(setSettings)}
							>
								<option value='webp'>WebP</option>
								<option value='avif'>AVIF</option>
								<option value='both'>Both</option>
							</select>
						</label>
					</div>
				)}
			</CheckboxOption>

			{/* Preload Front Page Images */}
			<CheckboxOption
				label='Preload Images on Front Page'
				checked={settings.preloadFrontPageImages}
				onChange={handleChange(setSettings)}
				name='preloadFrontPageImages'
				textareaName='preloadFrontPageImagesUrls'
				textareaPlaceholder='Enter img url (full/partial) to preload this img in front page.'
				textareaValue={settings.preloadFrontPageImagesUrls}
				onTextareaChange={handleChange(setSettings)}
				description='Preload critical images on the front page to enhance initial load performance.'
			/>


			{/* Preload Feature Images for Specific Post Types */}
			<CheckboxOption
				label='Preload Feature Images for Post Types'
				checked={settings.preloadPostTypeImage}
				onChange={handleChange(setSettings)}
				name='preloadPostTypeImage'
				description='Select post types where feature images should be preloaded for better performance.'
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
							placeholder="Exclude specific img to preload."
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
								Set max width so it can't load bigger img than it. <code>0</code> default.
							</p>
						</span>

						<span>
							<textarea
								className='text-area-field'
								placeholder="Exclude specific size to preload."
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
				{isLoading ? 'Saving...' : 'Save Settings'}
			</button>
		</form>
	);
};

export default ImageOptimization;
