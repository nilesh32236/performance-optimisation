/**
 * StatusBadge component.
 *
 * Renders a colour-coded pill badge for a metric status value.
 * Supports 'good', 'needs_improvement', and 'poor' variants using
 * --wppo- CSS custom properties defined in the abstracts layer.
 *
 * @since 1.5.0
 */

import { __ } from '@wordpress/i18n';

// lib/status.js scoreToStatus() emits 'warning' for 50–89 and 'unknown' for
// non-numeric input; normalize here so both modules share one vocabulary
// (audit #1354: a score of 75 previously rendered the 'Unknown' badge).
const KNOWN_STATUSES = [ 'good', 'needs_improvement', 'poor' ];

const STATUS_ALIASES = {
	warning: 'needs_improvement',
};

const StatusBadge = ( { status } ) => {
	const normalized = STATUS_ALIASES[ status ] || status;
	const safeStatus = KNOWN_STATUSES.includes( normalized )
		? normalized
		: 'unknown';
	const labelMap = {
		good: __( 'Good', 'performance-optimisation' ),
		needs_improvement: __(
			'Needs Improvement',
			'performance-optimisation'
		),
		poor: __( 'Poor', 'performance-optimisation' ),
		unknown: __( 'Unknown', 'performance-optimisation' ),
	};

	const label = labelMap[ safeStatus ];

	return (
		<span
			className={ `wppo-status-badge wppo-status-badge--${ safeStatus }` }
		>
			{ label }
		</span>
	);
};

export default StatusBadge;
