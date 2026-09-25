/**
 * ImageJobCard component.
 *
 * P3-020 Dashboard polling boundary: owns the image optimisation card
 * workflow (optimise/remove + `image_job_status` poll lifecycle) via
 * `useImageJobPoll`, rendering the existing presentational
 * `ImageOptimizationCard`. The Dashboard shell stays in sync through the
 * `onStatus` callback without owning any fetch or timer.
 *
 * @since NEXT
 * @param {Object}   props                  Component props.
 * @param {Object}   props.initialImageInfo Initial image info for card UI.
 * @param {Function} props.onStatus         Called with normalized image info on each committed update.
 * @return {Element} Card element.
 */
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useImageJobPoll } from '../../lib/useImageJobPoll';
import useNotice from '../../lib/useNotice';
import ImageOptimizationCard from '../ImageOptimizationCard';
import ConfirmDialog from '../common/ConfirmDialog';
import NoticeBanner from '../common/NoticeBanner';

const ImageJobCard = ( { initialImageInfo, onStatus } ) => {
	const { notice, notify, dismiss } = useNotice();
	const {
		bgProcessing,
		bgJobsQueued,
		imgSavings,
		imageInfo,
		optimizing,
		removing,
		optimizeImages,
		removeImages,
	} = useImageJobPoll( { initialImageInfo, onStatus, notify } );
	const [ confirmRemove, setConfirmRemove ] = useState( false );
	const { completed = {}, pending = {}, failed = {} } = imageInfo;

	const handleRemoveRequest = useCallback( () => {
		setConfirmRemove( true );
	}, [] );
	const handleRemoveConfirm = useCallback( () => {
		setConfirmRemove( false );
		removeImages();
	}, [ removeImages ] );
	const handleRemoveCancel = useCallback( () => {
		setConfirmRemove( false );
	}, [] );

	return (
		<>
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
			<ImageOptimizationCard
				completed={ completed }
				pending={ pending }
				failed={ failed }
				bgProcessing={ bgProcessing }
				bgJobsQueued={ bgJobsQueued }
				loading={ {
					optimize_images: optimizing,
					remove_images: removing,
				} }
				savings={ imgSavings }
				pendingPathsCount={
					( pending.webp || 0 ) + ( pending.avif || 0 )
				}
				onOptimize={ optimizeImages }
				onRemove={ handleRemoveRequest }
			/>
			<ConfirmDialog
				isOpen={ confirmRemove }
				onConfirm={ handleRemoveConfirm }
				onCancel={ handleRemoveCancel }
				title={ __(
					'Remove Optimized Images',
					'performance-optimisation'
				) }
				message={ __(
					'This will delete all optimized WebP and AVIF copies. Original images will not be affected.',
					'performance-optimisation'
				) }
				confirmLabel={ __( 'Delete', 'performance-optimisation' ) }
				variant="danger"
			/>
		</>
	);
};

export default ImageJobCard;
