/**
 * RecentActivityCard component.
 *
 * Shows the 5 most recent optimization activities on the Dashboard.
 * The "View Full Log" button navigates to the Tools tab where the
 * complete paginated activity log lives.
 *
 * @since 1.5.0
 */

import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faHistory, faArrowRight } from '@fortawesome/free-solid-svg-icons';
import FeatureCard from './common/FeatureCard';

const RecentActivityCard = ( { activities, onNavigate } ) => {
	return (
		<FeatureCard
			title="Recent Optimization Activity"
			icon={ <FontAwesomeIcon icon={ faHistory } /> }
			footer={
				<button
					type="button"
					className="wppo-button wppo-button--secondary"
					onClick={ () => onNavigate( 'tools' ) }
				>
					View Full Log
					<FontAwesomeIcon icon={ faArrowRight } />
				</button>
			}
		>
			<p
				className="wppo-text-muted wppo-text-small"
				style={ { marginBottom: '16px' } }
			>
				The 5 most recent actions performed by the plugin. Open the
				Tools tab for the complete paginated log.
			</p>
			<div className="wppo-activity-wrapper">
				{ activities?.length ? (
					<ul className="wppo-activity-list">
						{ activities.slice( 0, 5 ).map( ( activity, index ) => (
							<li key={ index }>
								{ /* Activity text is sanitized server-side via wp_kses */ }
								{ /* eslint-disable-next-line react/no-danger */ }
								<div
									className="wppo-activity-text"
									dangerouslySetInnerHTML={ {
										__html: activity.activity,
									} }
								/>
							</li>
						) ) }
					</ul>
				) : (
					<div className="wppo-empty-state">
						No optimization activity recorded yet.
					</div>
				) }
			</div>
		</FeatureCard>
	);
};

export default RecentActivityCard;
