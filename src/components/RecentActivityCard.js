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

const RecentActivityCard = ( { activities, onNavigate } ) => {
	return (
		<FeatureCard
			title={ __(
				'Recent Optimization Activity',
				'performance-optimisation'
			) }
			icon={ <FontAwesomeIcon icon={ faHistory } /> }
			footer={
				<button
					type="button"
					className="wppo-button wppo-button--secondary"
					onClick={ () => onNavigate( 'tools' ) }
				>
					{ __( 'View Full Log', 'performance-optimisation' ) }
					<FontAwesomeIcon icon={ faArrowRight } />
				</button>
			}
		>
			<p
				className="wppo-text-muted wppo-text-small"
				style={ { marginBottom: '16px' } }
			>
				{ __(
					'The 5 most recent actions performed by the plugin. Open the Tools tab for the complete paginated log.',
					'performance-optimisation'
				) }
			</p>
			<div className="wppo-activity-wrapper">
				{ activities?.length ? (
					<ul className="wppo-activity-list">
						{ activities.slice( 0, 5 ).map( ( activity ) => (
							<li key={ activity.id }>
								<span className="wppo-activity-text">
									{ activity.activity }
								</span>
							</li>
						) ) }
					</ul>
				) : (
					<div className="wppo-empty-state">
						{ __(
							'No optimization activity recorded yet.',
							'performance-optimisation'
						) }
					</div>
				) }
			</div>
		</FeatureCard>
	);
};

export default memo( RecentActivityCard );
