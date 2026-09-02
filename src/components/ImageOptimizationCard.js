/**
 * ImageOptimizationCard component.
 *
 * @since 1.5.0
 */

import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faImages, faSpinner } from '@fortawesome/free-solid-svg-icons';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { __ } from '@wordpress/i18n';

/**
 * Format a byte count as a human-readable size string.
 *
 * @param {number} bytes Byte count.
 * @return {string} Formatted size (e.g. "1.5 MB").
 */
const formatBytes = ( bytes ) => {
	if ( ! bytes || bytes <= 0 ) {
		return '0 B';
	}
	const units = [ 'B', 'KB', 'MB', 'GB' ];
	const index = Math.min(
		Math.floor( Math.log( bytes ) / Math.log( 1024 ) ),
		units.length - 1
	);
	return `${ ( bytes / 1024 ** index ).toFixed( 1 ) } ${ units[ index ] }`;
};

const ImageOptimizationCard = ( {
	completed = {},
	pending = {},
	failed = {},
	bgProcessing = false,
	bgJobsQueued = 0,
	loading = {},
	pendingPathsCount = 0,
	savings = null,
	onOptimize,
	onRemove,
} ) => {
	const totalWebP =
		( completed.webp || 0 ) + ( pending.webp || 0 ) + ( failed.webp || 0 );
	const totalAvif =
		( completed.avif || 0 ) + ( pending.avif || 0 ) + ( failed.avif || 0 );
	const webpPercent =
		totalWebP > 0 ? ( ( completed.webp || 0 ) / totalWebP ) * 100 : 0;
	const avifPercent =
		totalAvif > 0 ? ( ( completed.avif || 0 ) / totalAvif ) * 100 : 0;
	const failedWebP = failed.webp || 0;
	const failedAvif = failed.avif || 0;

	return (
		<FeatureCard
			title={ __( 'Image Optimisation', 'performance-optimisation' ) }
			icon={ <FontAwesomeIcon icon={ faImages } /> }
			footer={
				<>
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						onClick={ onOptimize }
						isLoading={ loading.optimize_images }
						disabled={ bgProcessing || pendingPathsCount === 0 }
						label={ __(
							'Optimize All',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Optimizing…',
							'performance-optimisation'
						) }
					/>
					<LoadingSubmitButton
						className="wppo-button wppo-button--danger"
						onClick={ onRemove }
						isLoading={ loading.remove_images }
						disabled={ ! completed.webp && ! completed.avif }
						label={ __(
							'Remove Optimized',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Removing…',
							'performance-optimisation'
						) }
					/>
				</>
			}
		>
			<div className="wppo-progress-grid">
				<div className="wppo-progress-section">
					<div
						className="wppo-progress-header"
						id="wppo-webp-progress-label"
					>
						<span>
							{ __(
								'WebP Conversion Progress',
								'performance-optimisation'
							) }
						</span>
						<span>
							{ completed.webp || 0 } / { totalWebP }
						</span>
					</div>
					<div
						className="wppo-progress-bar"
						role="progressbar"
						aria-labelledby="wppo-webp-progress-label"
						aria-valuemin="0"
						aria-valuemax="100"
						aria-valuenow={ Math.round( webpPercent ) }
					>
						<div
							className="wppo-progress-bar__fill"
							// dynamic progress via CSS var for consistency (intentional inline var)
							style={ { '--wppo-progress': `${ webpPercent }%` } }
						></div>
					</div>
				</div>

				<div className="wppo-progress-section">
					<div
						className="wppo-progress-header"
						id="wppo-avif-progress-label"
					>
						<span>
							{ __(
								'AVIF Conversion Progress',
								'performance-optimisation'
							) }
						</span>
						<span>
							{ completed.avif || 0 } / { totalAvif }
						</span>
					</div>
					<div
						className="wppo-progress-bar"
						role="progressbar"
						aria-labelledby="wppo-avif-progress-label"
						aria-valuemin="0"
						aria-valuemax="100"
						aria-valuenow={ Math.round( avifPercent ) }
					>
						<div
							className="wppo-progress-bar__fill"
							// dynamic progress via CSS var for consistency (intentional inline var)
							style={ { '--wppo-progress': `${ avifPercent }%` } }
						></div>
					</div>
				</div>
			</div>

			{ ( failedWebP > 0 || failedAvif > 0 ) && (
				<div
					className="wppo-text-muted wppo-text-small wppo-mt-10"
					aria-live="polite"
				>
					{ __( 'Failed conversions:', 'performance-optimisation' ) }{ ' ' }
					WebP { failedWebP }, AVIF { failedAvif }{ ' ' }
					{ __( '(included in total)', 'performance-optimisation' ) }
				</div>
			) }

			{ savings &&
				savings.original_bytes > 0 &&
				savings.images_counted > 0 && (
					<div
						className="wppo-image-savings wppo-mt-16"
						aria-live="polite"
					>
						<span>
							{ __( 'Original', 'performance-optimisation' ) }{ ' ' }
							{ formatBytes( savings.original_bytes ) }{ ' ' }
							{ __( '→ Optimised', 'performance-optimisation' ) }{ ' ' }
							{ formatBytes( savings.converted_bytes ) } (
							{ Math.max(
								0,
								Math.round(
									( savings.saved_bytes /
										savings.original_bytes ) *
										100
								)
							) }
							% { __( 'smaller', 'performance-optimisation' ) } ·{ ' ' }
							{ savings.images_counted }{ ' ' }
							{ __( 'images', 'performance-optimisation' ) })
						</span>
					</div>
				) }

			{ ( bgProcessing || bgJobsQueued > 0 ) && (
				<div className="wppo-notice wppo-notice--info wppo-mt-32">
					<FontAwesomeIcon icon={ faSpinner } spin />
					<span>
						{ __(
							'Currently processing background optimisation jobs',
							'performance-optimisation'
						) }{ ' ' }
						( { bgJobsQueued }{ ' ' }
						{ __( 'queued', 'performance-optimisation' ) })
					</span>
				</div>
			) }
		</FeatureCard>
	);
};

export default ImageOptimizationCard;
