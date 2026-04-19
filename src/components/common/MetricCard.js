/**
 * MetricCard component.
 *
 * Displays a single performance metric with a label, value, optional unit,
 * and a StatusBadge. Used in the Performance Audit results grid.
 *
 * @since 1.5.0
 */

import StatusBadge from './StatusBadge';

const MetricCard = ( { label, value, unit = '', status = null } ) => {
	return (
		<div className="wppo-metric-card">
			<span className="wppo-metric-card__label">{ label }</span>
			<span className="wppo-metric-card__value">
				{ value }
				{ unit && (
					<span className="wppo-metric-card__unit"> { unit }</span>
				) }
			</span>
			{ status && <StatusBadge status={ status } /> }
		</div>
	);
};

export default MetricCard;
