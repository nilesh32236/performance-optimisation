/**
 * RecentActivityCard component.
 *
 * Shows the 5 most recent optimization activities on the Dashboard.
 * The "View Full Log" button navigates to the Tools tab where the
 * complete paginated activity log lives.
 *
 * @since 1.5.0
 */

import { __ } from '@wordpress/i18n';
import { memo } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faHistory, faArrowRight } from '@fortawesome/free-solid-svg-icons';
import FeatureCard from './common/FeatureCard';
import NoticeBanner from './common/NoticeBanner';

const RecentActivityCard = ( {
	activities,
	activitiesError = false,
	loading = false,
	onNavigate = () => {},
} ) => {
	// Audit #1420: loading flag (undefined activities during fetch must not
	// flash the empty state); safe default navigation.
	const showList = Array.isArray( activities ) && activities.length > 0;
	const showEmptyState = ! showList && ! activitiesError && ! loading;
	return (
		<FeatureCard
			title={ __(
				'Recent Optimisation Activity',
				'performance-optimisation'
			) }
			icon={ <FontAwesomeIcon icon={ faHistory } aria-hidden="true" /> }
			footer={
				<button
					type="button"
					className="wppo-button wppo-button--secondary"
					onClick={ () => {
						if ( typeof onNavigate === 'function' ) {
							onNavigate( 'tools' );
						}
					} }
					aria-label={ __(
						'View Full Optimisation Activity Log',
						'performance-optimisation'
					) }
				>
					{ __( 'View Full Log', 'performance-optimisation' ) }
					<FontAwesomeIcon icon={ faArrowRight } aria-hidden="true" />
				</button>
			}
		>
			<p className="wppo-text-muted wppo-text-small wppo-mb-16">
				{ __(
					'The 5 most recent actions performed by the plugin. Open the Tools tab for the complete paginated log.',
					'performance-optimisation'
				) }
			</p>
			<div className="wppo-activity-wrapper">
				{ activitiesError && (
					<NoticeBanner
						type="error"
						message={ __(
							'Failed to load recent activity. Open the Tools tab for the full log.',
							'performance-optimisation'
						) }
					/>
				) }
				{ showList && (
					<ul className="wppo-activity-list">
						{ activities.slice( 0, 5 ).map( ( activity ) => (
							<li key={ activity.id }>
								<span className="wppo-activity-text">
									{ activity.activity }
								</span>
							</li>
						) ) }
					</ul>
				) }
				{ loading && ! showList && (
					<div
						className="wppo-empty-state"
						role="status"
						aria-live="polite"
					>
						{ __(
							'Loading activity…',
							'performance-optimisation'
						) }
					</div>
				) }
				{ showEmptyState && (
					<div className="wppo-empty-state">
						{ __(
							'No optimisation activity recorded yet.',
							'performance-optimisation'
						) }
					</div>
				) }
			</div>
		</FeatureCard>
	);
};

export default memo( RecentActivityCard );
