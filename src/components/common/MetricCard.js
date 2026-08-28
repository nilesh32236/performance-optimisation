/**
 * MetricCard component.
 *
 * Displays a single performance metric with a label, value, optional unit,
 * and a StatusBadge. Used in the Performance Audit results grid.
 *
 * @since 1.5.0
 * @since NEXT Retained for backward compatibility — currently not rendered in the
 * tabbed Performance Audit view (which uses wppo-audit-overview-card inline)
 * but kept as a reusable primitive for future metric grids and tested coverage.
 * See AUDIT/AGENTS/agent-A07-css.md D-09 and AUDIT/DEAD-CODE.md X-12.
 */

import StatusBadge from './StatusBadge';

const MetricCard = ( { label, value, unit = '', status = null } ) => {
	return (
		<div className="wppo-metric-card">
			<span className="wppo-metric-card__label">{ label }</span>
			<span className="wppo-metric-card__value">
				{ value }
				{ unit !== null && unit !== undefined && unit !== '' && (
					<span className="wppo-metric-card__unit"> { unit }</span>
				) }
			</span>
			{ status && <StatusBadge status={ status } /> }
		</div>
	);
};

export default MetricCard;
