/**
 * TaskMap component.
 *
 * Compact existing-control task map for Phase D discoverability: seven
 * common goals with one-button shortcuts to the existing tabs that own
 * them. Purely presentational — buttons call the existing onNavigate(tab)
 * callback only; no settings, REST, or optimization behavior changes.
 *
 * Mounted on the Dashboard directly after <WelcomePanel /> so it stays
 * visible to returning users (unlike the auto-dismissing welcome panel).
 *
 * @since NEXT
 */

import { __, sprintf } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCompass, faArrowRight } from '@fortawesome/free-solid-svg-icons';
import FeatureCard from './common/FeatureCard';
import { CORE_TASKS } from '../lib/taskMap';

/**
 * TaskMap card listing the core task shortcuts.
 *
 * @param {Object}   props
 * @param {Function} [props.onNavigate] Callback to switch the active WPPO tab.
 * @return {Element} The task map card.
 */
const TaskMap = ( { onNavigate } = {} ) => (
	<FeatureCard
		title={ __( 'Where to find things', 'performance-optimisation' ) }
		icon={ <FontAwesomeIcon icon={ faCompass } aria-hidden="true" /> }
	>
		<p className="wppo-text-muted wppo-text-small">
			{ __(
				'Shortcuts to the existing settings — nothing new, just the fastest path.',
				'performance-optimisation'
			) }
		</p>
		<ul className="wppo-taskmap">
			{ CORE_TASKS.map( ( entry ) => {
				const task = entry.getTask();
				const action = entry.getAction();
				return (
					<li key={ entry.key } className="wppo-taskmap__item">
						<div className="wppo-taskmap__text">
							<strong className="wppo-taskmap__task">
								{ task }
							</strong>
							<span className="wppo-taskmap__blurb">
								{ entry.getBlurb() }
							</span>
						</div>
						<button
							type="button"
							className="wppo-button wppo-button--secondary wppo-button--sm"
							onClick={ () => onNavigate?.( entry.tab ) }
							aria-label={ sprintf(
								/* translators: 1: destination label, 2: task description. */
								__( '%1$s: %2$s', 'performance-optimisation' ),
								action,
								task
							) }
						>
							{ action }
							{ ' →' }
							<FontAwesomeIcon
								icon={ faArrowRight }
								className="wppo-ml-6"
								aria-hidden="true"
							/>
						</button>
					</li>
				);
			} ) }
		</ul>
	</FeatureCard>
);

export default TaskMap;
