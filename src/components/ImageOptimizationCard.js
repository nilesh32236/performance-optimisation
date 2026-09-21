/**
 * ImageOptimizationCard component.
 *
 * @since 1.5.0
 */

import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faImages, faSpinner } from '@fortawesome/free-solid-svg-icons';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { formatBytes } from '../lib/util';
import { savingsPercent } from '../lib/format';
import { __, sprintf, _n } from '@wordpress/i18n';

/**
 * Shared conversion progress section (WebP/AVIF were ~30-line duplicates).
 *
 * @since NEXT
 * @param {Object} props       Props.
 * @param {string} props.id    Progress label id.
 * @param {string} props.title Section title.
 * @param {number} props.done  Completed count.
 * @param {number} props.total Total count.
 * @return {Element} Progress section.
 */
export const ConversionProgressSection = ( { id, title, done, total } ) => {
	const safeTotal = Number( total ) || 0;
	const safeDone = Number( done ) || 0;
	const percent = safeTotal > 0 ? ( safeDone / safeTotal ) * 100 : 0;
	return (
		<div className="wppo-progress-section">
			<div className="wppo-progress-header" id={ id }>
				<span>{ title }</span>
				<span>
					{ safeDone } / { safeTotal }
				</span>
			</div>
			<div
				className="wppo-progress-bar"
				role="progressbar"
				aria-labelledby={ id }
				aria-valuemin="0"
				aria-valuemax="100"
				aria-valuenow={ Math.round( percent ) }
			>
				{ /* dynamic progress via CSS var for consistency (intentional inline var) */ }
				<div
					className="wppo-progress-bar__fill"
					style={ { '--wppo-progress': `${ percent }%` } }
				></div>
			</div>
		</div>
	);
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
				<ConversionProgressSection
					id="wppo-webp-progress-label"
					title={ __(
						'WebP Conversion Progress',
						'performance-optimisation'
					) }
					done={ completed.webp || 0 }
					total={ totalWebP }
				/>

				<ConversionProgressSection
					id="wppo-avif-progress-label"
					title={ __(
						'AVIF Conversion Progress',
						'performance-optimisation'
					) }
					done={ completed.avif || 0 }
					total={ totalAvif }
				/>
			</div>

			{ ( failedWebP > 0 || failedAvif > 0 ) && (
				<div
					className="wppo-text-muted wppo-text-small wppo-mt-10"
					aria-live="polite"
				>
					{ sprintf(
						/* translators: %1$d: failed WebP count, %2$d: failed AVIF count. */
						__(
							'Failed conversions: WebP %1$d, AVIF %2$d (included in total)',
							'performance-optimisation'
						),
						failedWebP,
						failedAvif
					) }
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
							{ sprintf(
								/* translators: 1: original size, 2: optimised size, 3: percent saved, 4: image-count phrase. */
								__(
									'Original %1$s → Optimised %2$s (%3$d%% smaller · %4$s)',
									'performance-optimisation'
								),
								formatBytes( savings.original_bytes ),
								formatBytes( savings.converted_bytes ),
								Math.max(
									0,
									savingsPercent(
										savings.original_bytes,
										savings.converted_bytes
									) ?? 0
								),
								// Audit #1420: nested _n so '1 image' is correct.
								sprintf(
									/* translators: %d: image count. */
									_n(
										'%d image',
										'%d images',
										savings.images_counted,
										'performance-optimisation'
									),
									savings.images_counted
								)
							) }
						</span>
					</div>
				) }

			{ ( bgProcessing || bgJobsQueued > 0 ) && (
				<div
					className="wppo-notice wppo-notice--info wppo-mt-32"
					role="status"
					aria-live="polite"
				>
					<FontAwesomeIcon
						icon={ faSpinner }
						spin
						aria-hidden="true"
					/>
					<span>
						{ sprintf(
							/* translators: %d: queued job count. */
							_n(
								'Currently processing background optimisation jobs (%d queued)',
								'Currently processing background optimisation jobs (%d queued)',
								bgJobsQueued,
								'performance-optimisation'
							),
							bgJobsQueued
						) }
					</span>
				</div>
			) }
		</FeatureCard>
	);
};

export default ImageOptimizationCard;
