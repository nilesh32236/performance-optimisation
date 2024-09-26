import React, { useState } from 'react';
import { handleChange, handleSubmit } from '../lib/formUtils';

const ImageOptimization = ({ options }) => {
	const [settings, setSettings] = useState({
		compressImages: options?.compressImages || false,
		excludeCompressedImages: options?.excludeCompressedImages || '',
		convertToWebP: options?.convertToWebP || false,
		excludeWebPImages: options?.excludeWebPImages || '',
	});

	const [isLoading, setIsLoading] = useState(false);
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
			<div className="checkbox-option">
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

			<button type="submit" className="submit-button" disabled={isLoading}>
				{isLoading ? 'Saving...' : 'Save Settings'}
			</button>
		</form>
	);
};

export default ImageOptimization;
