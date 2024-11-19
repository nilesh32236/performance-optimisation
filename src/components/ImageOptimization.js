import React, { useState } from 'react';
import { handleChange, handleSubmit } from '../lib/formUtils';

const ImageOptimization = ({ options }) => {
	const [settings, setSettings] = useState({
		lazyLoadImages: options?.lazyLoadImages || false,
		excludeFistImages: options?.excludeFistImages || 0,
		excludeImages: options?.excludeImages || '',
		// compressImages: options?.compressImages || false,
		excludeCompressedImages: options?.excludeCompressedImages || '',
		convertToWebP: options?.convertToWebP || false,
		excludeWebPImages: options?.excludeWebPImages || '',
		replacePlaceholderWithSVG: options?.replacePlaceholderWithSVG || false,
		preloadFrontPageImages: options?.preloadFrontPageImages || '',
		preloadFrontPageImagesUrls: options?.preloadFrontPageImagesUrls || '',
		preloadPostTypeImage: options?.preloadPostTypeImage || false,
		selectedPostType: options?.selectedPostType || [],
		availablePostTypes: options?.availablePostTypes,
		excludePostTypeImgUrl: options?.excludePostTypeImgUrl || '',
		maxWidthImgSize: options?.maxWidthImgSize || 0,
		excludeSize: options?.options || '',
	});

	console.log(settings.availablePostTypes);
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
			await handleSubmit(settings, 'image_optimisation');
		} catch (error) {
			console.error('Form submission error:', error);
		} finally {
			setIsLoading(false);
		}
	}

	return (
		<form onSubmit={onSubmit} className="settings-form">
			<h2>Image Optimization Settings</h2>

			{/* Compress Images */}
			{/* <div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="compressImages"
						checked={settings.compressImages}
						onChange={handleChange(setSettings)}
					/>
					Compress Images
				</label>
				<p className="option-description">
					Automatically compress images to reduce file size and improve page loading speed.
				</p>
				{settings.compressImages && (
					<textarea
						className="text-area-field"
						placeholder="Exclude specific images from compression"
						name="excludeCompressedImages"
						value={settings.excludeCompressedImages}
						onChange={handleChange(setSettings)}
					/>
				)}
			</div> */}

			{/* Lazy Load Images */}
			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="lazyLoadImages"
						checked={settings.lazyLoadImages}
						onChange={handleChange(setSettings)}
					/>
					Lazy Load Images
				</label>
				<p className="option-description">
					Enable lazy loading for images to improve the initial load speed by loading images only when they appear in the viewport.
				</p>
				{settings.lazyLoadImages && (
					<>
						<input
							className='input-field'
							placeholder='Enter number you want to exclude first'
							name='excludeFistImages'
							value={settings.excludeFistImages}
							onChange={handleChange(setSettings)}
						/>
						<textarea
							className="text-area-field"
							placeholder="Exclude specific image URLs"
							name="excludeImages"
							value={settings.excludeImages}
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
			</div>

			{/* Convert to WebP */}
			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="convertToWebP"
						checked={settings.convertToWebP}
						onChange={handleChange(setSettings)}
					/>
					Convert Images to WebP
				</label>
				<p className="option-description">
					Convert images to WebP format to reduce image size while maintaining quality.
				</p>
				{settings.convertToWebP && (
					<textarea
						className="text-area-field"
						placeholder="Exclude specific images from WebP conversion"
						name="excludeWebPImages"
						value={settings.excludeWebPImages}
						onChange={handleChange(setSettings)}
					/>
				)}
			</div>

			{/* Preload Front Page Images */}
			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="preloadFrontPageImages"
						checked={settings.preloadFrontPageImages}
						onChange={handleChange(setSettings)}
					/>
					Preload Images on Front Page
				</label>
				<p className="option-description">
					Preload critical images on the front page to enhance initial load performance.
				</p>
				{settings.preloadFrontPageImages && (
					<textarea
						className="text-area-field"
						placeholder="Enter img url (full/partial) to preload this img in front page."
						name="preloadFrontPageImagesUrls"
						value={settings.preloadFrontPageImagesUrls}
						onChange={handleChange(setSettings)}
					/>
				)}
			</div>

			{/* Preload Feature Images for Specific Post Types */}
			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="preloadPostTypeImage"
						checked={settings.preloadPostTypeImage}
						onChange={handleChange(setSettings)}
					/>
					Preload Feature Images for Post Types
				</label>
				<p className="option-description">
					Select post types where feature images should be preloaded for better performance.
				</p>
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
			</div>

			<button type="submit" className="submit-button" disabled={isLoading}>
				{isLoading ? 'Saving...' : 'Save Settings'}
			</button>
		</form>
	);
};

export default ImageOptimization;
