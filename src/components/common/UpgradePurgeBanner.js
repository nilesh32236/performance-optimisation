/**
 * Shared upgrade-purge banner (audit #1515).
 *
 * Single presentational home for the last-purge + safe-preview banner
 * previously copied in Dashboard.js and FileOptimization.js. A new field
 * (e.g. a lastPurge alias) fixed here reaches both tabs.
 *
 * @since NEXT
 */
import { __, sprintf } from '@wordpress/i18n';
import { isSafeHttpUrl } from '../../lib/urls';

/**
 * Upgrade purge status banner.
 *
 * @since NEXT
 * @param {Object} props              Component props.
 * @param {Object} props.upgradePurge Normalised { last_purge, safe_preview_url } slice.
 * @return {*} Banner element or null when there is nothing to show.
 */
const UpgradePurgeBanner = ( { upgradePurge } ) => {
	const lastPurgeReason =
		upgradePurge &&
		upgradePurge.last_purge &&
		upgradePurge.last_purge.reason
			? upgradePurge.last_purge.reason
			: '';
	const previewUrl =
		upgradePurge && upgradePurge.safe_preview_url
			? upgradePurge.safe_preview_url
			: '';
	const hasPreview = !! previewUrl && isSafeHttpUrl( previewUrl );

	if ( ! lastPurgeReason && ! hasPreview ) {
		return null;
	}

	return (
		<div
			className="wppo-notice wppo-notice--info wppo-mb-16"
			role="status"
			aria-live="polite"
		>
			<span>
				{ lastPurgeReason
					? sprintf(
							// translators: %s: last purge reason.
							__( 'Last purge: %s', 'performance-optimisation' ),
							lastPurgeReason
					  )
					: __(
							'Updates auto-purge derived caches',
							'performance-optimisation'
					  ) }
				{ ' — ' }
				{ hasPreview && (
					<a
						href={ previewUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __(
							'Open safe preview (bypasses minify)',
							'performance-optimisation'
						) }
					</a>
				) }
			</span>
		</div>
	);
};

export default UpgradePurgeBanner;
