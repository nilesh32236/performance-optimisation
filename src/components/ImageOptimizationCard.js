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

const ImageOptimizationCard = ( {
	completed = {},
	pending = {},
	bgProcessing = false,
	bgJobsQueued = 0,
	loading = {},
	pendingPathsCount = 0,
	onOptimize,
	onRemove,
} ) => {
	const totalWebP = ( completed.webp || 0 ) + ( pending.webp || 0 );
	const totalAvif = ( completed.avif || 0 ) + ( pending.avif || 0 );
	const webpPercent =
		totalWebP > 0 ? ( ( completed.webp || 0 ) / totalWebP ) * 100 : 0;
	const avifPercent =
		totalAvif > 0 ? ( ( completed.avif || 0 ) / totalAvif ) * 100 : 0;

	return (
		<FeatureCard
			title={ __( 'Image Optimization', 'performance-optimisation' ) }
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
							style={ { width: `${ webpPercent }%` } }
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
							style={ { width: `${ avifPercent }%` } }
						></div>
					</div>
				</div>
			</div>

			{ ( bgProcessing || bgJobsQueued > 0 ) && (
				<div
					className="wppo-notice wppo-notice--info"
					style={ { marginTop: '32px' } }
				>
					<FontAwesomeIcon icon={ faSpinner } spin />
					<span>
						{ __(
							'Currently processing background optimization jobs',
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
