import React, { useState, useCallback } from 'react';
import debounce from '../lib/Debonce';
import { handleChange, handleSubmit } from '../lib/formUtils';

const MediaOptimization = ({ options }) => {
	const [settings, setSettings] = useState({
		lazyLoadImages: options?.lazyLoadImages || false,
		excludeFistImages: options?.excludeFistImages || 0,
		excludeImages: options?.excludeImages || '',
		lazyLoadIframes: options?.lazyLoadIframes || false,
		excludeIframes: options?.excludeIframes || '',
	});

	const [isLoading, setIsLoading] = useState(false);

	const debouncedHandleSubmit = useCallback(
		debounce( async () => {
				setIsLoading( true );

				try {
					await handleSubmit( settings, 'media_optimisation' );
				} catch (error) {
					console.error( 'Form submission error:', error );
				} finally {
					setIsLoading( false );
				}
			}, 500 ),
		[settings]
	);

	const onSubmit = async (e) => {
		e.preventDefault();
		debouncedHandleSubmit();
	}

	return (
		<form onSubmit={onSubmit} className="settings-form">
			<h2>Media Optimization</h2>

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
					</>
				)}
			</div>

			{/* Lazy Load Iframes */}
			<div className="checkbox-option">
				<label>
					<input
						type="checkbox"
						name="lazyLoadIframes"
						checked={settings.lazyLoadIframes}
						onChange={handleChange(setSettings)}
					/>
					Lazy Load Iframes
				</label>
				<p className="option-description">
					Enable lazy loading for iframes (e.g., embedded videos or content) to load them only when they're visible on the screen.
				</p>
				{settings.lazyLoadIframes && (
					<textarea
						className="text-area-field"
						placeholder="Exclude specific iframe URLs"
						name="excludeIframes"
						value={settings.excludeIframes}
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

export default MediaOptimization;
